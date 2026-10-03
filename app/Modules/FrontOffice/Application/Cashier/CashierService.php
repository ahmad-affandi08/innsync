<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Cashier;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
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

/**
 * Cashier shifts of the front desk (FR-FO-036): a person opens a shift with a float, money they take or return is counted
 * against it per payment method, cash is dropped into the safe, and at the end the physical cash is counted against what the
 * system expects. A difference needs a reason and is kept as a fact; a closed shift never changes.
 *
 * Expected cash = opening float + cash received - cash paid back - cash drops. Other methods are not counted by hand here: their
 * total is reported so it can be matched with the card terminal or bank statement.
 */
final readonly class CashierService implements ShiftAttribution
{
    public const OPERATE_PERMISSION = 'front-office.cashier.operate';

    public const VIEW_PERMISSION = 'front-office.cashier.view';

    public const MANAGE_PERMISSION = 'front-office.cashier.manage';

    public const SETTINGS_PERMISSION = 'front-office.cashier.settings';

    private const MAX_MINOR = 1_000_000_000_000_000;

    public function __construct(
        private CashierRepository $shifts,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    // ---- the folio's side ----

    public function assertMayHandleMoney(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if ($this->shifts->settings($property)['require_open_shift'] && $this->shifts->openOf($property, strtolower($actorId)) === null) {
            throw CashierRefused::shiftRequired();
        }
    }

    public function attribute(PropertyId $property, string $actorId, string $postingId): void
    {
        $this->assertProperty($property);
        $open = $this->shifts->openOf($property, strtolower($actorId));

        if ($open !== null) {
            $this->shifts->attribute($property, $open['id'], $postingId, $this->clock->nowUtc());
        }
    }

    // ---- reads ----

    /**
     * The person's own screen: their open shift, if any, with what has gone through it.
     *
     * @return array<string, mixed>
     */
    public function mine(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::OPERATE_PERMISSION);
        $open = $this->shifts->openOf($property, strtolower($actorId));

        return [
            'shift' => $open === null ? null : $this->describe($property, $open),
            'currency' => $this->currencies->currencyOf($property),
            'require_open_shift' => $this->shifts->settings($property)['require_open_shift'],
            'may_view_all' => $this->permissions->allowsInProperty($actorId, self::VIEW_PERMISSION, $property),
        ];
    }

    /** @return array<string, mixed> */
    public function view(PropertyId $property, string $actorId, string $shiftId): array
    {
        $this->assertProperty($property);
        $shift = $this->shifts->find($property, strtolower($shiftId)) ?? throw Refusal::notFound('Shift not found.');
        $own = $shift['cashier_id'] === strtolower($actorId) && $this->permissions->allowsInProperty($actorId, self::OPERATE_PERMISSION, $property);

        if (! $own && ! $this->permissions->allowsInProperty($actorId, self::VIEW_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see this shift.');
        }

        return [
            'shift' => $this->describe($property, $shift),
            'currency' => $shift['currency'],
            'may_close' => $shift['status'] === 'open' && ($own || $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)),
            'may_drop' => $shift['status'] === 'open' && $own,
        ];
    }

    /**
     * Shifts for review, newest first.
     *
     * @return array<string, mixed>
     */
    public function search(PropertyId $property, string $actorId, ?string $status, ?string $cashierId): array
    {
        $this->authorize($property, $actorId, self::VIEW_PERMISSION);

        if ($status !== null && $status !== '' && ! in_array($status, ['open', 'closed'], true)) {
            throw Refusal::invalid('Choose open or closed.', ['status']);
        }

        $rows = $this->shifts->search($property, $status === '' ? null : $status, $cashierId === '' ? null : $cashierId, 100);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'cashier_id'))));

        return [
            'shifts' => array_map(static fn (array $s): array => [...$s, 'cashier_name' => $names[$s['cashier_id']] ?? null], $rows),
            'currency' => $this->currencies->currencyOf($property),
        ];
    }

    /** @return array{require_open_shift: bool, lock_version: int} */
    public function settings(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId, self::SETTINGS_PERMISSION);

        return $this->shifts->settings($property);
    }

    // ---- actions ----

    /** @return array<string, mixed> */
    public function open(PropertyId $property, string $actorId, int $floatMinor): array
    {
        $this->authorize($property, $actorId, self::OPERATE_PERMISSION);
        $actor = strtolower($actorId);
        $this->assertAmount($floatMinor, 0, 'The float is zero or more.', 'opening_float');

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $floatMinor): void {
            $today = $this->businessDate->current($property);
            $currency = $this->currencies->currencyOf($property);
            $number = $this->numbers->next($property, 'SHF');

            if (! $this->shifts->open($property, $id, $number, $actor, $currency, $this->clock->nowUtc(), $today->toString(), $floatMinor)) {
                throw CashierRefused::alreadyOpen();
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'cashier.shift.opened', 'cashier_shift', $id, null, ['number' => $number, 'opening_float_minor' => $floatMinor, 'currency' => $currency, 'business_date' => $today->toString()]));
        });

        return $this->describe($property, $this->shifts->find($property, $id) ?? throw Refusal::notFound('Shift not found.'));
    }

    /**
     * Cash taken out of the drawer into the safe. A drop is never more than the cash the system expects to be there.
     *
     * @return array<string, mixed>
     */
    public function drop(PropertyId $property, string $actorId, string $shiftId, int $amountMinor, ?string $reference, ?string $note): array
    {
        $this->authorize($property, $actorId, self::OPERATE_PERMISSION);
        $actor = strtolower($actorId);
        $this->assertAmount($amountMinor, 1, 'A drop is more than zero.', 'amount');
        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (($reference !== null && mb_strlen($reference) > 80) || ($note !== null && mb_strlen($note) > 300)) {
            throw Refusal::invalid('The reference is at most 80 and the note at most 300 characters.', ['reference', 'note']);
        }

        $this->transactions->run(function () use ($property, $actor, $shiftId, $amountMinor, $reference, $note): void {
            $shift = $this->shifts->find($property, strtolower($shiftId)) ?? throw Refusal::notFound('Shift not found.');

            if ($shift['cashier_id'] !== $actor) {
                throw Refusal::forbidden('Only the person who opened the shift can drop cash from it.');
            }

            if ($shift['status'] !== 'open') {
                throw CashierRefused::closed();
            }

            if ($reference !== null && ($earlier = $this->shifts->dropByReference($property, $shift['id'], $reference)) !== null) {
                if ($earlier['amount_minor'] !== $amountMinor) {
                    throw Refusal::stateConflict('This drop reference was already used with a different amount.');
                }

                return;
            }

            $cash = $this->cashOnHand($property, $shift);

            if ($amountMinor > $cash) {
                throw CashierRefused::dropExceedsCash($cash);
            }

            $id = $this->ids->next();

            if ($this->shifts->addDrop($property, $shift['id'], $id, $amountMinor, $reference, $note, $actor, $this->clock->nowUtc()) === 'added') {
                $this->audit->record(new AuditEntry($property->toString(), $actor, 'cashier.drop.recorded', 'cashier_shift', $shift['id'], null, ['amount_minor' => $amountMinor, 'currency' => $shift['currency'], 'reference' => $reference], $note));
            }
        });

        return $this->describe($property, $this->shifts->find($property, strtolower($shiftId)) ?? throw Refusal::notFound('Shift not found.'));
    }

    /**
     * Closes the shift against the counted cash. A difference needs a reason. The person who opened it closes it; someone with
     * the manage privilege can close it for them, and that is recorded.
     *
     * @return array<string, mixed>
     */
    public function close(PropertyId $property, string $actorId, string $shiftId, int $countedMinor, ?string $reason, int $expectedLockVersion): array
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::OPERATE_PERMISSION, $property) && ! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not close cashier shifts.');
        }

        $actor = strtolower($actorId);
        $this->assertAmount($countedMinor, 0, 'The counted cash is zero or more.', 'counted_cash');
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if ($reason !== null && mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason is at most 300 characters.', ['variance_reason']);
        }

        $closed = $this->transactions->run(function () use ($property, $actor, $actorId, $shiftId, $countedMinor, $reason, $expectedLockVersion): array {
            $shift = $this->shifts->find($property, strtolower($shiftId)) ?? throw Refusal::notFound('Shift not found.');

            if ($shift['cashier_id'] !== $actor && ! $this->permissions->allowsInProperty($actorId, self::MANAGE_PERMISSION, $property)) {
                throw Refusal::forbidden('Only the person who opened the shift, or a supervisor, can close it.');
            }

            if ($shift['status'] !== 'open') {
                throw CashierRefused::closed();
            }

            $receipts = $this->shifts->receipts($property, $shift['id']);
            $drops = array_sum(array_column($this->shifts->drops($property, $shift['id']), 'amount_minor'));
            $expected = $shift['opening_float_minor'] + $this->cashNet($receipts) - $drops;
            $variance = $countedMinor - $expected;

            if ($variance !== 0 && $reason === null) {
                throw Refusal::invalid('The counted cash differs from what the system expects: say why.', ['variance_reason']);
            }

            $today = $this->businessDate->current($property);
            $totals = ['receipts' => $receipts, 'drops_minor' => $drops, 'opening_float_minor' => $shift['opening_float_minor']];

            if (! $this->shifts->close($property, $shift['id'], $expectedLockVersion, $actor, $this->clock->nowUtc(), $today->toString(), $expected, $countedMinor, $variance === 0 ? null : $reason, $totals)) {
                throw CashierRefused::stale();
            }

            $this->audit->record(new AuditEntry(
                $property->toString(), $actor, 'cashier.shift.closed', 'cashier_shift', $shift['id'],
                ['status' => 'open'], ['status' => 'closed', 'expected_cash_minor' => $expected, 'counted_cash_minor' => $countedMinor, 'variance_minor' => $variance, 'closed_for' => $shift['cashier_id'] === $actor ? null : $shift['cashier_id']],
                $variance === 0 ? null : $reason,
            ));
            $this->outbox->publish(new OutboxEvent($property, 'frontoffice.cashier.shift.closed', $shift['id'], 1, [
                'shift_id' => $shift['id'], 'number' => $shift['number'], 'cashier_id' => $shift['cashier_id'], 'expected_cash_minor' => $expected, 'counted_cash_minor' => $countedMinor, 'variance_minor' => $variance, 'currency' => $shift['currency'],
                'closed_business_date' => $today->toString(), 'opening_float_minor' => $shift['opening_float_minor'], 'drops_minor' => $drops, 'cash_net_minor' => $this->cashNet($receipts), 'receipts' => $receipts,
                'actor_id' => $actor,
            ]));

            return ['id' => $shift['id']];
        });

        return $this->describe($property, $this->shifts->find($property, $closed['id']) ?? throw Refusal::notFound('Shift not found.'));
    }

    /** @return array{require_open_shift: bool, lock_version: int} */
    public function updateSettings(PropertyId $property, string $actorId, bool $requireOpenShift, int $expectedLockVersion, string $reason): array
    {
        $this->authorize($property, $actorId, self::SETTINGS_PERMISSION);

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $before = $this->shifts->settings($property);

        $this->transactions->run(function () use ($property, $actorId, $requireOpenShift, $expectedLockVersion, $reason, $before): void {
            if (! $this->shifts->saveSettings($property, $requireOpenShift, $expectedLockVersion, strtolower($actorId), $this->clock->nowUtc())) {
                throw CashierRefused::stale();
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'cashier.settings.changed', 'cashier_settings', $property->toString(), ['require_open_shift' => $before['require_open_shift']], ['require_open_shift' => $requireOpenShift], trim($reason)));
        });

        return $this->shifts->settings($property);
    }

    // ---- internals ----

    /**
     * @param  array<string, mixed>  $shift
     * @return array<string, mixed>
     */
    private function describe(PropertyId $property, array $shift): array
    {
        $receipts = $shift['status'] === 'closed' ? ($shift['totals']['receipts'] ?? []) : $this->shifts->receipts($property, $shift['id']);
        $drops = $this->shifts->drops($property, $shift['id']);
        $dropped = array_sum(array_column($drops, 'amount_minor'));
        $names = $this->staff->namesOf($property, array_values(array_filter([$shift['cashier_id'], $shift['closed_by']])));

        return [
            ...$shift,
            'cashier_name' => $names[$shift['cashier_id']] ?? null,
            'closed_by_name' => $shift['closed_by'] === null ? null : ($names[$shift['closed_by']] ?? null),
            'receipts' => $receipts,
            'drops' => $drops,
            'drops_minor' => $dropped,
            'cash_net_minor' => $this->cashNet($receipts),
            'cash_on_hand_minor' => $shift['status'] === 'closed' ? $shift['counted_cash_minor'] : $shift['opening_float_minor'] + $this->cashNet($receipts) - $dropped,
        ];
    }

    /** @param array<string, mixed> $shift */
    private function cashOnHand(PropertyId $property, array $shift): int
    {
        return $shift['opening_float_minor'] + $this->cashNet($this->shifts->receipts($property, $shift['id'])) - array_sum(array_column($this->shifts->drops($property, $shift['id']), 'amount_minor'));
    }

    /** @param list<array{method: string, received_minor: int, paid_back_minor: int, count: int}> $receipts */
    private function cashNet(array $receipts): int
    {
        foreach ($receipts as $r) {
            if ($r['method'] === 'cash') {
                return $r['received_minor'] - $r['paid_back_minor'];
            }
        }

        return 0;
    }

    /** @param non-empty-string $field */
    private function assertAmount(int $value, int $min, string $message, string $field): void
    {
        if ($value < $min || $value > self::MAX_MINOR) {
            throw Refusal::invalid($message, [$field]);
        }
    }

    private function authorize(PropertyId $property, string $actorId, string $permission): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, $permission, $property)) {
            throw Refusal::forbidden('This person may not use the cashier shifts.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
