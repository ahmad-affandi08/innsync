<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Files\FilePolicy;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\FileUpload;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Files\StoredFileRepository;
use App\Shared\Application\Files\StoreFile;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Retention\RetentionPolicies;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\PropertyTimeZone;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Attendance (FR-HR-012, -013, -014). A person clocks in and out for the shift the roster plans for them, with their own account: from the phone, within the distance of the property
 * the owner set (when one is set) and, when the owner asks, with a selfie. A supervisor with the privilege may record a clock-in or clock-out that did not happen on the phone, with a
 * reason. Lateness, early leaving, extra hours and absence are not stored: they are worked out from the roster and these records whenever they are read, so a correction changes them all.
 * The position of a person is not kept, only how far from the property it was.
 */
final readonly class AttendanceService
{
    public const BASELINE = ['radius_m' => 100, 'require_selfie' => false, 'late_grace' => 10, 'early_grace' => 10, 'extra_after' => 30];

    public const MAX_DAYS = 62;

    public const MANUAL_DAYS_BACK = 7;

    /** Minutes before a shift starts that a clock-in is taken, and after it ends that a clock-out is. */
    private const IN_BEFORE = 180;

    private const OUT_AFTER = 240;

    public const PHOTO_PURPOSE = 'hr_attendance_photo';

    public const PHOTO_MAX_BYTES = 3_145_728;

    public function __construct(
        private AttendanceStore $store,
        private RosterStore $roster,
        private OvertimeStore $overtime,
        private EmployeeStore $employees,
        private HrAccess $access,
        private PropertyTimeZoneReader $zones,
        private BusinessDateProvider $businessDate,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private StoredFileRepository $files,
        private RetentionPolicies $retention,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $date, ?string $from, ?string $to, ?string $department): array
    {
        $this->access->assertProperty($property);
        $manage = $this->access->may($property, $actorId, HrAccess::ATTENDANCE);
        $mine = $this->employees->employeeOfUser($property, strtolower($actorId));

        if (! $manage && $mine === null) {
            throw Refusal::forbidden('This person has no employee record and may not see attendance.');
        }

        $tz = $this->zone($property);
        $now = $this->clock->nowUtc();
        $today = $tz->calendarDateAt($now)->toString();
        $out = ['now' => $now->format('Y-m-d\TH:i:s\Z'), 'today' => $today, 'settings' => $this->settings($property), 'me' => $mine === null ? null : $this->me($property, $mine, $tz, $now), 'may' => ['manage' => $manage]];

        if (! $manage) {
            return $out + ['day' => null, 'summary' => null, 'departments' => EmployeeService::DEPARTMENTS];
        }

        $date = $date === null || $date === '' ? $today : $date;
        $from = $from === null || $from === '' ? date('Y-m-d', strtotime($today.' -6 days')) : $from;
        $to = $to === null || $to === '' ? $today : $to;
        $department = $department === '' ? null : $department;

        if (! $this->isDate($date) || ! $this->isDate($from) || ! $this->isDate($to) || $to < $from || (strtotime($to) - strtotime($from)) / 86400 >= self::MAX_DAYS) {
            throw Refusal::invalid('Choose a day, and a period of at most '.self::MAX_DAYS.' days ending after it starts.', ['date', 'from', 'to']);
        }

        if ($department !== null && ! in_array($department, EmployeeService::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose a department of the list.', ['department']);
        }

        return $out + ['day' => ['date' => $date, 'rows' => $this->dayRows($property, $date, $department, $tz, $now)], 'summary' => ['from' => $from, 'to' => $to, 'department' => $department, 'rows' => $this->summary($property, $from, $to, $department, $tz, $now)], 'departments' => EmployeeService::DEPARTMENTS];
    }

    /** @return array<string, mixed> the person's shift now, as `overview` shows it */
    public function clockIn(PropertyId $property, string $actorId, ?float $latitude, ?float $longitude, ?string $photo, ?string $photoName): array
    {
        $employee = $this->ownEmployee($property, $actorId);
        $tz = $this->zone($property);
        $now = $this->clock->nowUtc();
        $shift = $this->shiftFor($property, $employee['id'], $tz, $now);

        if ($shift === null) {
            throw Refusal::stateConflict('No shift is planned for you at this time. Ask your supervisor.');
        }

        if ($shift['record'] !== null) {
            throw Refusal::stateConflict('You clocked in for this shift already.');
        }

        $settings = $this->settings($property);
        $distance = $this->checkPlace($settings, $latitude, $longitude);
        $file = $this->photo($property, strtolower($actorId), $settings, $photo, $photoName);
        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $employee, $shift, $now, $distance, $file, $id): void {
            $this->employees->lockEmployee($property, $employee['id']);

            if (! $this->store->add($property, ['id' => $id, 'employee_id' => $employee['id'], 'work_date' => $shift['date'], 'in_at' => $now->format('Y-m-d H:i:s.u'), 'in_method' => 'mobile', 'in_distance_m' => $distance, 'in_photo_file_id' => $file?->id, 'recorded_by' => $actor], $now)) {
                throw Refusal::stateConflict('You clocked in for this shift already.');
            }

            $this->expire($property, $file, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'attendance.clocked_in', 'attendance', $id, null, ['employee' => $employee['number'], 'work_date' => $shift['date'], 'shift' => $shift['entry']['pattern_code'], 'method' => 'mobile', 'distance_m' => $distance, 'photo' => $file !== null]));
        });

        return $this->me($property, $employee['id'], $tz, $now) ?? [];
    }

    /** @return array<string, mixed> */
    public function clockOut(PropertyId $property, string $actorId, ?float $latitude, ?float $longitude, ?string $photo, ?string $photoName): array
    {
        $employee = $this->ownEmployee($property, $actorId);
        $tz = $this->zone($property);
        $now = $this->clock->nowUtc();
        $today = $tz->calendarDateAt($now)->toString();
        $open = null;

        foreach ([date('Y-m-d', strtotime($today.' -1 day')), $today] as $d) {
            $r = $this->store->record($property, $employee['id'], $d);

            if ($r !== null && $r['out_at'] === null) {
                $open = $r;
            }
        }

        if ($open === null) {
            throw Refusal::stateConflict('You have not clocked in, or you clocked out already.');
        }

        $in = new DateTimeImmutable((string) $open['in_at'], new DateTimeZone('UTC'));

        if ($now <= $in || $now > $in->modify('+20 hours')) {
            throw Refusal::stateConflict('Too long since you clocked in. Ask your supervisor to record your clock-out.');
        }

        $settings = $this->settings($property);
        $distance = $this->checkPlace($settings, $latitude, $longitude);
        $file = $this->photo($property, strtolower($actorId), $settings, $photo, $photoName);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $employee, $open, $now, $distance, $file): void {
            $this->employees->lockEmployee($property, $employee['id']);

            if (! $this->store->update($property, $open['id'], (int) $open['lock_version'], ['out_at' => $now->format('Y-m-d H:i:s.u'), 'out_method' => 'mobile', 'out_distance_m' => $distance, 'out_photo_file_id' => $file?->id], $now)) {
                throw Refusal::stateConflict('You clocked out already.');
            }

            $this->expire($property, $file, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'attendance.clocked_out', 'attendance', $open['id'], null, ['employee' => $employee['number'], 'work_date' => substr((string) $open['work_date'], 0, 10), 'method' => 'mobile', 'distance_m' => $distance, 'photo' => $file !== null]));
        });

        return $this->me($property, $employee['id'], $tz, $now) ?? [];
    }

    /**
     * A clock-in, and when given a clock-out, that a supervisor records for a person who could not do it on the phone; at most a week back, never over a record that exists.
     *
     * @return array<string, mixed>
     */
    public function recordManually(PropertyId $property, string $actorId, string $employeeId, string $date, string $in, ?string $out, string $reason): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not record attendance.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why it is recorded here, in at most 200 characters.', ['reason']);
        }

        $tz = $this->zone($property);
        $now = $this->clock->nowUtc();
        $today = $tz->calendarDateAt($now)->toString();

        if (! $this->isDate($date) || $date > $today || $date < date('Y-m-d', strtotime($today.' -'.self::MANUAL_DAYS_BACK.' days'))) {
            throw Refusal::invalid('Give a day from the last '.self::MANUAL_DAYS_BACK.' days, up to today.', ['work_date']);
        }

        foreach ([[$in, 'in_time'], [$out, 'out_time']] as [$t, $field]) {
            if ($t !== null && $t !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) !== 1) {
                throw Refusal::invalid('Give the time as hours and minutes, like 07:05.', [$field]);
            }
        }

        $employee = $this->employees->employee($property, strtolower($employeeId)) ?? throw Refusal::invalid('Choose an employee of this property.', ['employee_id']);
        $entry = $this->roster->entry($property, $employee['id'], $date);

        if ($entry === null || (bool) $entry['is_off']) {
            throw Refusal::invalid('The roster has no shift for this person on this day.', ['work_date']);
        }

        [$inAt, $outAt] = ShiftTimes::clocked($tz, $date, $in, $out);

        if ($inAt > $now || ($outAt !== null && $outAt > $now)) {
            throw Refusal::invalid('A time that has not come yet cannot be recorded.', ['in_time', 'out_time']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $employee, $date, $inAt, $outAt, $reason, $id, $now): void {
            $this->employees->lockEmployee($property, $employee['id']);

            if (! $this->store->add($property, [
                'id' => $id, 'employee_id' => $employee['id'], 'work_date' => $date, 'in_at' => $inAt->format('Y-m-d H:i:s.u'), 'in_method' => 'manual', 'out_at' => $outAt?->format('Y-m-d H:i:s.u'), 'out_method' => $outAt === null ? null : 'manual', 'recorded_by' => $actor, 'manual_reason' => $reason,
            ], $now)) {
                throw Refusal::stateConflict('This person has a record for this day already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'attendance.recorded_manually', 'attendance', $id, null, ['employee' => $employee['number'], 'work_date' => $date, 'in_at' => $inAt->format('Y-m-d\TH:i:s\Z'), 'out_at' => $outAt?->format('Y-m-d\TH:i:s\Z')], $reason));
        });

        return $this->dayRowFor($property, $employee['id'], $date, $tz, $now);
    }

    /** @return array{latitude: float|null, longitude: float|null, radius_m: int, require_selfie: bool, late_grace: int, early_grace: int, extra_after: int, geofence: bool, is_baseline: bool, lock_version: int|null} */
    public function settings(PropertyId $property): array
    {
        $r = $this->store->settings($property);

        return [
            'latitude' => $r === null || $r['latitude'] === null ? null : (float) $r['latitude'], 'longitude' => $r === null || $r['longitude'] === null ? null : (float) $r['longitude'],
            'radius_m' => (int) ($r['radius_m'] ?? self::BASELINE['radius_m']), 'require_selfie' => (bool) ($r['require_selfie'] ?? self::BASELINE['require_selfie']),
            'late_grace' => (int) ($r['late_grace_minutes'] ?? self::BASELINE['late_grace']), 'early_grace' => (int) ($r['early_grace_minutes'] ?? self::BASELINE['early_grace']), 'extra_after' => (int) ($r['extra_after_minutes'] ?? self::BASELINE['extra_after']),
            'geofence' => $r !== null && $r['latitude'] !== null, 'is_baseline' => $r === null, 'lock_version' => $r === null ? null : (int) $r['lock_version'],
        ];
    }

    /** @return array<string, mixed> */
    public function saveSettings(PropertyId $property, string $actorId, ?float $latitude, ?float $longitude, int $radius, bool $requireSelfie, int $lateGrace, int $earlyGrace, int $extraAfter, ?int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not set how attendance is taken.');

        if (($latitude === null) !== ($longitude === null) || ($latitude !== null && ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180))) {
            throw Refusal::invalid('Give the latitude and the longitude of the property, or neither.', ['latitude', 'longitude']);
        }

        if ($radius < 20 || $radius > 5000) {
            throw Refusal::invalid('The distance allowed is from 20 to 5000 metres.', ['radius_m']);
        }

        if ($lateGrace < 0 || $lateGrace > 120 || $earlyGrace < 0 || $earlyGrace > 120 || $extraAfter < 0 || $extraAfter > 240) {
            throw Refusal::invalid('Minutes of grace are from 0 to 120, and extra hours count from 0 to 240 minutes.', ['late_grace_minutes', 'early_grace_minutes', 'extra_after_minutes']);
        }

        $before = $this->settings($property);
        $actor = strtolower($actorId);
        $values = ['latitude' => $latitude, 'longitude' => $longitude, 'radius_m' => $radius, 'require_selfie' => $requireSelfie, 'late_grace' => $lateGrace, 'early_grace' => $earlyGrace, 'extra_after' => $extraAfter];

        $this->transactions->run(function () use ($property, $actor, $values, $lock, $before): void {
            if ($before['lock_version'] !== $lock || ! $this->store->saveSettings($property, $values, $before['lock_version'], $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('These settings changed after you opened them. Reload them.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'attendance_settings.changed', 'attendance_settings', $property->toString(), array_diff_key($before, ['lock_version' => 1, 'is_baseline' => 1]), $values));
        });

        return $this->settings($property);
    }

    private function photo(PropertyId $property, string $actorId, array $settings, ?string $contents, ?string $name): ?StoredFile
    {
        if ($contents === null || $contents === '') {
            if ($settings['require_selfie']) {
                throw Refusal::invalid('Take a selfie to clock in or out.', ['photo']);
            }

            return null;
        }

        try {
            return $this->storeFile->execute(new FileUpload($property, $actorId, self::PHOTO_PURPOSE, 'attendance', $this->ids->next(), $contents, new FilePolicy(['image/jpeg', 'image/png'], self::PHOTO_MAX_BYTES, FileSensitivity::Sensitive, false), $name));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), ['photo']);
        }
    }

    public function download(PropertyId $property, string $actorId, string $id, string $which): FileContent
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not open attendance selfies.');
        $r = $this->store->recordById($property, strtolower($id)) ?? throw Refusal::notFound('Attendance record not found.');
        $fileId = $which === 'out' ? $r['out_photo_file_id'] : $r['in_photo_file_id'];

        if ($fileId === null) {
            throw Refusal::notFound('This record has no such selfie.');
        }

        $policy = new class($this->permissions, $property) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->permissions->allowsInProperty($actorId, HrAccess::ATTENDANCE, $this->property);
            }
        };

        try {
            return $this->downloadFile->execute($property, (string) $fileId, strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The selfie is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not open attendance selfies.');
        }
    }

    /** @return array{expected: int, present: int, groups: list<array{department: string, code: string, expected: int, present: int}>} */
    public function onDuty(PropertyId $property): array
    {
        $tz = $this->zone($property);
        $now = $this->clock->nowUtc();
        $today = $tz->calendarDateAt($now)->toString();
        $from = date('Y-m-d', strtotime($today.' -1 day'));
        $records = [];

        foreach ($this->store->between($property, $from, $today, null) as $r) {
            $records[$r['employee_id'].'|'.substr((string) $r['work_date'], 0, 10)] = $r;
        }

        $groups = [];

        foreach ($this->roster->entriesBetween($property, $from, $today, null) as $e) {
            if ((bool) $e['is_off'] || $e['employee_status'] !== 'active') {
                continue;
            }

            [$start, $end] = $this->window($e, $tz);

            if ($now < $start || $now >= $end) {
                continue;
            }

            $key = $e['department'].'|'.$e['pattern_code'];
            $groups[$key] ??= ['department' => $e['department'], 'code' => $e['pattern_code'], 'expected' => 0, 'present' => 0];
            $groups[$key]['expected']++;
            $r = $records[$e['employee_id'].'|'.substr((string) $e['work_date'], 0, 10)] ?? null;
            $groups[$key]['present'] += $r !== null && $r['out_at'] === null ? 1 : 0;
        }

        ksort($groups);
        $list = array_values($groups);

        return ['expected' => array_sum(array_column($list, 'expected')), 'present' => array_sum(array_column($list, 'present')), 'groups' => $list];
    }

    /**
     * What the roster and the clock say of one planned day.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>|null  $record
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $grant  the overtime approved for the day: its minutes and when it was approved
     * @return array{status: string, late_minutes: int, early_minutes: int, extra_minutes: int, overtime_minutes: int, unapproved_minutes: int, overtime_granted: int, worked_minutes: int|null, planned_start: string, planned_end: string}
     */
    public function evaluate(array $entry, ?array $record, array $settings, DateTimeImmutable $now, PropertyTimeZone $tz, ?array $grant = null): array
    {
        [$start, $end] = $this->window($entry, $tz);
        $minutes = static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => (int) floor(($b->getTimestamp() - $a->getTimestamp()) / 60);
        $late = 0;
        $early = 0;
        $extra = 0;
        $overtime = 0;
        $worked = null;
        // Overtime counts as approved beforehand only when it was approved before the shift ended.
        $granted = $grant !== null && new DateTimeImmutable((string) $grant['approved_at'], new DateTimeZone('UTC')) <= $end ? (int) $grant['minutes'] : 0;

        if ($record === null) {
            $status = $now < $start->modify('+'.$settings['late_grace'].' minutes') ? 'upcoming' : ($now < $end ? 'not_in' : 'absent');
        } else {
            $in = new DateTimeImmutable((string) $record['in_at'], new DateTimeZone('UTC'));
            $diff = $minutes($start, $in);
            $late = $diff > $settings['late_grace'] ? $diff : 0;

            if ($record['out_at'] === null) {
                $status = $now < $end ? 'on_duty' : 'missing_out';
            } else {
                $out = new DateTimeImmutable((string) $record['out_at'], new DateTimeZone('UTC'));
                $status = 'present';
                $worked = $minutes($in, $out);
                $shortfall = $minutes($out, $end);
                $early = $shortfall > $settings['early_grace'] ? $shortfall : 0;
                $over = $minutes($end, $out);
                $extra = $over > 0 && $over >= $settings['extra_after'] ? $over : 0;
                $overtime = $over > 0 ? min($over, $granted) : 0;
            }
        }

        return ['status' => $status, 'late_minutes' => $late, 'early_minutes' => $early, 'extra_minutes' => $extra, 'overtime_minutes' => $overtime, 'unapproved_minutes' => max(0, $extra - $overtime), 'overtime_granted' => $granted, 'worked_minutes' => $worked, 'planned_start' => $start->format('Y-m-d\TH:i:s\Z'), 'planned_end' => $end->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} the planned start and end, in UTC
     */
    private function window(array $entry, PropertyTimeZone $tz): array
    {
        return ShiftTimes::window($entry, $tz);
    }

    /** The planned shift whose time it is: the day's or the one before it that runs past midnight. @return array{date: string, entry: array<string, mixed>, record: array<string, mixed>|null, window: array{0: DateTimeImmutable, 1: DateTimeImmutable}}|null */
    private function shiftFor(PropertyId $property, string $employeeId, PropertyTimeZone $tz, DateTimeImmutable $now): ?array
    {
        $today = $tz->calendarDateAt($now)->toString();
        $best = null;

        foreach ([date('Y-m-d', strtotime($today.' -1 day')), $today] as $d) {
            $entry = $this->roster->entry($property, $employeeId, $d);

            if ($entry === null || (bool) $entry['is_off']) {
                continue;
            }

            [$start, $end] = $this->window($entry, $tz);

            if ($now < $start->modify('-'.self::IN_BEFORE.' minutes') || $now > $end->modify('+'.self::OUT_AFTER.' minutes')) {
                continue;
            }

            $record = $this->store->record($property, $employeeId, $d);
            $open = $record !== null && $record['out_at'] === null;
            $candidate = ['date' => $d, 'entry' => $entry, 'record' => $record, 'window' => [$start, $end]];

            if ($best === null || ($open && ! ($best['record'] !== null && $best['record']['out_at'] === null)) || ($record === null && $best['record'] !== null && $best['record']['out_at'] !== null)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @return array<string, mixed>|null */
    private function me(PropertyId $property, string $employeeId, PropertyTimeZone $tz, DateTimeImmutable $now): ?array
    {
        $e = $this->employees->employee($property, $employeeId);

        if ($e === null) {
            return null;
        }

        $settings = $this->settings($property);
        $shift = $e['status'] === 'active' ? $this->shiftFor($property, $employeeId, $tz, $now) : null;

        return [
            'employee' => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department']], 'active' => $e['status'] === 'active',
            'shift' => $shift === null ? null : ['date' => $shift['date'], 'code' => $shift['entry']['pattern_code'], 'starts_at' => $shift['entry']['starts_at'], 'ends_at' => $shift['entry']['ends_at'], 'starts2_at' => $shift['entry']['starts2_at'], 'ends2_at' => $shift['entry']['ends2_at'], 'record' => $this->recordShape($shift['record']), ...$this->evaluate($shift['entry'], $shift['record'], $settings, $now, $tz, $this->grants($property, $shift['date'], $shift['date'], $employeeId)[$employeeId.'|'.$shift['date']] ?? null)],
            'may_clock_in' => $shift !== null && $shift['record'] === null, 'may_clock_out' => $shift !== null && $shift['record'] !== null && $shift['record']['out_at'] === null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function dayRows(PropertyId $property, string $date, ?string $department, PropertyTimeZone $tz, DateTimeImmutable $now): array
    {
        $settings = $this->settings($property);
        $records = [];

        foreach ($this->store->between($property, $date, $date, null) as $r) {
            $records[$r['employee_id']] = $r;
        }

        $grants = $this->grants($property, $date, $date, null);
        $rows = [];

        foreach ($this->roster->entriesBetween($property, $date, $date, $department) as $e) {
            if ((bool) $e['is_off']) {
                continue;
            }

            $rows[] = $this->row($e, $records[$e['employee_id']] ?? null, $settings, $now, $tz, $grants[$e['employee_id'].'|'.$date] ?? null);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function dayRowFor(PropertyId $property, string $employeeId, string $date, PropertyTimeZone $tz, DateTimeImmutable $now): array
    {
        foreach ($this->dayRows($property, $date, null, $tz, $now) as $r) {
            if ($r['employee']['id'] === $employeeId) {
                return $r;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $e
     * @param  array<string, mixed>|null  $record
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $grant
     * @return array<string, mixed>
     */
    private function row(array $e, ?array $record, array $settings, DateTimeImmutable $now, PropertyTimeZone $tz, ?array $grant): array
    {
        return [
            'employee' => ['id' => $e['employee_id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department']],
            'shift' => ['code' => $e['pattern_code'], 'date' => substr((string) $e['work_date'], 0, 10), 'starts_at' => $e['starts_at'], 'ends_at' => $e['ends_at'], 'starts2_at' => $e['starts2_at'], 'ends2_at' => $e['ends2_at']],
            'record' => $this->recordShape($record), ...$this->evaluate($e, $record, $settings, $now, $tz, $grant),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $r
     * @return array<string, mixed>|null
     */
    private function recordShape(?array $r): ?array
    {
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new DateTimeImmutable((string) $v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return $r === null ? null : [
            'id' => $r['id'], 'in_at' => $utc($r['in_at']), 'in_method' => $r['in_method'], 'in_distance_m' => $r['in_distance_m'] === null ? null : (int) $r['in_distance_m'], 'has_in_photo' => $r['in_photo_file_id'] !== null,
            'out_at' => $utc($r['out_at']), 'out_method' => $r['out_method'], 'out_distance_m' => $r['out_distance_m'] === null ? null : (int) $r['out_distance_m'], 'has_out_photo' => $r['out_photo_file_id'] !== null,
            'manual_reason' => $r['manual_reason'], 'lock_version' => (int) $r['lock_version'],
        ];
    }

    /** @return list<array<string, mixed>> one row for each person who was planned in the period */
    private function summary(PropertyId $property, string $from, string $to, ?string $department, PropertyTimeZone $tz, DateTimeImmutable $now): array
    {
        $settings = $this->settings($property);
        $records = [];

        foreach ($this->store->between($property, $from, $to, null) as $r) {
            $records[$r['employee_id'].'|'.substr((string) $r['work_date'], 0, 10)] = $r;
        }

        $grants = $this->grants($property, $from, $to, null);
        $per = [];

        foreach ($this->roster->entriesBetween($property, $from, $to, $department) as $e) {
            if ((bool) $e['is_off']) {
                continue;
            }

            [, $end] = $this->window($e, $tz);

            // A day not over, with nothing clocked yet, is not counted.
            if ($end > $now && ! isset($records[$e['employee_id'].'|'.substr((string) $e['work_date'], 0, 10)])) {
                continue;
            }

            $p = &$per[$e['employee_id']];
            $p ??= ['employee' => ['id' => $e['employee_id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department']], 'scheduled' => 0, 'present' => 0, 'late_days' => 0, 'late_minutes' => 0, 'early_days' => 0, 'early_minutes' => 0, 'absent' => 0, 'extra_minutes' => 0, 'overtime_minutes' => 0, 'unapproved_minutes' => 0, 'worked_minutes' => 0];

            $v = $this->evaluate($e, $records[$e['employee_id'].'|'.substr((string) $e['work_date'], 0, 10)] ?? null, $settings, $now, $tz, $grants[$e['employee_id'].'|'.substr((string) $e['work_date'], 0, 10)] ?? null);
            $p['scheduled']++;
            $p['present'] += in_array($v['status'], ['present', 'on_duty', 'missing_out'], true) ? 1 : 0;
            $p['absent'] += $v['status'] === 'absent' ? 1 : 0;
            $p['late_days'] += $v['late_minutes'] > 0 ? 1 : 0;
            $p['late_minutes'] += $v['late_minutes'];
            $p['early_days'] += $v['early_minutes'] > 0 ? 1 : 0;
            $p['early_minutes'] += $v['early_minutes'];
            $p['extra_minutes'] += $v['extra_minutes'];
            $p['overtime_minutes'] += $v['overtime_minutes'];
            $p['unapproved_minutes'] += $v['unapproved_minutes'];
            $p['worked_minutes'] += $v['worked_minutes'] ?? 0;
            unset($p);
        }

        $rows = array_values($per);
        usort($rows, static fn (array $a, array $b): int => strcmp($a['employee']['name'], $b['employee']['name']));

        return $rows;
    }

    /** @return array<string, array<string, mixed>> the approved overtime of the days, by person and day */
    private function grants(PropertyId $property, string $from, string $to, ?string $employeeId): array
    {
        $out = [];

        foreach ($this->overtime->approvedBetween($property, $from, $to, $employeeId) as $g) {
            $out[$g['employee_id'].'|'.substr((string) $g['work_date'], 0, 10)] = $g;
        }

        return $out;
    }

    /** @param array{latitude: float|null, longitude: float|null, radius_m: int, geofence: bool} $settings */
    private function checkPlace(array $settings, ?float $latitude, ?float $longitude): ?int
    {
        if ($latitude === null || $longitude === null) {
            if ($settings['geofence']) {
                throw Refusal::invalid('Allow the phone to share where you are, to clock in or out at the property.', ['latitude', 'longitude']);
            }

            return null;
        }

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw Refusal::invalid('The position given is not on the earth.', ['latitude', 'longitude']);
        }

        if (! $settings['geofence']) {
            return null;
        }

        $r = 6_371_000;
        [$p1, $p2] = [deg2rad($settings['latitude']), deg2rad($latitude)];
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($longitude - $settings['longitude']) / 2) ** 2;
        $distance = (int) round(2 * $r * asin(min(1, sqrt($a))));

        if ($distance > $settings['radius_m']) {
            throw Refusal::invalid("You are {$distance} metres from the property; clocking in or out is allowed within {$settings['radius_m']} metres.", ['latitude', 'longitude']);
        }

        return $distance;
    }

    private function expire(PropertyId $property, ?StoredFile $file, DateTimeImmutable $at): void
    {
        if ($file !== null) {
            $this->files->setExpiryOnce($property, $file->id, $this->retention->expiryFor($property, self::PHOTO_PURPOSE, $at));
        }
    }

    /** @return array<string, mixed> */
    private function ownEmployee(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $id = $this->employees->employeeOfUser($property, strtolower($actorId)) ?? throw Refusal::forbidden('Your account is not linked to an employee record.');
        $e = $this->employees->employee($property, $id) ?? throw Refusal::forbidden('Your account is not linked to an employee record.');

        if ($e['status'] !== 'active') {
            throw Refusal::forbidden('You no longer work here.');
        }

        return $e;
    }

    private function zone(PropertyId $property): PropertyTimeZone
    {
        return $this->zones->forProperty($property) ?? throw Refusal::notFound('Property not found.');
    }

    private function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
