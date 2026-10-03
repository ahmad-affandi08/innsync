<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
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
 * The cash of a closed cashier shift against the cash finance receives for it (FR-FIN-003, FR-FIN-037). The system holds what the shift took in; finance
 * counts what is handed over and records it once, never for its own shift. A difference needs a reason and stays an exception until someone other than the
 * receiver settles it as explained, recovered or waived with a note. Nothing is edited: the receipt and the settlement are each written once.
 */
final readonly class CashReconciliationService
{
    public const SETTLEMENTS = ['explained', 'recovered', 'waived'];

    private const MAX_MINOR = 9_000_000_000_000;

    public function __construct(
        private RevenueStore $store,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private IdempotentExecutor $executor,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $from, ?string $to, ?string $only): array
    {
        $this->access->requireRevenueView($property, $actorId);

        if ($only !== null && $only !== '' && ! in_array($only, ['waiting', 'received'], true)) {
            throw Refusal::invalid('Choose waiting or received.', ['only']);
        }

        $from = $this->optionalDate($from, 'from');
        $to = $this->optionalDate($to, 'to');
        $shifts = $this->store->cashShifts($property, $from, $to, $only === '' ? null : $only, 300);
        $exceptions = $this->store->exceptions($property, null);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([...array_column($shifts, 'cashier_id'), ...array_column($shifts, 'received_by'), ...array_column($exceptions, 'cashier_id'), ...array_column($exceptions, 'received_by'), ...array_column($exceptions, 'resolved_by')]))));
        $actor = strtolower($actorId);
        $may = $this->access->may($property, $actorId, FinanceAccess::RECONCILE);

        return [
            'shifts' => array_map(fn (array $s): array => $this->shiftShape($s, $names, $actor, $may), $shifts),
            'exceptions' => array_map(fn (array $e): array => $this->exceptionShape($e, $names, $actor, $may), $exceptions),
            'waiting' => count(array_filter($shifts, static fn (array $s): bool => $s['deposit_id'] === null)),
            'open_exceptions' => $this->store->openExceptionCount($property), 'settlements' => self::SETTLEMENTS, 'may' => ['reconcile' => $may],
        ];
    }

    /**
     * Records the cash received for a closed shift. The person who closed or worked the shift does not receive its cash.
     *
     * @return array<string, mixed>
     */
    public function receive(PropertyId $property, string $actorId, string $cashShiftId, int $depositedMinor, ?string $reason, ?string $note, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECONCILE, 'This person may not receive the cash of cashier shifts.');

        if ($depositedMinor < 0 || $depositedMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give the cash counted, zero or more.', ['deposited_minor']);
        }

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (($reason !== null && mb_strlen($reason) > 300) || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('The reason is at most 300 characters and the note at most 200.', ['reason']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();
        $shiftId = strtolower($cashShiftId);

        $operation = function () use ($property, $actor, $id, $shiftId, $depositedMinor, $reason, $note): void {
            $this->store->lockCashShift($property, $shiftId);
            $shift = $this->store->cashShift($property, $shiftId) ?? throw Refusal::notFound('Shift not found.');

            if ($shift['deposit_id'] !== null) {
                throw Refusal::stateConflict('The cash of this shift was received already.');
            }

            if ($shift['cashier_id'] === $actor || $shift['closed_by'] === $actor) {
                throw Refusal::forbidden('The cash of a shift is received by someone other than the person who worked or closed it.');
            }

            $expected = (int) $shift['cash_net_minor'];
            $variance = $depositedMinor - $expected;

            if ($variance !== 0 && $reason === null) {
                throw Refusal::invalid('The cash received differs from what the system holds: say why.', ['reason']);
            }

            $number = $this->numbers->next($property, 'DEP');
            $today = $this->businessDate->current($property)->toString();
            $now = $this->clock->nowUtc();

            if (! $this->store->addDeposit($property, ['id' => $id, 'number' => $number, 'cash_shift_id' => $shift['id'], 'deposited_minor' => $depositedMinor, 'expected_minor' => $expected, 'variance_minor' => $variance, 'reason' => $variance === 0 ? null : $reason, 'note' => $note, 'business_date' => $today, 'received_by' => $actor], $now)) {
                throw Refusal::stateConflict('The cash of this shift was received already.');
            }

            if ($variance !== 0) {
                $this->store->addException($property, ['id' => $this->ids->next(), 'deposit_id' => $id, 'variance_minor' => $variance], $now);
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'cash_deposit.recorded', 'cash_deposit', $id, null, ['number' => $number, 'shift' => $shift['number'], 'cashier_id' => $shift['cashier_id'], 'expected_minor' => $expected, 'deposited_minor' => $depositedMinor, 'variance_minor' => $variance], $variance === 0 ? null : $reason));
            $this->outbox->publish(new OutboxEvent($property, 'finance.cash.received', $id, 1, ['deposit_id' => $id, 'number' => $number, 'shift_id' => $shift['shift_id'], 'cashier_id' => $shift['cashier_id'], 'expected_minor' => $expected, 'deposited_minor' => $depositedMinor, 'variance_minor' => $variance, 'currency' => $shift['currency'], 'business_date' => $today, 'actor_id' => $actor]));
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $once = $this->executor->execute(
                new IdempotencyRequest($property, $key, 'finance.cash.receive', ['shift' => $shiftId, 'deposited' => $depositedMinor, 'reason' => $reason], $actor),
                function () use ($operation, $id): array {
                    $operation();

                    return ['id' => $id];
                },
            );
            $id = (string) $once->payload['id'];
        }

        $names = $this->staff->namesOf($property, [$actor]);
        $shift = $this->store->cashShift($property, $shiftId) ?? throw Refusal::notFound('Shift not found.');

        return $this->shiftShape($shift, $names, $actor, true);
    }

    /** @return array<string, mixed> */
    public function settle(PropertyId $property, string $actorId, string $exceptionId, string $status, string $resolution, int $expectedLockVersion): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECONCILE, 'This person may not settle cash exceptions.');
        $resolution = trim($resolution);

        if (! in_array($status, self::SETTLEMENTS, true)) {
            throw Refusal::invalid('Choose explained, recovered or waived.', ['status']);
        }

        if ($resolution === '' || mb_strlen($resolution) > 300) {
            throw Refusal::invalid('Say how it was settled, in at most 300 characters.', ['resolution']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $exceptionId, $status, $resolution, $expectedLockVersion): void {
            $e = $this->store->exception($property, strtolower($exceptionId)) ?? throw Refusal::notFound('Exception not found.');

            if ($e['status'] !== 'open') {
                throw Refusal::stateConflict('This exception was settled already.');
            }

            if ($e['received_by'] === $actor) {
                throw Refusal::forbidden('A cash difference is settled by someone other than the person who received the cash.');
            }

            if (! $this->store->settleException($property, $e['id'], $expectedLockVersion, $status, $resolution, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This exception changed meanwhile. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'cash_exception.settled', 'cash_exception', $e['id'], ['status' => 'open'], ['status' => $status, 'variance_minor' => (int) $e['variance_minor'], 'deposit' => $e['deposit_number'], 'shift' => $e['shift_number']], $resolution));
            $this->outbox->publish(new OutboxEvent($property, 'finance.cash.exception_settled', $e['id'], 1, ['exception_id' => $e['id'], 'deposit_id' => $e['deposit_id'], 'variance_minor' => (int) $e['variance_minor'], 'status' => $status, 'currency' => $e['currency'], 'actor_id' => $actor]));
        });

        $names = $this->staff->namesOf($property, [$actor]);

        return $this->exceptionShape($this->store->exception($property, strtolower($exceptionId)) ?? throw Refusal::notFound('Exception not found.'), $names, $actor, true);
    }

    /** @param array<string, mixed> $s @param array<string, string> $names @return array<string, mixed> */
    private function shiftShape(array $s, array $names, string $actor, bool $may): array
    {
        $received = $s['deposit_id'] !== null;

        return [
            'id' => $s['id'], 'number' => $s['number'], 'cashier_id' => $s['cashier_id'], 'cashier_name' => $names[$s['cashier_id']] ?? null, 'closed_on' => substr((string) $s['closed_business_date'], 0, 10), 'currency' => $s['currency'],
            'opening_float_minor' => (int) $s['opening_float_minor'], 'expected_cash_minor' => (int) $s['expected_cash_minor'], 'counted_cash_minor' => (int) $s['counted_cash_minor'], 'shift_variance_minor' => (int) $s['shift_variance_minor'],
            'drops_minor' => (int) $s['drops_minor'], 'cash_net_minor' => (int) $s['cash_net_minor'],
            // What the cashier declared to hand over: the drawer less the float that stays, plus what was dropped into the safe during the shift.
            'declared_minor' => (int) $s['counted_cash_minor'] - (int) $s['opening_float_minor'] + (int) $s['drops_minor'],
            'received' => $received, 'deposit' => $received ? [
                'id' => $s['deposit_id'], 'number' => $s['deposit_number'], 'deposited_minor' => (int) $s['deposited_minor'], 'variance_minor' => (int) $s['deposit_variance_minor'], 'reason' => $s['deposit_reason'], 'note' => $s['deposit_note'],
                'received_by' => $names[$s['received_by']] ?? null, 'received_on' => substr((string) $s['deposit_date'], 0, 10), 'exception_status' => $s['exception_status'],
            ] : null,
            'may_receive' => $may && ! $received && $s['cashier_id'] !== $actor && $s['closed_by'] !== $actor,
        ];
    }

    /** @param array<string, mixed> $e @param array<string, string> $names @return array<string, mixed> */
    private function exceptionShape(array $e, array $names, string $actor, bool $may): array
    {
        return [
            'id' => $e['id'], 'status' => $e['status'], 'variance_minor' => (int) $e['variance_minor'], 'currency' => $e['currency'], 'deposit_number' => $e['deposit_number'], 'shift_number' => $e['shift_number'],
            'cashier_name' => $names[$e['cashier_id']] ?? null, 'received_by' => $names[$e['received_by']] ?? null, 'reason' => $e['reason'], 'closed_on' => substr((string) $e['closed_business_date'], 0, 10),
            'resolution' => $e['resolution'], 'resolved_by' => $e['resolved_by'] === null ? null : ($names[$e['resolved_by']] ?? null), 'resolved_at' => $e['resolved_at'] === null ? null : (new DateTimeImmutable((string) $e['resolved_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'lock_version' => (int) $e['lock_version'], 'may_settle' => $may && $e['status'] === 'open' && $e['received_by'] !== $actor,
        ];
    }

    private function optionalDate(?string $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $value;
    }
}
