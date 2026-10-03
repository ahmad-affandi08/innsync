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
 * Corrections to a booked revenue day (FR-FIN-036). A booked day, verified or not, never changes; a correction is a journal that points at the day, adds to or takes
 * from the revenue of an outlet (base, service charge and tax apart) or from what was collected by a method, and says why. Finance asks for it; a different person
 * with the right to approve decides it. Approved, it takes effect on the business date of the approval, not on the day it corrects, so a verified day stays as it was
 * verified and the correction is seen, and reported, in the open period. A rejected correction changes nothing, and a wrong correction is itself corrected by another.
 */
final readonly class CorrectionService
{
    public const METHODS = ['cash', 'qris', 'card', 'bank_transfer', 'online'];

    public const MAX_LINES = 20;

    private const MAX_MINOR = 9_000_000_000_000;

    public function __construct(
        private CorrectionStore $store,
        private RevenueStore $revenue,
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
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $this->access->requireCorrectionView($property, $actorId);

        if ($status !== null && $status !== '' && ! in_array($status, ['pending', 'approved', 'rejected'], true)) {
            throw Refusal::invalid('Choose pending, approved or rejected.', ['status']);
        }

        $rows = $this->store->corrections($property, $status === '' ? null : $status, null);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([...array_column($rows, 'requested_by'), ...array_column($rows, 'decided_by')]))));

        $outlets = [];

        foreach ($this->revenue->lineTotals($property, '2000-01-01', '2100-12-31') as $l) {
            $outlets[(string) $l['outlet_code']] = ['code' => (string) $l['outlet_code'], 'name' => $l['outlet_name']];
        }

        ksort($outlets);

        return [
            'corrections' => array_map(fn (array $c): array => $this->head($c, $names), $rows), 'methods' => self::METHODS, 'outlets' => array_values($outlets),
            'may' => ['request' => $this->access->may($property, $actorId, FinanceAccess::RECONCILE), 'decide' => $this->access->may($property, $actorId, FinanceAccess::CORRECTION_APPROVE)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireCorrectionView($property, $actorId);
        $c = $this->store->correction($property, strtolower($id)) ?? throw Refusal::notFound('Correction not found.');
        $names = $this->staff->namesOf($property, array_values(array_filter([$c['requested_by'], $c['decided_by']])));
        $actor = strtolower($actorId);

        return [
            ...$this->head([...$c, 'line_count' => count($c['lines']), 'revenue_minor' => array_sum(array_map(static fn (array $l): int => (int) $l['total_minor'], $c['lines'])), 'received_minor' => array_sum(array_map(static fn (array $l): int => (int) $l['received_minor'], $c['lines']))], $names),
            'decision_note' => $c['decision_note'], 'lock_version' => (int) $c['lock_version'],
            'lines' => array_map(static fn (array $l): array => ['kind' => $l['kind'], 'outlet_code' => $l['outlet_code'], 'outlet_name' => $l['outlet_name'], 'method' => $l['method'], 'base_minor' => (int) $l['base_minor'], 'service_charge_minor' => (int) $l['service_charge_minor'], 'tax_minor' => (int) $l['tax_minor'], 'total_minor' => (int) $l['total_minor'], 'received_minor' => (int) $l['received_minor']], $c['lines']),
            'may_decide' => $c['status'] === 'pending' && $this->access->may($property, $actorId, FinanceAccess::CORRECTION_APPROVE) && $c['requested_by'] !== $actor,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines  revenue lines `{kind: 'revenue', outlet_code, base_minor, service_charge_minor, tax_minor}` and payment lines `{kind: 'payment', method, received_minor}`
     * @return array<string, mixed>
     */
    public function request(PropertyId $property, string $actorId, string $date, string $reason, array $lines, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECONCILE, 'This person may not ask for corrections.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('Say why the day is corrected, in at most 300 characters.', ['reason']);
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw Refusal::invalid('Give one to '.self::MAX_LINES.' lines.', ['lines']);
        }

        $day = $this->revenue->day($property, $this->date($date, 'date')) ?? throw Refusal::notFound('No revenue was booked for this date.');
        $names = [];

        foreach ($day['lines'] as $l) {
            $names[(string) $l['outlet_code']] = $l['outlet_name'];
        }

        $rows = [];
        $id = $this->ids->next();

        foreach ($lines as $line) {
            $kind = (string) ($line['kind'] ?? '');

            if ($kind === 'revenue') {
                $code = trim((string) ($line['outlet_code'] ?? ''));
                [$base, $service, $tax] = [(int) ($line['base_minor'] ?? 0), (int) ($line['service_charge_minor'] ?? 0), (int) ($line['tax_minor'] ?? 0)];

                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,19}$/D', $code) !== 1) {
                    throw Refusal::invalid('Choose the outlet of each revenue line.', ['lines']);
                }

                foreach ([$base, $service, $tax] as $v) {
                    if (abs($v) > self::MAX_MINOR) {
                        throw Refusal::invalid('An amount is more than nine trillion.', ['lines']);
                    }
                }

                if ($base + $service + $tax === 0) {
                    throw Refusal::invalid('A revenue line changes the revenue: its parts cannot add up to zero.', ['lines']);
                }

                $rows[] = ['id' => $this->ids->next(), 'kind' => 'revenue', 'outlet_code' => $code, 'outlet_name' => $names[$code] ?? null, 'method' => null, 'base_minor' => $base, 'service_charge_minor' => $service, 'tax_minor' => $tax, 'total_minor' => $base + $service + $tax, 'received_minor' => 0];
            } elseif ($kind === 'payment') {
                $method = (string) ($line['method'] ?? '');
                $received = (int) ($line['received_minor'] ?? 0);

                if (! in_array($method, self::METHODS, true)) {
                    throw Refusal::invalid('Choose the payment method of each payment line.', ['lines']);
                }

                if ($received === 0 || abs($received) > self::MAX_MINOR) {
                    throw Refusal::invalid('A payment line takes an amount that is not zero.', ['lines']);
                }

                $rows[] = ['id' => $this->ids->next(), 'kind' => 'payment', 'outlet_code' => null, 'outlet_name' => null, 'method' => $method, 'base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'received_minor' => $received];
            } else {
                throw Refusal::invalid('A line is for revenue or for a payment.', ['lines']);
            }
        }

        $actor = strtolower($actorId);

        $operation = function () use ($property, $actor, $id, $day, $reason, $rows): void {
            $number = $this->numbers->next($property, 'COR');
            $now = $this->clock->nowUtc();

            if (! $this->store->add($property, ['id' => $id, 'number' => $number, 'day_id' => $day['id'], 'business_date' => substr((string) $day['business_date'], 0, 10), 'reason' => $reason, 'requested_by' => $actor, 'requested_at' => $now->format('Y-m-d H:i:s.u')], $rows, $now)) {
                throw Refusal::stateConflict('A correction with this number already exists. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'correction.requested', 'correction', $id, null, ['number' => $number, 'day' => substr((string) $day['business_date'], 0, 10), 'lines' => count($rows), 'revenue_minor' => array_sum(array_column($rows, 'total_minor')), 'received_minor' => array_sum(array_column($rows, 'received_minor'))], $reason));
            $this->outbox->publish(new OutboxEvent($property, 'finance.correction.requested', $id, 1, ['correction_id' => $id, 'number' => $number, 'business_date' => substr((string) $day['business_date'], 0, 10), 'actor_id' => $actor]));
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $this->executor->execute(new IdempotencyRequest($property, $key, 'finance.correction.request', ['date' => substr((string) $day['business_date'], 0, 10), 'reason' => $reason, 'lines' => $rows === [] ? [] : array_map(static fn (array $r): array => array_diff_key($r, ['id' => 1]), $rows)], $actor), function () use ($operation, $id): array {
                $operation();

                return ['id' => $id];
            });
        }

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function decide(PropertyId $property, string $actorId, string $id, bool $approve, ?string $note, int $expectedLockVersion): array
    {
        $this->access->require($property, $actorId, FinanceAccess::CORRECTION_APPROVE, 'This person may not approve corrections.');
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! $approve && $note === null) {
            throw Refusal::invalid('Say why the correction is rejected.', ['note']);
        }

        if ($note !== null && mb_strlen($note) > 300) {
            throw Refusal::invalid('The note is at most 300 characters.', ['note']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $approve, $note, $expectedLockVersion): void {
            $this->store->lock($property, strtolower($id));
            $c = $this->store->correction($property, strtolower($id)) ?? throw Refusal::notFound('Correction not found.');

            if ($c['status'] !== 'pending') {
                throw Refusal::stateConflict('This correction was decided already.');
            }

            if ($c['requested_by'] === $actor) {
                throw Refusal::forbidden('A correction is approved by someone other than the person who asked for it.');
            }

            $effective = $approve ? $this->businessDate->current($property)->toString() : null;

            if (! $this->store->decide($property, $c['id'], $expectedLockVersion, $approve ? 'approved' : 'rejected', $actor, $note, $effective, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This correction changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $approve ? 'correction.approved' : 'correction.rejected', 'correction', $c['id'], ['status' => 'pending'], ['status' => $approve ? 'approved' : 'rejected', 'number' => $c['number'], 'day' => substr((string) $c['business_date'], 0, 10), 'effective_date' => $effective], $note));
            $this->outbox->publish(new OutboxEvent($property, $approve ? 'finance.correction.approved' : 'finance.correction.rejected', $c['id'], 1, ['correction_id' => $c['id'], 'number' => $c['number'], 'business_date' => substr((string) $c['business_date'], 0, 10), 'effective_date' => $effective, 'revenue_minor' => array_sum(array_map(static fn (array $l): int => (int) $l['total_minor'], $c['lines'])), 'received_minor' => array_sum(array_map(static fn (array $l): int => (int) $l['received_minor'], $c['lines'])), 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @param array<string, mixed> $c @param array<string, string> $names @return array<string, mixed> */
    private function head(array $c, array $names): array
    {
        return [
            'id' => $c['id'], 'number' => $c['number'], 'status' => $c['status'], 'day_date' => substr((string) $c['business_date'], 0, 10), 'reason' => $c['reason'], 'line_count' => (int) $c['line_count'], 'revenue_minor' => (int) $c['revenue_minor'], 'received_minor' => (int) $c['received_minor'],
            'requested_by' => $names[$c['requested_by']] ?? null, 'requested_at' => (new DateTimeImmutable((string) $c['requested_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'decided_by' => ($c['decided_by'] ?? null) === null ? null : ($names[$c['decided_by']] ?? null),
            'effective_date' => ($c['effective_date'] ?? null) === null ? null : substr((string) $c['effective_date'], 0, 10),
        ];
    }

    private function date(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $value;
    }
}
