<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fixed costs that come round: rent, electricity, water, subscriptions (FR-FIN-016). A recurring expense has a schedule (monthly, quarterly or yearly, on a day
 * of the month, a day past the end of a short month falling on its last day), the amount expected and the expense account it is booked under. Its next due date
 * is the oldest one nobody has settled; a person settles it as paid (the amount that was paid, when and how) or as skipped (with the reason), and the next due date
 * follows. Reminders start a number of days before the due date and turn to overdue after it; the dashboard warns of both. A paid due date is a cost of the
 * department of its account in the management P&L and a payment in the cash flow; nothing here makes a supplier payable, so a cost that is invoiced through
 * purchasing is not also recorded here.
 */
final readonly class RecurringExpenseService
{
    public const FREQUENCIES = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    public const METHODS = ['cash', 'transfer', 'giro', 'other'];

    public const DEFAULT_REMIND_DAYS = 7;

    private const MAX_MINOR = 9_000_000_000_000;

    public function __construct(
        private RecurringExpenseStore $store,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private IdempotentExecutor $executor,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->requireRecurringView($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $items = array_map(fn (array $r): array => $this->shape($r, $today), $this->store->all($property));
        $soonLimit = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
        $expected = 0;

        foreach ($items as $i) {
            foreach ($i['upcoming'] as $date) {
                $expected += $date <= $soonLimit ? $i['amount_minor'] : 0;
            }
        }

        return [
            'today' => $today, 'items' => $items, 'accounts' => array_map(static fn (array $a): array => ['id' => $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'department' => $a['department']], $this->store->accounts($property)),
            'overdue_count' => count(array_filter($items, static fn (array $i): bool => $i['state'] === 'overdue')), 'due_soon_count' => count(array_filter($items, static fn (array $i): bool => $i['state'] === 'due_soon')), 'expected_30_days_minor' => $expected,
            'frequencies' => array_keys(self::FREQUENCIES), 'methods' => self::METHODS, 'default_remind_days' => self::DEFAULT_REMIND_DAYS, 'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::RECURRING_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireRecurringView($property, $actorId);
        $r = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Recurring expense not found.');
        $today = $this->businessDate->current($property)->toString();
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($r['history'], 'settled_by'))));

        return [
            ...$this->shape($r, $today), 'methods' => self::METHODS,
            'history' => array_map(static fn (array $h): array => [
                'due_date' => substr((string) $h['due_date'], 0, 10), 'status' => $h['status'], 'amount_minor' => $h['amount_minor'] === null ? null : (int) $h['amount_minor'], 'paid_on' => $h['paid_on'] === null ? null : substr((string) $h['paid_on'], 0, 10),
                'method' => $h['method'], 'reference' => $h['reference'], 'note' => $h['note'], 'by' => $names[$h['settled_by']] ?? null,
            ], $r['history']),
            'accounts' => array_map(static fn (array $a): array => ['id' => $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'department' => $a['department']], $this->store->accounts($property)),
            'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::RECURRING_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $name, string $accountId, ?string $payee, int $amountMinor, string $frequency, int $dueDay, string $startMonth, ?string $endDate, ?int $remindDays): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECURRING_MANAGE, 'This person may not manage recurring expenses.');
        $name = trim($name);
        $payee = $payee === null || trim($payee) === '' ? null : trim($payee);
        $remindDays ??= self::DEFAULT_REMIND_DAYS;

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give a name of at most 80 characters.', ['name']);
        }

        if ($payee !== null && mb_strlen($payee) > 120) {
            throw Refusal::invalid('The payee is at most 120 characters.', ['payee']);
        }

        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give the amount expected, above zero.', ['amount_minor']);
        }

        if (! isset(self::FREQUENCIES[$frequency])) {
            throw Refusal::invalid('Choose monthly, quarterly or yearly.', ['frequency']);
        }

        if ($dueDay < 1 || $dueDay > 31) {
            throw Refusal::invalid('The due day is a day of the month, 1 to 31.', ['due_day']);
        }

        if (preg_match('/^\d{4}-\d{2}$/D', $startMonth) !== 1 || (int) substr($startMonth, 5, 2) < 1 || (int) substr($startMonth, 5, 2) > 12) {
            throw Refusal::invalid('Give the first month as year-month.', ['start_month']);
        }

        $endDate = $endDate === null || $endDate === '' ? null : $this->date($endDate, 'end_date');
        $this->assertRemind($remindDays);
        $account = $this->store->account($property, strtolower($accountId));

        if ($account === null || ! $account['is_active']) {
            throw Refusal::invalid('Choose an active expense account.', ['expense_account_id']);
        }

        $first = $this->dueIn($startMonth, $dueDay);

        if ($endDate !== null && $endDate < $first) {
            throw Refusal::invalid('The end date is before the first due date.', ['end_date']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $name, $account, $payee, $amountMinor, $frequency, $dueDay, $startMonth, $endDate, $remindDays, $first): void {
            $this->store->add($property, ['id' => $id, 'name' => $name, 'expense_account_id' => $account['id'], 'payee' => $payee, 'amount_minor' => $amountMinor, 'frequency' => $frequency, 'due_day' => $dueDay, 'start_month' => $startMonth.'-01', 'end_date' => $endDate, 'remind_days' => $remindDays, 'next_due' => $first], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'recurring_expense.created', 'recurring_expense', $id, null, ['name' => $name, 'account' => $account['code'], 'amount_minor' => $amountMinor, 'frequency' => $frequency, 'due_day' => $dueDay, 'first_due' => $first]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function update(PropertyId $property, string $actorId, string $id, string $name, string $accountId, ?string $payee, int $amountMinor, int $remindDays, ?string $endDate, bool $active, int $expectedLockVersion): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECURRING_MANAGE, 'This person may not manage recurring expenses.');
        $name = trim($name);
        $payee = $payee === null || trim($payee) === '' ? null : trim($payee);

        if ($name === '' || mb_strlen($name) > 80 || ($payee !== null && mb_strlen($payee) > 120)) {
            throw Refusal::invalid('Give a name of at most 80 characters and a payee of at most 120.', ['name']);
        }

        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give the amount expected, above zero.', ['amount_minor']);
        }

        $this->assertRemind($remindDays);
        $endDate = $endDate === null || $endDate === '' ? null : $this->date($endDate, 'end_date');
        $account = $this->store->account($property, strtolower($accountId));

        if ($account === null) {
            throw Refusal::invalid('Choose an expense account.', ['expense_account_id']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $name, $account, $payee, $amountMinor, $remindDays, $endDate, $active, $expectedLockVersion): void {
            $before = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Recurring expense not found.');

            if ($account['id'] !== $before['expense_account_id'] && ! $account['is_active']) {
                throw Refusal::invalid('Choose an active expense account.', ['expense_account_id']);
            }

            if ($endDate !== null && $endDate < substr((string) $before['start_month'], 0, 10)) {
                throw Refusal::invalid('The end date is before the first month.', ['end_date']);
            }

            if (! $this->store->update($property, $before['id'], $expectedLockVersion, ['name' => $name, 'expense_account_id' => $account['id'], 'payee' => $payee, 'amount_minor' => $amountMinor, 'remind_days' => $remindDays, 'end_date' => $endDate, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This recurring expense changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'recurring_expense.updated', 'recurring_expense', $before['id'],
                ['name' => $before['name'], 'account' => $before['account_code'], 'amount_minor' => (int) $before['amount_minor'], 'remind_days' => (int) $before['remind_days'], 'end_date' => $before['end_date'] === null ? null : substr((string) $before['end_date'], 0, 10), 'is_active' => (bool) $before['is_active']],
                ['name' => $name, 'account' => $account['code'], 'amount_minor' => $amountMinor, 'remind_days' => $remindDays, 'end_date' => $endDate, 'is_active' => $active]));
        });

        return $this->show($property, $actorId, $id);
    }

    /**
     * Settles the next due date: paid (the amount that was paid, when and how) or skipped (with the reason). The due dates are settled in order.
     *
     * @return array<string, mixed>
     */
    public function settle(PropertyId $property, string $actorId, string $id, string $action, ?int $amountMinor, ?string $paidOn, ?string $method, ?string $reference, ?string $note, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECURRING_MANAGE, 'This person may not manage recurring expenses.');
        $today = $this->businessDate->current($property)->toString();
        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! in_array($action, ['paid', 'skipped'], true)) {
            throw Refusal::invalid('Choose paid or skipped.', ['action']);
        }

        if (($reference !== null && mb_strlen($reference) > 60) || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('The reference is at most 60 characters and the note at most 200.', ['reference']);
        }

        if ($action === 'paid') {
            $paidOn = $paidOn === null || $paidOn === '' ? $today : $this->date($paidOn, 'paid_on');

            if ($method === null || ! in_array($method, self::METHODS, true)) {
                throw Refusal::invalid('Choose how it was paid.', ['method']);
            }

            if (in_array($method, ['transfer', 'giro'], true) && $reference === null) {
                throw Refusal::invalid('A transfer or a giro needs its reference.', ['reference']);
            }

            if ($amountMinor !== null && ($amountMinor < 1 || $amountMinor > self::MAX_MINOR)) {
                throw Refusal::invalid('Give the amount paid, above zero.', ['amount_minor']);
            }

            if ($paidOn > $today) {
                throw Refusal::invalid('A payment cannot be dated in the future.', ['paid_on']);
            }
        } elseif ($note === null) {
            throw Refusal::invalid('Say why this due date is skipped.', ['note']);
        }

        $actor = strtolower($actorId);
        $occurrenceId = $this->ids->next();

        $operation = function () use ($property, $actor, $occurrenceId, $id, $action, $amountMinor, $paidOn, $method, $reference, $note, $today): void {
            $this->store->lock($property, strtolower($id));
            $r = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Recurring expense not found.');

            if (! $r['is_active']) {
                throw Refusal::stateConflict('This recurring expense is paused.');
            }

            $due = substr((string) $r['next_due'], 0, 10);
            $end = $r['end_date'] === null ? null : substr((string) $r['end_date'], 0, 10);

            if ($end !== null && $due > $end) {
                throw Refusal::stateConflict('This recurring expense has ended; nothing is left to settle.');
            }

            $amount = $action === 'paid' ? ($amountMinor ?? (int) $r['amount_minor']) : null;

            if ($action === 'paid' && $paidOn < substr((string) $r['start_month'], 0, 10)) {
                throw Refusal::invalid('A payment cannot be dated before the first month of this expense.', ['paid_on']);
            }

            if (! $this->store->addOccurrence($property, ['id' => $occurrenceId, 'recurring_id' => $r['id'], 'due_date' => $due, 'status' => $action, 'amount_minor' => $amount, 'paid_on' => $action === 'paid' ? $paidOn : null, 'method' => $action === 'paid' ? $method : null, 'reference' => $reference, 'note' => $note, 'business_date' => $today, 'settled_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This due date was settled already.');
            }

            $next = $this->nextDue($due, (string) $r['frequency'], (int) $r['due_day']);
            $this->store->advance($property, $r['id'], $next, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'recurring_expense.'.$action, 'recurring_expense', $r['id'], ['next_due' => $due], ['next_due' => $next, 'name' => $r['name'], 'due_date' => $due, 'amount_minor' => $amount, 'expected_minor' => (int) $r['amount_minor'], 'method' => $action === 'paid' ? $method : null], $note));

            if ($action === 'paid') {
                $this->outbox->publish(new OutboxEvent($property, 'finance.recurring.paid', $occurrenceId, 1, ['occurrence_id' => $occurrenceId, 'recurring_id' => $r['id'], 'name' => $r['name'], 'due_date' => $due, 'paid_on' => $paidOn, 'amount_minor' => $amount, 'method' => $method, 'expense_account_code' => $r['account_code'], 'department' => $r['department'], 'category' => $r['category'], 'business_date' => $today, 'actor_id' => $actor]));
            }
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $this->executor->execute(new IdempotencyRequest($property, $key, 'finance.recurring.settle', ['id' => strtolower($id), 'action' => $action, 'amount' => $amountMinor, 'paid_on' => $paidOn, 'method' => $method, 'reference' => $reference], $actor), function () use ($operation, $occurrenceId): array {
                $operation();

                return ['id' => $occurrenceId];
            });
        }

        return $this->show($property, $actorId, $id);
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function shape(array $r, string $today): array
    {
        $due = substr((string) $r['next_due'], 0, 10);
        $end = $r['end_date'] === null ? null : substr((string) $r['end_date'], 0, 10);
        $finished = $end !== null && $due > $end;
        $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($due))->format('%r%a');
        $state = match (true) {
            ! $r['is_active'] => 'paused',
            $finished => 'finished',
            $days < 0 => 'overdue',
            $days <= (int) $r['remind_days'] => 'due_soon',
            default => 'upcoming',
        };
        $upcoming = [];

        if (! $finished && $r['is_active']) {
            $date = $due;

            for ($i = 0; $i < 3 && ($end === null || $date <= $end); $i++) {
                $upcoming[] = $date;
                $date = $this->nextDue($date, (string) $r['frequency'], (int) $r['due_day']);
            }
        }

        return [
            'id' => $r['id'], 'name' => $r['name'], 'expense_account_id' => $r['expense_account_id'], 'account_code' => $r['account_code'], 'account_name' => $r['account_name'], 'department' => $r['department'], 'payee' => $r['payee'],
            'amount_minor' => (int) $r['amount_minor'], 'frequency' => $r['frequency'], 'due_day' => (int) $r['due_day'], 'start_month' => substr((string) $r['start_month'], 0, 7), 'end_date' => $end, 'remind_days' => (int) $r['remind_days'],
            'next_due' => $finished ? null : $due, 'days_to_due' => $finished ? null : $days, 'days_overdue' => ! $finished && $days < 0 ? -$days : 0, 'state' => $state, 'active' => (bool) $r['is_active'], 'lock_version' => (int) $r['lock_version'], 'upcoming' => $upcoming,
        ];
    }

    /** The due date of a month (`Y-m`): the day asked for, or the last day of a shorter month. */
    private function dueIn(string $month, int $day): string
    {
        $last = (int) (new DateTimeImmutable($month.'-01', new DateTimeZone('UTC')))->format('t');

        return sprintf('%s-%02d', $month, min($day, $last));
    }

    private function nextDue(string $after, string $frequency, int $dueDay): string
    {
        $first = (new DateTimeImmutable(substr($after, 0, 7).'-01', new DateTimeZone('UTC')))->modify('+'.self::FREQUENCIES[$frequency].' months');

        return $this->dueIn($first->format('Y-m'), $dueDay);
    }

    private function assertRemind(int $days): void
    {
        if ($days < 0 || $days > 60) {
            throw Refusal::invalid('The reminder starts 0 to 60 days before the due date.', ['remind_days']);
        }
    }

    private function date(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $value;
    }
}
