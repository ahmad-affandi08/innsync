<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Application;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\BusinessDate;
use InvalidArgumentException;

/**
 * Housekeeping checklists (FR-HK-005): daily, weekly and monthly lists written by management as templates, done per room (every
 * active room) or per public area (the areas named in the template). A change is a new version. Each room or area has one run of a
 * checklist per period, begun by the first person who ticks an item, with the items as they were at that moment; a ticked item is a
 * fact (who, when, a note) that cannot be undone. Every ticked item is announced, with the share done, for Human Resource.
 */
final readonly class ChecklistService
{
    public const MANAGE_PERMISSION = 'housekeeping.checklist.manage';

    public const PERFORM_PERMISSION = 'housekeeping.checklist.perform';

    public const VIEW_PERMISSION = 'housekeeping.checklist.view';

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const SCOPES = ['room', 'area'];

    public function __construct(
        private ChecklistRepository $checklists,
        private RoomCatalogReader $rooms,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * Writes a checklist: the first version or a new version of an existing name (which becomes the one in force).
     *
     * @param  list<string>  $items
     * @param  list<string>  $areas  the public areas, for the area scope
     * @return array<string, mixed>
     */
    public function define(PropertyId $property, string $actorId, string $name, string $frequency, string $scope, array $areas, array $items, bool $active): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $name = trim($name);

        if (mb_strlen($name) < 3 || mb_strlen($name) > 80 || ! in_array($frequency, self::FREQUENCIES, true) || ! in_array($scope, self::SCOPES, true)) {
            throw Refusal::invalid('Name the checklist in 3 to 80 characters and choose daily, weekly or monthly, for rooms or for areas.', ['name', 'frequency', 'scope']);
        }

        $items = array_values(array_filter(array_map(static fn (string $i): string => trim($i), $items), static fn (string $i): bool => $i !== ''));

        if ($items === [] || count($items) > 40 || array_filter($items, static fn (string $i): bool => mb_strlen($i) > 160) !== []) {
            throw Refusal::invalid('A checklist has 1 to 40 items of at most 160 characters.', ['items']);
        }

        $areas = array_values(array_unique(array_filter(array_map(static fn (string $a): string => trim($a), $areas), static fn (string $a): bool => $a !== '')));

        if ($scope === 'room' ? $areas !== [] : ($areas === [] || count($areas) > 30 || array_filter($areas, static fn (string $a): bool => mb_strlen($a) > 60) !== [])) {
            throw Refusal::invalid('A checklist for areas names 1 to 30 areas of at most 60 characters; one for rooms names none.', ['areas']);
        }

        $actor = strtolower($actorId);
        $structured = [];

        foreach ($items as $i => $text) {
            $structured[] = ['id' => 'i'.($i + 1), 'text' => $text];
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $name, $frequency, $scope, $areas, $structured, $active, $id): void {
            $previous = $this->checklists->latestByName($property, $name);

            if ($previous !== null && ($previous['frequency'] !== $frequency || $previous['scope'] !== $scope)) {
                throw Refusal::invalid('A checklist keeps its frequency and whether it is for rooms or areas: write a new one with another name.', ['frequency', 'scope']);
            }

            $version = ($previous['version'] ?? 0) + 1;
            $this->checklists->addTemplate($property, $id, $name, $version, $frequency, $scope, $areas, $structured, $active, $actor, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'hk.checklist.defined', 'hk_checklist_template', $id, $previous === null ? null : ['version' => $previous['version'], 'items' => count($previous['items']), 'active' => $previous['is_active']], ['name' => $name, 'version' => $version, 'frequency' => $frequency, 'scope' => $scope, 'items' => count($structured), 'areas' => count($areas), 'active' => $active]));
        });

        return $this->checklists->template($property, $id) ?? throw Refusal::notFound('Checklist not found.');
    }

    /** @return array{templates: list<array<string, mixed>>, frequencies: list<string>, scopes: list<string>} */
    public function templates(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);

        return ['templates' => $this->checklists->latestTemplates($property), 'frequencies' => self::FREQUENCIES, 'scopes' => self::SCOPES];
    }

    /**
     * What is to be done now: every active checklist for its period, with how far each room or area has come.
     *
     * @return array{business_date: string, checklists: list<array<string, mixed>>, may_perform: bool}
     */
    public function board(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, [self::PERFORM_PERMISSION, self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
        $today = $this->businessDate->current($property);
        $lists = [];

        foreach ($this->checklists->latestTemplates($property) as $template) {
            if (! $template['is_active']) {
                continue;
            }

            $period = self::period($template['frequency'], $today);
            $progress = $this->checklists->progress($property, $template['name'], $period['key']);
            $total = count($template['items']);
            $targets = [];

            foreach ($this->targets($property, $template) as $ref => $label) {
                $done = $progress[$ref]['completed'] ?? 0;
                $targets[] = ['ref' => $ref, 'label' => $label, 'completed' => $done, 'total' => $progress[$ref]['total'] ?? $total, 'percent' => intdiv($done * 100, $progress[$ref]['total'] ?? $total)];
            }

            $lists[] = [
                'template_id' => $template['id'], 'name' => $template['name'], 'frequency' => $template['frequency'], 'scope' => $template['scope'], 'period_key' => $period['key'], 'period_start' => $period['start'], 'period_end' => $period['end'],
                'targets' => $targets,
            ];
        }

        return ['business_date' => $today->toString(), 'checklists' => $lists, 'may_perform' => $this->permissions->allowsInProperty($actorId, self::PERFORM_PERMISSION, $property)];
    }

    /**
     * The items of one checklist for one room or area in the current period, with what is ticked and who ticked it.
     *
     * @return array<string, mixed>
     */
    public function detail(PropertyId $property, string $actorId, string $templateId, string $target): array
    {
        $this->authorize($property, $actorId, [self::PERFORM_PERMISSION, self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
        $template = $this->checklists->template($property, strtolower($templateId)) ?? throw Refusal::notFound('Checklist not found.');
        $label = $this->targets($property, $template)[$template['scope'] === 'room' ? strtolower($target) : $target] ?? throw Refusal::notFound('Room or area not found.');
        $ref = $template['scope'] === 'room' ? strtolower($target) : $target;
        $period = self::period($template['frequency'], $this->businessDate->current($property));
        $run = $this->checklists->findRun($property, $template['name'], $period['key'], $ref);
        $items = $run['items'] ?? $template['items'];
        $done = $run === null ? [] : array_column($this->checklists->completions($property, $run['id']), null, 'item_id');
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($done, 'completed_by'))));

        return [
            'template_id' => $template['id'], 'name' => $template['name'], 'target' => $ref, 'label' => $label, 'period_key' => $period['key'],
            'items' => array_map(static fn (array $i): array => [
                'id' => $i['id'], 'text' => $i['text'], 'done' => isset($done[$i['id']]), 'note' => $done[$i['id']]['note'] ?? null,
                'by' => isset($done[$i['id']]) ? ($names[$done[$i['id']]['completed_by']] ?? null) : null, 'at' => $done[$i['id']]['completed_at'] ?? null,
            ], $items),
            'completed' => count($done), 'total' => count($items), 'percent' => intdiv(count($done) * 100, count($items)),
        ];
    }

    /**
     * Ticks one item for a room or area in the current period of the checklist in force. It begins the run if nobody has.
     *
     * @return array<string, mixed> the checklist for that room or area, as `detail` shows it
     */
    public function complete(PropertyId $property, string $actorId, string $templateId, string $target, string $itemId, ?string $note): array
    {
        $this->authorize($property, $actorId, [self::PERFORM_PERMISSION]);
        $actor = strtolower($actorId);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 300) {
            throw Refusal::invalid('A note is at most 300 characters.', ['note']);
        }

        $this->transactions->run(function () use ($property, $actor, $templateId, $target, $itemId, $note): void {
            $template = $this->checklists->template($property, strtolower($templateId)) ?? throw Refusal::notFound('Checklist not found.');
            $latest = $this->checklists->latestByName($property, $template['name']);

            if ($latest === null || $latest['id'] !== $template['id'] || ! $template['is_active']) {
                throw Refusal::stateConflict('This checklist was changed or retired. Refresh to see the one in force.');
            }

            $ref = $template['scope'] === 'room' ? strtolower($target) : $target;
            $label = $this->targets($property, $template)[$ref] ?? throw Refusal::notFound('Room or area not found.');
            $today = $this->businessDate->current($property);
            $period = self::period($template['frequency'], $today);
            $now = $this->clock->nowUtc();
            $run = $this->checklists->ensureRun($property, $this->ids->next(), $template['id'], $template['name'], $period['key'], $period['start'], $period['end'], $ref, $label, $template['items'], $now);

            if (! in_array($itemId, array_column($run['items'], 'id'), true)) {
                throw Refusal::invalid('This item is not on the checklist.', ['item_id']);
            }

            if ($this->checklists->complete($property, $this->ids->next(), $run['id'], $itemId, $note, $actor, $now, $today->toString()) === 'already') {
                throw Refusal::stateConflict('This item was already ticked.');
            }

            $done = count($this->checklists->completions($property, $run['id']));
            $total = count($run['items']);
            $percent = intdiv($done * 100, $total);

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'hk.checklist.item_completed', 'hk_checklist_run', $run['id'], null, ['checklist' => $template['name'], 'period' => $period['key'], 'target' => $label, 'item' => $itemId, 'percent' => $percent]));
            $this->outbox->publish(new OutboxEvent($property, 'housekeeping.checklist.item_completed', $run['id'], 1, [
                'run_id' => $run['id'], 'checklist' => $template['name'], 'frequency' => $template['frequency'], 'period' => $period['key'], 'target' => $label, 'item_id' => $itemId, 'completed_by' => $actor, 'completed' => $done, 'total' => $total, 'percent' => $percent,
            ]));

            if ($done === $total) {
                $this->outbox->publish(new OutboxEvent($property, 'housekeeping.checklist.run_completed', $run['id'], 1, ['run_id' => $run['id'], 'checklist' => $template['name'], 'frequency' => $template['frequency'], 'period' => $period['key'], 'target' => $label, 'total' => $total, 'completed_by' => $actor]));
            }
        });

        return $this->detail($property, $actorId, $templateId, $target);
    }

    /**
     * How much of each checklist was done in the dates, per room or area and per person.
     *
     * @return array{from: string, to: string, runs: list<array<string, mixed>>, people: list<array<string, mixed>>, percent: int}
     */
    public function performance(PropertyId $property, string $actorId, ?string $from, ?string $to): array
    {
        $this->authorize($property, $actorId, [self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
        $today = $this->businessDate->current($property);

        try {
            $start = $from === null || $from === '' ? $today->addDays(-29) : BusinessDate::fromString($from);
            $end = $to === null || $to === '' ? $today : BusinessDate::fromString($to);
        } catch (InvalidArgumentException $e) {
            throw Refusal::invalid($e->getMessage(), ['from', 'to']);
        }

        if ($end->isBefore($start) || $start->daysUntil($end) > 92) {
            throw Refusal::invalid('Choose a range of at most 93 days that ends after it starts.', ['from', 'to']);
        }

        $runs = $this->checklists->performance($property, $start->toString(), $end->toString());
        $people = [];

        foreach ($runs as $r) {
            foreach ($r['by'] as $user => $count) {
                $people[$user] = ($people[$user] ?? 0) + $count;
            }
        }

        $names = $this->staff->namesOf($property, array_keys($people));
        arsort($people);
        $items = array_sum(array_column($runs, 'items'));

        return [
            'from' => $start->toString(), 'to' => $end->toString(),
            'percent' => $items === 0 ? 0 : intdiv(array_sum(array_column($runs, 'completed')) * 100, $items),
            'runs' => array_map(static fn (array $r): array => ['run_id' => $r['run_id'], 'template' => $r['template'], 'frequency' => $r['frequency'], 'period_key' => $r['period_key'], 'target' => $r['target'], 'items' => $r['items'], 'completed' => $r['completed'], 'percent' => $r['items'] === 0 ? 0 : intdiv($r['completed'] * 100, $r['items'])], $runs),
            'people' => array_map(static fn (string $id, int $n): array => ['user_id' => $id, 'name' => $names[$id] ?? null, 'items' => $n], array_keys($people), array_values($people)),
        ];
    }

    /** @return array{key: string, start: string, end: string} */
    public static function period(string $frequency, BusinessDate $date): array
    {
        $d = new \DateTimeImmutable($date->toString(), new \DateTimeZone('UTC'));

        return match ($frequency) {
            'daily' => ['key' => $d->format('Y-m-d'), 'start' => $d->format('Y-m-d'), 'end' => $d->format('Y-m-d')],
            'weekly' => (static function () use ($d): array {
                $monday = $d->setISODate((int) $d->format('o'), (int) $d->format('W'), 1);

                return ['key' => $d->format('o').'-W'.$d->format('W'), 'start' => $monday->format('Y-m-d'), 'end' => $monday->modify('+6 days')->format('Y-m-d')];
            })(),
            default => ['key' => $d->format('Y-m'), 'start' => $d->format('Y-m-01'), 'end' => $d->format('Y-m-t')],
        };
    }

    /**
     * The rooms (every active room) or the areas the checklist is done for.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, string> label by reference
     */
    private function targets(PropertyId $property, array $template): array
    {
        if ($template['scope'] === 'area') {
            return array_combine($template['areas'], $template['areas']);
        }

        $rooms = [];

        foreach ($this->rooms->activeRooms($property) as $room) {
            if ($room->isActive) {
                $rooms[$room->id] = $room->number;
            }
        }

        return $rooms;
    }

    /** @param list<string> $permissions any of these */
    private function authorize(PropertyId $property, string $actorId, array $permissions): void
    {
        $this->assertProperty($property);

        foreach ($permissions as $permission) {
            if ($this->permissions->allowsInProperty($actorId, $permission, $property)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not use the housekeeping checklists.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
