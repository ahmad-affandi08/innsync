<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The routine duties of engineering (FR-MTC-011): a procedure (a list of steps to check, such as the generator, the pumps or the air conditioning) that falls due every day, on a day
 * of the week or on a day of the month, in the day shift, the night shift or either. A manager writes them, changes them (with the version of the screen) and retires them; a
 * duty is never deleted, and a change reaches the times it falls due from then on, the times already made keeping the steps they had.
 */
final readonly class DutyService
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const SHIFTS = ['any', 'day', 'night'];

    public const MAX_STEPS = 30;

    public function __construct(
        private DutyStore $duties,
        private AssetStore $assets,
        private MaintenanceAccess $access,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not manage the routine duties.');

        return array_map(fn (array $d): array => $this->shape($d), $this->duties->duties($property, false));
    }

    /** @return array<string, mixed> what the form of a duty offers */
    public function choices(PropertyId $property): array
    {
        return [
            'frequencies' => self::FREQUENCIES, 'shifts' => self::SHIFTS, 'categories' => WorkOrderService::CATEGORIES, 'max_steps' => self::MAX_STEPS,
            'assets' => array_values(array_map(static fn (array $a): array => ['id' => $a['id'], 'number' => $a['number'], 'name' => $a['name']], array_filter($this->assets->all($property), static fn (array $a): bool => $a['status'] === 'active'))),
        ];
    }

    /**
     * @param  list<string>  $steps
     * @return array<string, mixed>
     */
    public function create(PropertyId $property, string $actorId, string $title, string $frequency, string $shift, ?int $weekday, ?int $monthDay, ?string $assetId, ?string $area, string $category, array $steps): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not manage the routine duties.');
        $clean = $this->clean($property, $title, $frequency, $shift, $weekday, $monthDay, $assetId, $area, $category, $steps);
        $actor = strtolower($actorId);
        $id = $this->ids->next();
        $starts = $this->businessDate->current($property)->toString();

        $this->transactions->run(function () use ($property, $actor, $id, $clean, $starts): void {
            $this->duties->addDuty($property, [...$clean['fields'], 'id' => $id, 'starts_on' => $starts, 'created_by' => $actor], $clean['steps'], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'duty.created', 'duty', $id, null, [...$clean['fields'], 'steps' => count($clean['steps'])]));
        });

        return $this->shape($this->duties->duty($property, $id) ?? throw Refusal::notFound('Duty not found.'));
    }

    /**
     * @param  list<string>  $steps
     * @return array<string, mixed>
     */
    public function update(PropertyId $property, string $actorId, string $id, string $title, string $frequency, string $shift, ?int $weekday, ?int $monthDay, ?string $assetId, ?string $area, string $category, array $steps, int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not manage the routine duties.');
        $clean = $this->clean($property, $title, $frequency, $shift, $weekday, $monthDay, $assetId, $area, $category, $steps);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $clean, $lock): void {
            $before = $this->duties->duty($property, strtolower($id)) ?? throw Refusal::notFound('Duty not found.');

            if (! (bool) $before['is_active']) {
                throw Refusal::stateConflict('This duty is retired. Bring it back before changing it.');
            }

            if (! $this->duties->updateDuty($property, $before['id'], $lock, $clean['fields'], $clean['steps'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This duty changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'duty.changed', 'duty', $before['id'], ['title' => $before['title'], 'frequency' => $before['frequency'], 'shift' => $before['shift'], 'steps' => count($before['steps'])], [...$clean['fields'], 'steps' => count($clean['steps'])]));
        });

        return $this->shape($this->duties->duty($property, strtolower($id)) ?? throw Refusal::notFound('Duty not found.'));
    }

    /** @return array<string, mixed> */
    public function setActive(PropertyId $property, string $actorId, string $id, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not manage the routine duties.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $active, $lock): void {
            $before = $this->duties->duty($property, strtolower($id)) ?? throw Refusal::notFound('Duty not found.');

            if ((bool) $before['is_active'] === $active) {
                throw Refusal::stateConflict($active ? 'This duty is in use already.' : 'This duty is retired already.');
            }

            // A duty brought back falls due from today, not for the days it was retired.
            $fields = ['is_active' => $active] + ($active ? ['starts_on' => $this->businessDate->current($property)->toString()] : []);

            if (! $this->duties->updateDuty($property, $before['id'], $lock, $fields, null, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This duty changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $active ? 'duty.resumed' : 'duty.retired', 'duty', $before['id'], ['is_active' => (bool) $before['is_active']], ['is_active' => $active, 'title' => $before['title']]));
        });

        return $this->shape($this->duties->duty($property, strtolower($id)) ?? throw Refusal::notFound('Duty not found.'));
    }

    /**
     * @param  list<string>  $steps
     * @return array{fields: array<string, mixed>, steps: list<string>}
     */
    private function clean(PropertyId $property, string $title, string $frequency, string $shift, ?int $weekday, ?int $monthDay, ?string $assetId, ?string $area, string $category, array $steps): array
    {
        $title = trim($title);
        $area = $area === null || trim($area) === '' ? null : trim($area);

        if ($title === '' || mb_strlen($title) > 80) {
            throw Refusal::invalid('Name the duty in at most 80 characters.', ['title']);
        }

        if (! in_array($frequency, self::FREQUENCIES, true)) {
            throw Refusal::invalid('Choose how often it falls due.', ['frequency']);
        }

        if (! in_array($shift, self::SHIFTS, true)) {
            throw Refusal::invalid('Choose the shift.', ['shift']);
        }

        if ($frequency === 'weekly' && ($weekday === null || $weekday < 1 || $weekday > 7)) {
            throw Refusal::invalid('Choose the day of the week.', ['weekday']);
        }

        if ($frequency === 'monthly' && ($monthDay === null || $monthDay < 1 || $monthDay > 28)) {
            throw Refusal::invalid('Choose the day of the month, from 1 to 28.', ['month_day']);
        }

        if (! in_array($category, WorkOrderService::CATEGORIES, true)) {
            throw Refusal::invalid('Choose the kind of work.', ['category']);
        }

        if ($area !== null && mb_strlen($area) > 80) {
            throw Refusal::invalid('The place is at most 80 characters.', ['area']);
        }

        $asset = null;

        if ($assetId !== null && $assetId !== '') {
            $asset = $this->assets->find($property, strtolower($assetId));

            if ($asset === null || $asset['status'] !== 'active') {
                throw Refusal::invalid('Choose an asset that is in use.', ['asset_id']);
            }
        }

        if ($asset === null && $area === null) {
            throw Refusal::invalid('Choose the asset it is about or name the place.', ['asset_id', 'area']);
        }

        $clean = [];

        foreach ($steps as $text) {
            $text = trim((string) $text);

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > 200) {
                throw Refusal::invalid('A step is at most 200 characters.', ['steps']);
            }

            $clean[] = $text;
        }

        if ($clean === [] || count($clean) > self::MAX_STEPS) {
            throw Refusal::invalid('A duty has one to '.self::MAX_STEPS.' steps.', ['steps']);
        }

        return [
            'fields' => ['title' => $title, 'frequency' => $frequency, 'shift' => $shift, 'weekday' => $frequency === 'weekly' ? $weekday : null, 'month_day' => $frequency === 'monthly' ? $monthDay : null, 'asset_id' => $asset['id'] ?? null, 'area' => $area, 'category' => $category],
            'steps' => $clean,
        ];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function shape(array $d): array
    {
        return [
            'id' => $d['id'], 'title' => $d['title'], 'frequency' => $d['frequency'], 'shift' => $d['shift'], 'weekday' => $d['weekday'] === null ? null : (int) $d['weekday'], 'month_day' => $d['month_day'] === null ? null : (int) $d['month_day'],
            'asset_id' => $d['asset_id'], 'asset' => $d['asset_id'] === null ? null : ['number' => $d['asset_number'], 'name' => $d['asset_name']], 'area' => $d['area'], 'category' => $d['category'], 'starts_on' => substr((string) $d['starts_on'], 0, 10),
            'active' => (bool) $d['is_active'], 'steps' => $d['steps'], 'lock_version' => (int) $d['lock_version'],
        ];
    }
}
