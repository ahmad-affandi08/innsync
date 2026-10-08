<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\HumanResource\Domain\Face\FaceMatcher;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Privacy\ConsentLedger;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Face matching for attendance. A face is a special kind of personal data (the PDP law), so it is handled narrowly:
 *  - the phone's browser turns a photo into 128 numbers; the server never receives or keeps the photo for this, only the numbers (the clock-in selfie is kept as before, for a supervisor);
 *  - a person is registered by someone with the attendance privilege, with the person there, who confirms the person agreed; the agreement is recorded in the consent ledger;
 *  - the numbers are encrypted at rest, kept only while the person works here, and erased when they leave or when HR removes them (the agreement is then recorded as withdrawn);
 *  - each clock-in keeps only the outcome and the distance.
 * The matching is done on what the browser reports, so it deters a buddy punch but is not proof against someone who forges the request; the selfie and the review marks remain the check behind it.
 */
final readonly class FaceService
{
    public const PURPOSE = 'face_attendance';

    public const NOTICE = 'v1';

    public function __construct(private FaceTemplateStore $templates, private EmployeeStore $employees, private HrAccess $access, private ConsentLedger $consents, private TransactionRunner $transactions, private AuditTrail $audit, private Clock $clock) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not register faces for attendance.');
        $enrolled = $this->templates->enrolled($property);

        return [
            'samples' => (int) config('attendance.face.samples'),
            'employees' => array_map(static fn (array $e): array => [
                'id' => (string) $e['id'], 'number' => (string) $e['number'], 'name' => (string) $e['full_name'], 'department' => (string) $e['department'], 'position' => (string) $e['position'],
                'enrolled_at' => isset($enrolled[(string) $e['id']]) ? substr($enrolled[(string) $e['id']], 0, 19) : null,
            ], $this->employees->employees($property, 'active')),
        ];
    }

    /** @param list<mixed> $samples descriptors taken from the photos of the person, as many as `attendance.face.samples` */
    public function enrol(PropertyId $property, string $actorId, string $employeeId, array $samples, bool $agreed): void
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not register faces for attendance.');
        $employee = $this->employees->employee($property, strtolower($employeeId));

        if ($employee === null || $employee['status'] !== 'active') {
            throw Refusal::invalid('Choose an employee who works here.', ['employee_id']);
        }

        if (! $agreed) {
            throw Refusal::invalid('Confirm that the person agreed, in person, to have their face matched for attendance.', ['agreed']);
        }

        $need = (int) config('attendance.face.samples');

        if (count($samples) !== $need) {
            throw Refusal::invalid("Take {$need} photos of the person.", ['samples']);
        }

        foreach ($samples as $s) {
            if (! FaceMatcher::valid($s)) {
                throw Refusal::invalid('One of the photos could not be read as a face. Take it again.', ['samples']);
            }
        }

        /** @var list<list<float>> $samples */
        if (! FaceMatcher::consistent($samples, (float) config('attendance.face.max_distance'))) {
            throw Refusal::invalid('The photos do not look like the same person. Take them again, one person, facing the camera.', ['samples']);
        }

        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $employee, $samples, $now): void {
            $this->employees->lockEmployee($property, $employee['id']);
            $this->templates->save($property, $employee['id'], array_map(static fn (array $s): array => array_map('floatval', $s), $samples), $actor, $now);
            $this->consents->record($property, $actor, 'employee', $employee['id'], self::PURPOSE, self::NOTICE, true, 'in_person');
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'attendance.face.enrolled', 'employee', $employee['id'], null, ['number' => $employee['number'], 'samples' => count($samples)]));
        });
    }

    public function remove(PropertyId $property, string $actorId, string $employeeId, string $reason): void
    {
        $this->access->require($property, $actorId, HrAccess::ATTENDANCE, 'This person may not remove registered faces.');

        if (trim($reason) === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        $employee = $this->employees->employee($property, strtolower($employeeId)) ?? throw Refusal::invalid('Choose an employee of this property.', ['employee_id']);
        $this->erase($property, strtolower($actorId), $employee['id'], trim($reason));
    }

    /** Erases the registered face of a person who leaves (called inside the offboarding), or whose agreement was withdrawn. Nothing happens when none is registered. */
    public function erase(PropertyId $property, string $actorId, string $employeeId, ?string $reason = null): void
    {
        if (! $this->templates->delete($property, $employeeId)) {
            return;
        }

        $this->consents->record($property, $actorId, 'employee', $employeeId, self::PURPOSE, self::NOTICE, false, 'erased');
        $this->audit->record(new AuditEntry($property->toString(), $actorId, 'attendance.face.erased', 'employee', $employeeId, ['registered' => true], ['registered' => false], $reason));
    }

    /**
     * What a clock-in's face says. `none` is no verdict (no face sent, or the person is not registered); `mismatch` is a face that is not the registered one. In `require` mode both refuse the clock-in.
     *
     * @param  string  $mode  off|flag|require
     * @return array{result: string|null, x: int|null} the outcome to keep with the clock-in and the distance in thousandths
     */
    public function check(PropertyId $property, string $employeeId, string $mode, mixed $probe): array
    {
        if ($mode === 'off') {
            return ['result' => null, 'x' => null];
        }

        $registered = $this->templates->find($property, $employeeId);

        if ($registered === null) {
            return $this->none($mode, 'Your face is not registered yet. Ask HR to register it, then clock in.');
        }

        if (! FaceMatcher::valid($probe)) {
            return $this->none($mode, 'Take a selfie that shows your face to clock in or out.');
        }

        $distance = FaceMatcher::nearest($registered['templates'], $probe);
        $x = (int) min(65535, round($distance * 1000));

        if ($distance <= (float) config('attendance.face.max_distance')) {
            return ['result' => 'match', 'x' => $x];
        }

        if ($mode === 'require') {
            throw Refusal::invalid('The face in the selfie does not match the one registered for you. Take the selfie again, facing the camera in good light.', ['photo']);
        }

        return ['result' => 'mismatch', 'x' => $x];
    }

    /** @return array{result: string|null, x: int|null} */
    private function none(string $mode, string $message): array
    {
        if ($mode === 'require') {
            throw Refusal::invalid($message, ['photo']);
        }

        return ['result' => 'none', 'x' => null];
    }
}
