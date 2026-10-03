<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
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
 * What the property owes its suppliers (FR-FIN-011, FR-FIN-012). A payable is made from a recognised supplier invoice; what is still owed is its amount
 * less the payments that were made and the supplier credits applied to it. It is open, partly paid or paid, and overdue when something is owed after the
 * due date. Finance can classify a payable under an expense account and apply a supplier credit to it. The due schedule and the aging report (current,
 * 1-30, 31-60, 61-90 and over 90 days past due) are read from the same figures, as of a business date.
 */
final readonly class PayableService
{
    public const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function __construct(
        private PayableStore $store,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status, ?string $supplierId): array
    {
        $this->access->requireView($property, $actorId);
        $status = $status === null || $status === '' ? 'open' : $status;

        if (! in_array($status, ['open', 'overdue', 'paid', 'all'], true)) {
            throw Refusal::invalid('Choose open, overdue, paid or all.', ['status']);
        }

        $today = $this->businessDate->current($property)->toString();
        $rows = [];
        $owed = 0;
        $overdue = 0;
        $dueSoon = 0;
        $currency = '';

        foreach ($this->store->payables($property, $supplierId === '' ? null : $supplierId) as $p) {
            $shape = $this->shape($p, $today);
            $currency = $shape['currency'];

            if ($shape['balance_minor'] > 0) {
                $owed += $shape['balance_minor'];
                $overdue += $shape['overdue'] ? $shape['balance_minor'] : 0;
                $dueSoon += ! $shape['overdue'] && $shape['days_to_due'] <= 7 ? $shape['balance_minor'] : 0;
            }

            if ($status === 'all' || ($status === 'open' && $shape['balance_minor'] > 0) || ($status === 'overdue' && $shape['overdue']) || ($status === 'paid' && $shape['balance_minor'] === 0)) {
                $rows[] = $shape;
            }
        }

        $suppliers = [];

        foreach ($rows as $r) {
            $suppliers[$r['supplier_id']] = ['id' => $r['supplier_id'], 'name' => $r['supplier_name']];
        }

        return [
            'today' => $today, 'currency' => $currency, 'payables' => $rows, 'owed_minor' => $owed, 'overdue_minor' => $overdue, 'due_soon_minor' => $dueSoon,
            'suppliers' => array_values($suppliers), 'credits' => $this->creditRows($property, null),
            'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::PAYABLE_MANAGE), 'pay' => $this->access->may($property, $actorId, FinanceAccess::PAYMENT_RECORD)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireView($property, $actorId);
        $p = $this->store->payable($property, strtolower($id)) ?? throw Refusal::notFound('Payable not found.');
        $today = $this->businessDate->current($property)->toString();
        $shape = $this->shape($p, $today);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($p['payments'], 'created_by'))));
        $utc = static fn (mixed $v): string => (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $accounts = array_column($this->store->accounts($property), null, 'id');

        return [
            ...$shape, 'issued_on' => substr((string) $p['issued_on'], 0, 10), 'business_date' => substr((string) $p['business_date'], 0, 10), 'tax_minor' => (int) $p['tax_minor'], 'source_type' => $p['source_type'], 'source_id' => $p['source_id'], 'order_number' => $p['order_number'],
            'occurred_at' => $utc($p['occurred_at']), 'correlation_id' => $p['correlation_id'], 'expense_account_id' => $p['expense_account_id'], 'expense_account' => $p['expense_account_id'] === null ? null : ($accounts[$p['expense_account_id']]['code'] ?? null),
            'accounts' => array_values(array_map(static fn (array $a): array => ['id' => $a['id'], 'code' => $a['code'], 'name' => $a['name']], array_filter($accounts, static fn (array $a): bool => (bool) $a['is_active']))),
            'payments' => array_map(static fn (array $pay): array => [
                'id' => $pay['id'], 'number' => $pay['number'], 'amount_minor' => (int) $pay['amount_minor'], 'method' => $pay['method'], 'paid_on' => substr((string) $pay['paid_on'], 0, 10), 'reference' => $pay['reference'], 'status' => $pay['status'], 'reverses_id' => $pay['reverses_id'] ?? null, 'created_by_name' => $names[$pay['created_by']] ?? null,
                'proofs' => array_map(static fn (array $pr): array => ['id' => $pr['id'], 'name' => $pr['display_name']], $pay['proofs']),
            ], $p['payments']),
            'applications' => array_map(static fn (array $a): array => ['id' => $a['id'], 'credit_note_number' => $a['credit_note_number'], 'source_number' => $a['source_number'], 'amount_minor' => (int) $a['amount_minor'], 'business_date' => substr((string) $a['business_date'], 0, 10)], $p['applications']),
            'credits' => array_values(array_filter($this->creditRows($property, $p['supplier_id']), static fn (array $c): bool => $c['available_minor'] > 0)),
            'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::PAYABLE_MANAGE), 'pay' => $this->access->may($property, $actorId, FinanceAccess::PAYMENT_RECORD)],
        ];
    }

    /** @return array<string, mixed> */
    public function classify(PropertyId $property, string $actorId, string $id, ?string $accountId): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PAYABLE_MANAGE, 'This person may not classify payables.');
        $p = $this->store->payable($property, strtolower($id)) ?? throw Refusal::notFound('Payable not found.');
        $account = null;

        if ($accountId !== null && $accountId !== '') {
            $account = $this->store->account($property, strtolower($accountId)) ?? throw Refusal::invalid('Choose an expense account from the list.', ['expense_account_id']);

            if (! (bool) $account['is_active']) {
                throw Refusal::stateConflict('This expense account is inactive.');
            }
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $p, $account): void {
            $this->store->classify($property, $p['id'], $account['id'] ?? null);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payable.classified', 'payable', $p['id'], ['expense_account_id' => $p['expense_account_id']], ['expense_account_id' => $account['id'] ?? null, 'code' => $account['code'] ?? null]));
        });

        return $this->show($property, $actorId, $p['id']);
    }

    /** Sets (part of) a supplier credit against a payable of the same supplier. @return array<string, mixed> */
    public function applyCredit(PropertyId $property, string $actorId, string $payableId, string $creditId, int $amountMinor): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PAYABLE_MANAGE, 'This person may not apply supplier credits.');
        $actor = strtolower($actorId);

        if ($amountMinor < 1) {
            throw Refusal::invalid('Give an amount above zero.', ['amount_minor']);
        }

        $this->transactions->run(function () use ($property, $actor, $payableId, $creditId, $amountMinor): void {
            $this->store->lockPayable($property, strtolower($payableId));
            $this->store->lockCredit($property, strtolower($creditId));
            $payable = $this->store->payable($property, strtolower($payableId)) ?? throw Refusal::notFound('Payable not found.');
            $credit = $this->store->credit($property, strtolower($creditId)) ?? throw Refusal::invalid('Choose a supplier credit.', ['credit_id']);

            if ($credit['supplier_id'] !== $payable['supplier_id']) {
                throw Refusal::stateConflict('A credit can be set only against a payable of the same supplier.');
            }

            $balance = (int) $payable['amount_minor'] - (int) $payable['paid_minor'] - (int) $payable['pending_minor'] - (int) $payable['credit_minor'];
            $available = (int) $credit['amount_minor'] - (int) $credit['applied_minor'];

            if ($amountMinor > $available || $amountMinor > $balance) {
                throw Refusal::stateConflict('The amount is more than the credit has left ('.$available.') or than the payable still owes ('.$balance.').');
            }

            $date = $this->businessDate->current($property)->toString();
            $this->store->addCreditApplication($property, ['id' => $this->ids->next(), 'credit_id' => $credit['id'], 'payable_id' => $payable['id'], 'amount_minor' => $amountMinor, 'applied_by' => $actor, 'business_date' => $date], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payable.credit_applied', 'payable', $payable['id'], null, ['credit_note_number' => $credit['credit_note_number'], 'amount_minor' => $amountMinor, 'payable' => $payable['source_number']]));
            $this->outbox->publish(new OutboxEvent($property, 'finance.payable.credit_applied', $payable['id'], 1, ['payable_id' => $payable['id'], 'credit_id' => $credit['id'], 'amount_minor' => $amountMinor, 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, strtolower($payableId));
    }

    /**
     * The aging of what is owed at the end of a business date, per supplier and in total, by how many days past due it is.
     *
     * @return array<string, mixed>
     */
    public function aging(PropertyId $property, string $actorId, ?string $asOf): array
    {
        $this->access->requireView($property, $actorId);
        $date = $asOf === null || $asOf === '' ? $this->businessDate->current($property)->toString() : $asOf;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', ['as_of']);
        }

        $per = [];
        $totals = array_fill_keys(self::BUCKETS, 0);
        $currency = '';

        foreach ($this->store->outstandingAsOf($property, $date) as $p) {
            $left = (int) $p['amount_minor'] - (int) $p['paid_minor'] - (int) $p['credit_minor'];
            $bucket = $this->bucket(substr((string) $p['due_date'], 0, 10), $date);
            $currency = $p['currency'];
            $per[$p['supplier_id']] ??= ['supplier_id' => $p['supplier_id'], 'supplier_name' => $p['supplier_name'], ...array_fill_keys(self::BUCKETS, 0), 'total_minor' => 0, 'documents' => 0];
            $per[$p['supplier_id']][$bucket] += $left;
            $per[$p['supplier_id']]['total_minor'] += $left;
            $per[$p['supplier_id']]['documents']++;
            $totals[$bucket] += $left;
        }

        $rows = array_values($per);
        usort($rows, static fn (array $a, array $b): int => strcmp($a['supplier_name'], $b['supplier_name']));

        return ['as_of' => $date, 'currency' => $currency, 'rows' => $rows, 'totals' => [...$totals, 'total_minor' => array_sum($totals)], 'buckets' => self::BUCKETS];
    }

    /**
     * Open payables by due date: what is overdue and what falls due in the days ahead.
     *
     * @return array<string, mixed>
     */
    public function schedule(PropertyId $property, string $actorId, int $days): array
    {
        $this->access->requireView($property, $actorId);
        $days = max(1, min(90, $days));
        $today = $this->businessDate->current($property)->toString();
        $limit = (new DateTimeImmutable($today))->modify('+'.$days.' days')->format('Y-m-d');
        $rows = [];

        foreach ($this->store->payables($property, null) as $p) {
            $shape = $this->shape($p, $today);

            if ($shape['balance_minor'] > 0 && $shape['due_date'] <= $limit) {
                $rows[] = $shape;
            }
        }

        return ['today' => $today, 'days' => $days, 'until' => $limit, 'rows' => $rows, 'overdue_minor' => array_sum(array_map(static fn (array $r): int => $r['overdue'] ? $r['balance_minor'] : 0, $rows)), 'due_minor' => array_sum(array_column($rows, 'balance_minor'))];
    }

    /** @return list<array<string, mixed>> */
    private function creditRows(PropertyId $property, ?string $supplierId): array
    {
        return array_map(static fn (array $c): array => [
            'id' => $c['id'], 'supplier_id' => $c['supplier_id'], 'supplier_name' => $c['supplier_name'], 'credit_note_number' => $c['credit_note_number'], 'source_number' => $c['source_number'], 'amount_minor' => (int) $c['amount_minor'],
            'applied_minor' => (int) $c['applied_minor'], 'available_minor' => (int) $c['amount_minor'] - (int) $c['applied_minor'], 'business_date' => substr((string) $c['business_date'], 0, 10), 'currency' => $c['currency'],
        ], $this->store->credits($property, $supplierId));
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function shape(array $p, string $today): array
    {
        $paid = (int) $p['paid_minor'];
        $credit = (int) $p['credit_minor'];
        $pending = (int) ($p['pending_minor'] ?? 0);
        $balance = (int) $p['amount_minor'] - $paid - $credit;
        $due = substr((string) $p['due_date'], 0, 10);
        $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($due))->format('%r%a');

        return [
            'id' => $p['id'], 'supplier_id' => $p['supplier_id'], 'supplier_code' => $p['supplier_code'], 'supplier_name' => $p['supplier_name'], 'source_number' => $p['source_number'], 'document_number' => $p['document_number'], 'due_date' => $due,
            'amount_minor' => (int) $p['amount_minor'], 'paid_minor' => $paid, 'credit_minor' => $credit, 'pending_minor' => $pending, 'balance_minor' => $balance, 'available_minor' => $balance - $pending, 'currency' => $p['currency'],
            'status' => $balance === 0 ? 'paid' : ($paid + $credit > 0 ? 'partial' : 'open'), 'overdue' => $balance > 0 && $due < $today, 'days_to_due' => $days, 'days_overdue' => $balance > 0 && $days < 0 ? -$days : 0,
        ];
    }

    private function bucket(string $due, string $asOf): string
    {
        $late = (int) (new DateTimeImmutable($due))->diff(new DateTimeImmutable($asOf))->format('%r%a');

        return match (true) {
            $late <= 0 => 'current',
            $late <= 30 => 'd1_30',
            $late <= 60 => 'd31_60',
            $late <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }
}
