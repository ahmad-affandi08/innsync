<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Routine;

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
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Daily, weekly and monthly checklists for the front desk (FR-FO-032, FR-FO-033). Management writes a template; a change is a new
 * version and the old ones stay on record. The period (a business date, an ISO week or a month) has one run of each active
 * checklist, started by the first person who ticks an item, with the items as they were at that moment. A ticked item is a fact
 * (who, when, an optional note) that cannot be undone, and each one is announced for the Human Resource module with the share of
 * the checklist done so far, which is what the PRD calls the completion percentage as a performance component.
 */
final readonly class SopService
{
    public const MANAGE_PERMISSION = 'front-office.sop.manage';

    public const PERFORM_PERMISSION = 'front-office.sop.perform';

    public const VIEW_PERMISSION = 'front-office.sop.view';

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public function __construct(
        private RoutineRepository $routine,
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
     * Writes a checklist: the first version, or a new version of an existing name (which becomes the one in force).
     *
     * @param  list<string>  $items
     * @return array<string, mixed>
     */
    public function define(PropertyId $property, string $actorId, string $name, string $frequency, array $items, bool $active): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);
        $name = trim($name);

        if (mb_strlen($name) < 3 || mb_strlen($name) > 80 || ! in_array($frequency, self::FREQUENCIES, true)) {
            throw Refusal::invalid('Name the checklist in 3 to 80 characters and choose daily, weekly or monthly.', ['name', 'frequency']);
        }

        $items = array_values(array_filter(array_map(static fn (string $i): string => trim($i), $items), static fn (string $i): bool => $i !== ''));

        if ($items === [] || count($items) > 40 || array_filter($items, static fn (string $i): bool => mb_strlen($i) > 160) !== []) {
            throw Refusal::invalid('A checklist has 1 to 40 items of at most 160 characters.', ['items']);
        }

        $actor = strtolower($actorId);
        $structured = [];

        foreach ($items as $i => $text) {
            $structured[] = ['id' => 'i'.($i + 1), 'text' => $text];
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $name, $frequency, $structured, $active, $id): void {
            $previous = $this->routine->latestByName($property, $name);

            if ($previous !== null && $previous['frequency'] !== $frequency) {
                throw Refusal::invalid('A checklist keeps its frequency: write a new one with another name.', ['frequency']);
            }

            $version = ($previous['version'] ?? 0) + 1;
            $this->routine->addTemplate($property, $id, $name, $version, $frequency, $structured, $active, $actor, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'sop.template.defined', 'sop_template', $id, $previous === null ? null : ['version' => $previous['version'], 'items' => count($previous['items']), 'active' => $previous['is_active']], ['name' => $name, 'version' => $version, 'frequency' => $frequency, 'items' => count($structured), 'active' => $active]));
        });

        return $this->routine->template($property, $id) ?? throw Refusal::notFound('Checklist not found.');
    }

    /** @return array{templates: list<array<string, mixed>>, frequencies: list<string>} */
    public function templates(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, [self::MANAGE_PERMISSION]);

        return ['templates' => $this->routine->latestTemplates($property), 'frequencies' => self::FREQUENCIES];
    }

    /**
     * What is to be done now: every active checklist for its period, with what is ticked and who ticked it.
     *
     * @return array{business_date: string, checklists: list<array<string, mixed>>, may_perform: bool}
     */
    public function board(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, [self::PERFORM_PERMISSION, self::VIEW_PERMISSION, self::MANAGE_PERMISSION]);
        $today = $this->businessDate->current($property);
        $lists = [];

        foreach ($this->routine->latestTemplates($property) as $template) {
            if (! $template['is_active']) {
                continue;
            }

            $period = self::period($template['frequency'], $today);
            $run = $this->routine->findRun($property, $template['name'], $period['key']);
            $items = $run['items'] ?? $template['items'];
            $done = $run === null ? [] : array_column($this->routine->completions($property, $run['id']), null, 'item_id');
            $names = $this->staff->namesOf($property, array_values(array_unique(array_column($done, 'completed_by'))));

            $lists[] = [
                'template_id' => $template['id'], 'name' => $template['name'], 'frequency' => $template['frequency'], 'period_key' => $period['key'], 'period_start' => $period['start'], 'period_end' => $period['end'],
                'items' => array_map(static fn (array $i): array => [
                    'id' => $i['id'], 'text' => $i['text'], 'done' => isset($done[$i['id']]), 'note' => $done[$i['id']]['note'] ?? null,
                    'by' => isset($done[$i['id']]) ? ($names[$done[$i['id']]['completed_by']] ?? null) : null, 'at' => $done[$i['id']]['completed_at'] ?? null,
                ], $items),
                'completed' => count($done), 'total' => count($items), 'percent' => count($items) === 0 ? 0 : intdiv(count($done) * 100, count($items)),
            ];
        }

        return ['business_date' => $today->toString(), 'checklists' => $lists, 'may_perform' => $this->permissions->allowsInProperty($actorId, self::PERFORM_PERMISSION, $property)];
    }

    /**
     * Ticks one item of the checklist in force for the current period. It starts the period's run if nobody has.
     *
     * @return array<string, mixed> the checklist as on the board
     */
    public function complete(PropertyId $property, string $actorId, string $templateId, string $itemId, ?string $note): array
    {
        $this->authorize($property, $actorId, [self::PERFORM_PERMISSION]);
        $actor = strtolower($actorId);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 300) {
            throw Refusal::invalid('A note is at most 300 characters.', ['note']);
        }

        $this->transactions->run(function () use ($property, $actor, $templateId, $itemId, $note): void {
            $template = $this->routine->template($property, strtolower($templateId)) ?? throw Refusal::notFound('Checklist not found.');
            $latest = $this->routine->latestByName($property, $template['name']);

            if ($latest === null || $latest['id'] !== $template['id'] || ! $template['is_active']) {
                throw Refusal::stateConflict('This checklist was changed or retired. Refresh to see the one in force.');
            }

            $today = $this->businessDate->current($property);
            $period = self::period($template['frequency'], $today);
            $now = $this->clock->nowUtc();
            $run = $this->routine->ensureRun($property, $this->ids->next(), $template['id'], $template['name'], $period['key'], $period['start'], $period['end'], $template['items'], $now);

            if (! in_array($itemId, array_column($run['items'], 'id'), true)) {
                throw Refusal::invalid('This item is not on the checklist.', ['item_id']);
            }

            if ($this->routine->complete($property, $this->ids->next(), $run['id'], $itemId, $note, $actor, $now, $today->toString()) === 'already') {
                throw Refusal::stateConflict('This item was already ticked.');
            }

            $done = count($this->routine->completions($property, $run['id']));
            $total = count($run['items']);
            $percent = intdiv($done * 100, $total);

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'sop.item.completed', 'sop_run', $run['id'], null, ['checklist' => $template['name'], 'period' => $period['key'], 'item' => $itemId, 'percent' => $percent]));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.sop.item_completed', $run['id'], 1, [
                'run_id' => $run['id'], 'checklist' => $template['name'], 'frequency' => $template['frequency'], 'period' => $period['key'], 'item_id' => $itemId, 'completed_by' => $actor, 'completed' => $done, 'total' => $total, 'percent' => $percent,
            ]));

            if ($done === $total) {
                $this->outbox->publish(new OutboxEvent($property, 'frontoffice.sop.run_completed', $run['id'], 1, ['run_id' => $run['id'], 'checklist' => $template['name'], 'frequency' => $template['frequency'], 'period' => $period['key'], 'total' => $total, 'completed_by' => $actor]));
            }
        });

        return array_values(array_filter($this->board($property, $actorId)['checklists'], static fn (array $c): bool => $c['template_id'] === strtolower($templateId)))[0] ?? throw Refusal::notFound('Checklist not found.');
    }

    /**
     * How much of each checklist was done in the dates, and by whom: the figure the Human Resource module receives item by item.
     *
     * @return array{from: string, to: string, runs: list<array<string, mixed>>, people: list<array<string, mixed>>}
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

        $runs = $this->routine->performance($property, $start->toString(), $end->toString());
        $people = [];

        foreach ($runs as $r) {
            foreach ($r['by'] as $user => $count) {
                $people[$user] = ($people[$user] ?? 0) + $count;
            }
        }

        $names = $this->staff->namesOf($property, array_keys($people));
        arsort($people);

        return [
            'from' => $start->toString(), 'to' => $end->toString(),
            'runs' => array_map(static fn (array $r): array => ['run_id' => $r['run_id'], 'template' => $r['template'], 'frequency' => $r['frequency'], 'period_key' => $r['period_key'], 'items' => $r['items'], 'completed' => $r['completed'], 'percent' => $r['items'] === 0 ? 0 : intdiv($r['completed'] * 100, $r['items'])], $runs),
            'people' => array_map(static fn (string $id, int $n): array => ['user_id' => $id, 'name' => $names[$id] ?? null, 'items' => $n], array_keys($people), array_values($people)),
        ];
    }

    /** @return array{key: string, start: string, end: string} */
    public static function period(string $frequency, BusinessDate $date): array
    {
        $d = new DateTimeImmutable($date->toString(), new DateTimeZone('UTC'));

        return match ($frequency) {
            'daily' => ['key' => $d->format('Y-m-d'), 'start' => $d->format('Y-m-d'), 'end' => $d->format('Y-m-d')],
            'weekly' => (static function () use ($d): array {
                $monday = $d->setISODate((int) $d->format('o'), (int) $d->format('W'), 1);

                return ['key' => $d->format('o').'-W'.$d->format('W'), 'start' => $monday->format('Y-m-d'), 'end' => $monday->modify('+6 days')->format('Y-m-d')];
            })(),
            default => ['key' => $d->format('Y-m'), 'start' => $d->format('Y-m-01'), 'end' => $d->format('Y-m-t')],
        };
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

        throw Refusal::forbidden('This person may not use the front desk checklists.');
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
