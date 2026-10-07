<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The clock-ins that look unusual, in front of a supervisor (owner request 2026-10-07: stronger attendance). `AttendanceAnomalies` marks them; here a person with the attendance
 * privilege looks at the selfie and the marks and answers once: it is fine, or it is questioned with a note (the follow-up is the ordinary correction, which needs an approver).
 * The answer is never changed, is audited, and a person does not answer about their own clock-in.
 */
final readonly class AttendanceReviewService
{
    /** How many days back the list goes; the marks are worked out over a longer stretch so repeats and shared phones are seen. */
    public const LIST_DAYS = 14;

    public const CONTEXT_DAYS = 45;

    public function __construct(
        private AttendanceStore $store,
        private EmployeeStore $employees,
        private HrAccess $access,
        private PropertyTimeZoneReader $zones,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * @return list<array{attendance_id: string, side: string, employee_id: string, employee_name: string, employee_number: string, work_date: string, at: string, flags: list<string>, strong: bool, has_photo: bool}>
     */
    public function queue(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not review clock-ins.');

        return array_values(array_map(
            static fn (array $i): array => array_diff_key($i, ['key' => 1, 'reviewed' => 1, 'in_list' => 1]),
            array_filter($this->items($property), static fn (array $i): bool => $i['reviewed'] === false && $i['in_list']),
        ));
    }

    public function decide(PropertyId $property, string $actorId, string $attendanceId, string $side, string $decision, ?string $note): void
    {
        $this->access->assertProperty($property);
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not review clock-ins.');
        $note = $note === null ? null : trim($note);

        if (! in_array($side, ['in', 'out'], true) || ! in_array($decision, ['ok', 'questioned'], true)) {
            throw Refusal::invalid('Choose whether the clock-in is fine or questioned.', ['decision']);
        }

        if ($decision === 'questioned' && ($note === null || $note === '')) {
            throw Refusal::invalid('Say what looks wrong, so the follow-up knows where to start.', ['note']);
        }

        if ($note !== null && mb_strlen($note) > 300) {
            throw Refusal::invalid('A note of at most 300 characters.', ['note']);
        }

        $key = strtolower($attendanceId).':'.$side;
        $item = null;

        foreach ($this->items($property) as $candidate) {
            if ($candidate['key'] === $key) {
                $item = $candidate;
            }
        }

        if ($item === null) {
            throw Refusal::notFound('This clock-in has nothing to review.');
        }

        if ($item['reviewed']) {
            throw Refusal::stateConflict('This clock-in already has an answer.');
        }

        if ($this->employees->employeeOfUser($property, strtolower($actorId)) === $item['employee_id']) {
            throw Refusal::forbidden('A person does not answer about their own clock-in. Ask another supervisor.');
        }

        $now = $this->clock->nowUtc();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $item, $attendanceId, $side, $decision, $note, $now): void {
            if (! $this->store->addReview($property, $this->ids->next(), strtolower($attendanceId), $side, $item['flags'], $decision, $note === '' ? null : $note, $actor, $now)) {
                throw Refusal::stateConflict('This clock-in already has an answer.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'attendance.reviewed', 'attendance', strtolower($attendanceId), null,
                ['employee' => $item['employee_number'], 'side' => $side, 'decision' => $decision, 'flags' => $item['flags']], $note === '' ? null : $note));
        });
    }

    /** @return list<array<string, mixed>> every marked clock-in of the context period, with whether it was answered and whether it is within the list's days */
    private function items(PropertyId $property): array
    {
        $zone = $this->zones->forProperty($property) ?? throw Refusal::invalid('The property has no time zone.');
        $today = $zone->calendarDateAt($this->clock->nowUtc())->toString();
        $rows = $this->store->between($property, date('Y-m-d', strtotime($today.' -'.self::CONTEXT_DAYS.' days')), $today, null);
        $radius = (int) ($this->store->settings($property)['radius_m'] ?? AttendanceService::BASELINE['radius_m']);
        $marks = AttendanceAnomalies::flag($rows, $radius);

        if ($marks === []) {
            return [];
        }

        $byId = [];
        foreach ($rows as $r) {
            $byId[(string) $r['id']] = $r;
        }

        $reviews = $this->store->reviews($property, array_keys($byId));
        $people = [];
        foreach ($this->employees->employees($property, null) as $e) {
            $people[(string) $e['id']] = $e;
        }

        $from = date('Y-m-d', strtotime($today.' -'.self::LIST_DAYS.' days'));
        $items = [];

        foreach ($marks as $key => $flags) {
            [$id, $side] = explode(':', $key);
            $r = $byId[$id];
            $e = $people[(string) $r['employee_id']] ?? null;
            $items[] = [
                'key' => $key, 'attendance_id' => $id, 'side' => $side, 'employee_id' => (string) $r['employee_id'], 'employee_name' => (string) ($e['full_name'] ?? '—'), 'employee_number' => (string) ($e['number'] ?? ''),
                'work_date' => substr((string) $r['work_date'], 0, 10), 'at' => (string) $r[$side.'_at'], 'flags' => $flags, 'strong' => array_intersect($flags, AttendanceAnomalies::STRONG) !== [],
                'has_photo' => ($r[$side.'_photo_file_id'] ?? null) !== null, 'reviewed' => isset($reviews[$key]), 'in_list' => substr((string) $r['work_date'], 0, 10) >= $from,
            ];
        }

        usort($items, static fn (array $a, array $b): int => [$b['strong'], $b['at']] <=> [$a['strong'], $a['at']]);

        return $items;
    }
}
