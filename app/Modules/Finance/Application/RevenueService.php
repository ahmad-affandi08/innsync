<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The revenue of the property as the night audit closed each day (FR-FIN-001, FR-FIN-002, FR-FIN-005). A day is a fact: its figures never change. The
 * daily report is those days over a range, by outlet and by payment method, with the monthly roll-up of a year. A day is verified by finance once the
 * cash of every shift closed on it was received and no exception of that cash is open; a verified day is final, and a later correction is a
 * reversal posted on a later day, not an edit.
 */
final readonly class RevenueService
{
    /** The longest range of the daily report, in days. */
    public const MAX_RANGE_DAYS = 93;

    public function __construct(
        private RevenueStore $store,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function report(PropertyId $property, string $actorId, ?string $from, ?string $to): array
    {
        $this->access->requireRevenueView($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $to = $to === null || $to === '' ? $today : $this->date($to, 'to');
        $from = $from === null || $from === '' ? substr($to, 0, 8).'01' : $this->date($from, 'from');

        if ($from > $to) {
            throw Refusal::invalid('The start of the range is after its end.', ['from']);
        }

        if ($this->daysBetween($from, $to) >= self::MAX_RANGE_DAYS) {
            throw Refusal::invalid('Choose a range of at most '.self::MAX_RANGE_DAYS.' days; the monthly roll-up covers a whole year.', ['to']);
        }

        $days = array_map($this->dayShape(...), $this->store->days($property, $from, $to));
        $totals = $this->sum($days);

        return [
            'from' => $from, 'to' => $to, 'today' => $today, 'days' => $days, 'totals' => $totals,
            'outlets' => $this->outlets($this->store->lineTotals($property, $from, $to)),
            'methods' => array_map(static fn (array $m): array => ['method' => $m['method'], 'received_minor' => (int) $m['received_minor'], 'paid_back_minor' => (int) $m['paid_back_minor'], 'net_minor' => (int) $m['received_minor'] - (int) $m['paid_back_minor'], 'entries' => (int) $m['entries']], $this->store->paymentTotals($property, $from, $to)),
            'unverified' => count(array_filter($days, static fn (array $d): bool => $d['status'] !== 'verified')),
            'may' => ['verify' => $this->access->may($property, $actorId, FinanceAccess::RECONCILE)],
        ];
    }

    /** @return array<string, mixed> the months of a year with their totals and the outlets of each */
    public function monthly(PropertyId $property, string $actorId, ?int $year): array
    {
        $this->access->requireRevenueView($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $year ??= (int) substr($today, 0, 4);

        if ($year < 2000 || $year > 2100) {
            throw Refusal::invalid('Give a year between 2000 and 2100.', ['year']);
        }

        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $months[sprintf('%04d-%02d', $year, $m)] = ['month' => sprintf('%04d-%02d', $year, $m), 'days' => 0, 'base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'collected_minor' => 0, 'outlets' => []];
        }

        foreach ($this->store->days($property, $from, $to) as $d) {
            $key = substr((string) $d['business_date'], 0, 7);

            foreach (['base_minor', 'service_charge_minor', 'tax_minor', 'total_minor', 'collected_minor'] as $field) {
                $months[$key][$field] += (int) $d[$field];
            }

            $months[$key]['days']++;
        }

        foreach ($this->store->lineTotals($property, $from, $to) as $line) {
            $key = substr((string) $line['business_date'], 0, 7);
            $code = (string) $line['outlet_code'];
            $o = $months[$key]['outlets'][$code] ?? ['code' => $code, 'name' => $line['outlet_name'], 'base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0];

            foreach (['base_minor', 'service_charge_minor', 'tax_minor', 'total_minor'] as $field) {
                $o[$field] += (int) $line[$field];
            }

            $months[$key]['outlets'][$code] = $o;
        }

        $list = array_map(static fn (array $m): array => [...$m, 'outlets' => array_values($m['outlets'])], array_values($months));

        return ['year' => $year, 'months' => $list, 'totals' => $this->sum($list)];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $date): array
    {
        $this->access->requireRevenueView($property, $actorId);
        $day = $this->store->day($property, $this->date($date, 'date')) ?? throw Refusal::notFound('No revenue was booked for this date.');
        $blockers = $this->store->dayBlockers($property, substr((string) $day['business_date'], 0, 10));
        $names = $this->staff->namesOf($property, array_values(array_filter([$day['verified_by'] ?? null, $day['actor_id'] ?? null])));

        return [
            ...$this->dayShape($day),
            'lines' => array_map(static fn (array $l): array => ['source' => $l['source'], 'outlet_code' => $l['outlet_code'], 'outlet_name' => $l['outlet_name'], 'base_minor' => (int) $l['base_minor'], 'service_charge_minor' => (int) $l['service_charge_minor'], 'tax_minor' => (int) $l['tax_minor'], 'total_minor' => (int) $l['total_minor']], $day['lines']),
            'payments' => array_map(static fn (array $p): array => ['method' => $p['method'], 'received_minor' => (int) $p['received_minor'], 'paid_back_minor' => (int) $p['paid_back_minor'], 'net_minor' => (int) $p['received_minor'] - (int) $p['paid_back_minor'], 'entries' => (int) $p['entries']], $day['payments']),
            'night_audit_id' => $day['night_audit_id'], 'occurred_at' => $this->iso((string) $day['occurred_at']), 'booked_by' => $names[$day['actor_id'] ?? ''] ?? null,
            'verified_by' => $day['verified_by'] === null ? null : ($names[$day['verified_by']] ?? null), 'verification_note' => $day['verification_note'],
            'blockers' => $blockers, 'may_verify' => $this->access->may($property, $actorId, FinanceAccess::RECONCILE) && $day['status'] !== 'verified' && $blockers['waiting'] === 0 && $blockers['open'] === 0,
            'may' => ['verify' => $this->access->may($property, $actorId, FinanceAccess::RECONCILE)],
        ];
    }

    /** @return array<string, mixed> */
    public function verify(PropertyId $property, string $actorId, string $date, ?string $note): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECONCILE, 'This person may not verify revenue days.');
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 300) {
            throw Refusal::invalid('The note is at most 300 characters.', ['note']);
        }

        $date = $this->date($date, 'date');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $date, $note): void {
            $day = $this->store->day($property, $date) ?? throw Refusal::notFound('No revenue was booked for this date.');
            $this->store->lockDay($property, $day['id']);
            $day = $this->store->day($property, $date) ?? throw Refusal::notFound('No revenue was booked for this date.');

            if ($day['status'] === 'verified') {
                throw Refusal::stateConflict('This day was verified already.');
            }

            $blockers = $this->store->dayBlockers($property, $date);

            if ($blockers['waiting'] > 0 || $blockers['open'] > 0) {
                throw Refusal::stateConflict('The day cannot be verified: '.$blockers['waiting'].' shift(s) closed on it have no cash received and '.$blockers['open'].' cash exception(s) are open.');
            }

            $this->store->verifyDay($property, $day['id'], $actor, $note, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'revenue_day.verified', 'revenue_day', $day['id'], ['status' => 'recorded'], ['status' => 'verified', 'business_date' => $date, 'total_minor' => (int) $day['total_minor'], 'collected_minor' => (int) $day['collected_minor']], $note));
            $this->outbox->publish(new OutboxEvent($property, 'finance.revenue.day_verified', $day['id'], 1, ['day_id' => $day['id'], 'business_date' => $date, 'total_minor' => (int) $day['total_minor'], 'collected_minor' => (int) $day['collected_minor'], 'currency' => $day['currency'], 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $date);
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    private function dayShape(array $d): array
    {
        return [
            'id' => $d['id'], 'date' => substr((string) $d['business_date'], 0, 10), 'currency' => $d['currency'], 'base_minor' => (int) $d['base_minor'], 'service_charge_minor' => (int) $d['service_charge_minor'], 'tax_minor' => (int) $d['tax_minor'],
            'total_minor' => (int) $d['total_minor'], 'collected_minor' => (int) $d['collected_minor'], 'status' => $d['status'], 'verified_at' => $d['verified_at'] === null ? null : $this->iso((string) $d['verified_at']),
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, int> */
    private function sum(array $rows): array
    {
        $sum = ['base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0, 'collected_minor' => 0];

        foreach ($rows as $r) {
            foreach ($sum as $key => $value) {
                $sum[$key] = $value + (int) $r[$key];
            }
        }

        return $sum;
    }

    /** @param list<array<string, mixed>> $lines @return list<array<string, mixed>> */
    private function outlets(array $lines): array
    {
        $outlets = [];

        foreach ($lines as $l) {
            $code = (string) $l['outlet_code'];
            $o = $outlets[$code] ?? ['code' => $code, 'name' => $l['outlet_name'], 'base_minor' => 0, 'service_charge_minor' => 0, 'tax_minor' => 0, 'total_minor' => 0];

            foreach (['base_minor', 'service_charge_minor', 'tax_minor', 'total_minor'] as $field) {
                $o[$field] += (int) $l[$field];
            }

            $outlets[$code] = $o;
        }

        ksort($outlets);

        return array_values($outlets);
    }

    private function date(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $value;
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) (new DateTimeImmutable($from, new DateTimeZone('UTC')))->diff(new DateTimeImmutable($to, new DateTimeZone('UTC')))->days;
    }

    private function iso(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
