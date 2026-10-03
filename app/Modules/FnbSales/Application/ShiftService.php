<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The shift of a cashier at an outlet (FR-FBS-009). A cashier opens it with the float put in the drawer and takes payments only while it is open; a bill is
 * paid into the shift of whoever took the payment. Closing counts the cash against what the system expects (the float plus the cash taken for bills) and keeps
 * the variance with the reason; any variance needs one. A shift with a QRIS payment that is not decided yet is not closed until it is. Closing publishes what the
 * shift took, by method, for finance to receive the cash.
 */
final readonly class ShiftService
{
    public function __construct(
        private PaymentStore $store,
        private SetupStore $setup,
        private FnbAccess $access,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** The cashier's open shift, the outlets one can be opened for, and, for a manager, the recent shifts. @return array<string, mixed> */
    public function mine(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $cashier = $this->access->may($property, $actorId, FnbAccess::CASHIER_OPERATE);
        $manager = $this->access->may($property, $actorId, FnbAccess::SETUP_MANAGE);

        if (! $cashier && ! $manager) {
            throw Refusal::forbidden('This person may not see the cashier shifts.');
        }

        $open = $cashier ? $this->store->openShiftOf($property, strtolower($actorId)) : null;
        $recent = $manager ? $this->store->shifts($property, null, 30) : [];
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($recent, 'cashier_id'))));

        return [
            'currency' => $this->currencies->currencyOf($property),
            'shift' => $open === null ? null : $this->view($property, $open),
            'outlets' => $cashier ? array_values(array_map(static fn (array $o): array => ['id' => $o['id'], 'code' => $o['code'], 'name' => $o['name']], array_filter($this->setup->outlets($property), static fn (array $o): bool => (bool) $o['is_active']))) : [],
            'recent' => array_map(fn (array $s): array => [...$this->summary($s), 'cashier' => $names[$s['cashier_id']] ?? null], $recent),
            'may' => ['operate' => $cashier, 'manage' => $manager],
        ];
    }

    /** @return array<string, mixed> */
    public function open(PropertyId $property, string $actorId, string $outletId, int $floatMinor): array
    {
        $this->access->require($property, $actorId, FnbAccess::CASHIER_OPERATE, 'This person may not open a cashier shift.');
        $outlet = $this->setup->outlet($property, strtolower($outletId)) ?? throw Refusal::invalid('Choose an outlet.', ['outlet_id']);

        if (! (bool) $outlet['is_active']) {
            throw Refusal::stateConflict('This outlet is not in use.');
        }

        if ($floatMinor < 0 || $floatMinor > 9_000_000_000_000) {
            throw Refusal::invalid('Give the float as an amount of zero or more.', ['opening_float_minor']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();
        $date = $this->businessDate->current($property)->toString();
        $currency = $this->currencies->currencyOf($property);

        $this->transactions->run(function () use ($property, $actor, $id, $outlet, $floatMinor, $date, $currency): void {
            $number = $this->numbers->next($property, 'FSH');

            if (! $this->store->addShift($property, ['id' => $id, 'outlet_id' => $outlet['id'], 'number' => $number, 'cashier_id' => $actor, 'currency' => $currency, 'opening_float_minor' => $floatMinor, 'business_date' => $date, 'opened_at' => $this->clock->nowUtc()], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('You have a shift open already. Close it first.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_shift.opened', 'fnb_shift', $id, null, ['number' => $number, 'outlet' => $outlet['code'], 'opening_float_minor' => $floatMinor]));
        });

        return $this->view($property, $this->store->shift($property, $id) ?? throw Refusal::notFound('Shift not found.'));
    }

    /** @return array<string, mixed> */
    public function close(PropertyId $property, string $actorId, string $shiftId, int $countedMinor, ?string $reason, int $lock): array
    {
        $this->access->require($property, $actorId, FnbAccess::CASHIER_OPERATE, 'This person may not close a cashier shift.');
        $actor = strtolower($actorId);

        if ($countedMinor < 0 || $countedMinor > 9_000_000_000_000) {
            throw Refusal::invalid('Give the cash counted as an amount of zero or more.', ['counted_cash_minor']);
        }

        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;

        if ($reason !== null && mb_strlen($reason) > 200) {
            throw Refusal::invalid('The reason is at most 200 characters.', ['reason']);
        }

        $this->transactions->run(function () use ($property, $actor, $shiftId, $countedMinor, $reason, $lock): void {
            $this->store->lockShift($property, strtolower($shiftId));
            $shift = $this->store->shift($property, strtolower($shiftId)) ?? throw Refusal::notFound('Shift not found.');

            if ($shift['cashier_id'] !== $actor) {
                throw Refusal::forbidden('A shift is closed by the cashier who opened it.');
            }

            if ($shift['status'] !== 'open') {
                throw Refusal::stateConflict('This shift is closed already.');
            }

            if ((int) $shift['lock_version'] !== $lock) {
                throw Refusal::stateConflict('This shift changed after you opened it. Reload it.');
            }

            if ($this->store->unresolvedCount($property, $shift['id']) > 0) {
                throw Refusal::stateConflict('A QRIS payment of this shift is not decided yet. Mark it paid, failed or expired first.');
            }

            $totals = $this->totals($property, $shift['id']);
            $expected = (int) $shift['opening_float_minor'] + $totals['cash_minor'];
            $variance = $countedMinor - $expected;

            if ($variance !== 0 && $reason === null) {
                throw Refusal::invalid('The cash counted is not the cash expected. Say why.', ['reason']);
            }

            $now = $this->clock->nowUtc();
            $date = $this->businessDate->current($property)->toString();

            if (! $this->store->closeShift($property, $shift['id'], $lock, ['closed_at' => $now, 'closed_business_date' => $date, 'expected_cash_minor' => $expected, 'counted_cash_minor' => $countedMinor, 'variance_minor' => $variance, 'variance_reason' => $variance === 0 ? null : $reason], $now)) {
                throw Refusal::stateConflict('This shift changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_shift.closed', 'fnb_shift', $shift['id'], ['status' => 'open', 'expected_cash_minor' => $expected], ['status' => 'closed', 'counted_cash_minor' => $countedMinor, 'variance_minor' => $variance], $variance === 0 ? null : $reason));
            $this->outbox->publish(new OutboxEvent($property, 'fnb.cashier.shift.closed', $shift['id'], 1, [
                'shift_id' => $shift['id'], 'number' => $shift['number'], 'outlet_id' => $shift['outlet_id'], 'cashier_id' => $shift['cashier_id'], 'actor_id' => $actor, 'currency' => $shift['currency'], 'opened_business_date' => $shift['business_date'], 'closed_business_date' => $date,
                'opening_float_minor' => (int) $shift['opening_float_minor'], 'expected_cash_minor' => $expected, 'counted_cash_minor' => $countedMinor, 'variance_minor' => $variance, 'cash_net_minor' => $totals['cash_minor'], 'payments' => $totals['by_method'],
            ]));
        });

        return $this->view($property, $this->store->shift($property, strtolower($shiftId)) ?? throw Refusal::notFound('Shift not found.'));
    }

    /**
     * What a shift took: the cash that counts towards the drawer and, by method, what was paid.
     *
     * @return array{cash_minor: int, by_method: list<array{method: string, count: int, amount_minor: int}>, open_minor: int}
     */
    public function totals(PropertyId $property, string $shiftId): array
    {
        $by = [];
        $cash = 0;
        $open = 0;

        foreach ($this->store->shiftTotals($property, $shiftId) as $t) {
            if ($t['status'] === 'paid') {
                $by[$t['method']] = ['method' => $t['method'], 'count' => ($by[$t['method']]['count'] ?? 0) + $t['count'], 'amount_minor' => ($by[$t['method']]['amount_minor'] ?? 0) + $t['amount_minor']];

                if ($t['method'] === 'cash') {
                    $cash += $t['amount_minor'];
                }
            } elseif (in_array($t['status'], ['initiated', 'pending', 'unknown'], true)) {
                $open += $t['amount_minor'];
            }
        }

        // What the refunds made in this shift paid back is taken out of what it took, by method: the cash leaves this drawer, whichever shift took it.
        foreach ($this->store->shiftRefunds($property, $shiftId) as $method => $amount) {
            $by[$method] = ['method' => (string) $method, 'count' => $by[$method]['count'] ?? 0, 'amount_minor' => ($by[$method]['amount_minor'] ?? 0) - $amount];

            if ($method === 'cash') {
                $cash -= $amount;
            }
        }

        return ['cash_minor' => $cash, 'by_method' => array_values($by), 'open_minor' => $open];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function view(PropertyId $property, array $s): array
    {
        $totals = $this->totals($property, $s['id']);

        return [...$this->summary($s), 'outlet' => $s['outlet_name'] ?? $this->setup->outlet($property, $s['outlet_id'])['name'] ?? null, 'cash_taken_minor' => $totals['cash_minor'], 'expected_now_minor' => (int) $s['opening_float_minor'] + $totals['cash_minor'], 'by_method' => $totals['by_method'], 'open_minor' => $totals['open_minor']];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function summary(array $s): array
    {
        return [
            'id' => $s['id'], 'number' => $s['number'], 'outlet_id' => $s['outlet_id'], 'outlet' => $s['outlet_name'] ?? null, 'status' => $s['status'], 'opening_float_minor' => (int) $s['opening_float_minor'], 'business_date' => $s['business_date'], 'opened_at' => FnbTime::utc($s['opened_at']),
            'closed_at' => FnbTime::utc($s['closed_at']), 'expected_cash_minor' => $s['expected_cash_minor'] === null ? null : (int) $s['expected_cash_minor'], 'counted_cash_minor' => $s['counted_cash_minor'] === null ? null : (int) $s['counted_cash_minor'],
            'variance_minor' => $s['variance_minor'] === null ? null : (int) $s['variance_minor'], 'variance_reason' => $s['variance_reason'], 'lock_version' => (int) $s['lock_version'],
        ];
    }
}
