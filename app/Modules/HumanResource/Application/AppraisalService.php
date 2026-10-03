<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The appraisal of a person for a period (FR-HR-022). The owner makes the forms (what is rated and how much each part weighs, summing to 100); the supervisor of the person, or whoever holds the appraisal right, rates each
 * part from 1 to 5 and comments, then signs: the objective figures of the period (attendance, punctuality, routines done, complaints) are taken at that moment and the ratings are frozen. The person then reads it and signs
 * too, saying whether they agree and, if not, why. A signature is the account of the signer, the time and a hash of what was signed, and needs a recent password confirmation. Nobody appraises themselves, and the person sees
 * their appraisal only once the appraiser has signed. An appraisal is never deleted; before it is completed it can be cancelled with a reason.
 */
final readonly class AppraisalService
{
    public const MAX_DAYS = 366;

    public const MAX_CRITERIA = 12;

    /** The usual form: five parts and their weights. @var list<array{0: string, 1: int}> */
    public const BASELINE = [['Quality of work', 30], ['Punctuality and attendance', 20], ['Teamwork', 20], ['Initiative', 15], ['Guest service', 15]];

    public function __construct(
        private AppraisalStore $store,
        private EmployeeStore $employees,
        private PerformanceService $performance,
        private HrAccess $access,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $manage = $this->access->may($property, $actorId, HrAccess::APPRAISAL);
        $me = $this->employees->employeeOfUser($property, $actor);
        $all = $this->store->list($property, null, 300);
        $mine = [];
        $team = [];

        foreach ($all as $a) {
            if ($me !== null && $a['employee_id'] === $me && in_array($a['status'], ['signed_appraiser', 'completed'], true)) {
                $mine[] = $this->shape($property, $actor, $a, $manage);
            }

            if ($this->mayAppraise($a, $actor, $manage) && $a['employee_id'] !== $me) {
                $team[] = $this->shape($property, $actor, $a, $manage);
            }
        }

        $people = array_values(array_filter($this->employees->employees($property, 'active'), fn (array $e): bool => $e['id'] !== $me && ($manage || ($me !== null && $e['supervisor_id'] === $me))));

        return [
            'today' => $this->businessDate->current($property)->toString(), 'linked' => $me !== null, 'may' => ['manage' => $manage, 'appraise' => $manage || $people !== []],
            'mine' => $mine, 'team' => $team, 'forms' => ($manage || $people !== []) ? array_map(self::formShape(...), $this->store->forms($property, ! $manage)) : [],
            'employees' => array_map(static fn (array $e): array => ['id' => $e['id'], 'number' => $e['number'], 'name' => $e['full_name'], 'department' => $e['department']], $people),
        ];
    }

    /** @param list<array{label: string, weight: int}> $criteria @return array<string, mixed> */
    public function createForm(PropertyId $property, string $actorId, string $name, array $criteria): array
    {
        $this->access->require($property, $actorId, HrAccess::APPRAISAL, 'This person may not make appraisal forms.');
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give a name of at most 80 characters.', ['name']);
        }

        $clean = $this->cleanCriteria($criteria);
        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $name, $clean): void {
            if (! $this->store->addForm($property, ['id' => $id, 'name' => $name, 'criteria' => $clean, 'is_active' => true, 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A form with this name exists already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'appraisal_form.created', 'appraisal_form', $id, null, ['name' => $name, 'criteria' => count($clean)]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function baselineForm(PropertyId $property, string $actorId): array
    {
        $this->access->require($property, $actorId, HrAccess::APPRAISAL, 'This person may not make appraisal forms.');

        if ($this->store->forms($property, false) !== []) {
            throw Refusal::stateConflict('Forms were made already.');
        }

        return $this->createForm($property, $actorId, 'Standard appraisal', array_map(static fn (array $c): array => ['label' => $c[0], 'weight' => $c[1]], self::BASELINE));
    }

    /** @return array<string, mixed> */
    public function setFormActive(PropertyId $property, string $actorId, string $id, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, HrAccess::APPRAISAL, 'This person may not make appraisal forms.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $active, $lock): void {
            $f = $this->store->form($property, strtolower($id)) ?? throw Refusal::notFound('Form not found.');

            if ((bool) $f['is_active'] === $active) {
                throw Refusal::stateConflict($active ? 'This form is in use already.' : 'This form is retired already.');
            }

            if (! $this->store->updateForm($property, $f['id'], $lock, ['is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This form changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $active ? 'appraisal_form.resumed' : 'appraisal_form.retired', 'appraisal_form', $f['id'], ['is_active' => (bool) $f['is_active']], ['is_active' => $active, 'name' => $f['name']]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $employeeId, string $formId, string $periodLabel, string $start, string $end): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $label = trim($periodLabel);

        if ($label === '' || mb_strlen($label) > 30) {
            throw Refusal::invalid('Name the period in at most 30 characters, for example 2026 first half.', ['period_label']);
        }

        if (! ShiftTimes::isDate($start) || ! ShiftTimes::isDate($end) || $end < $start || (strtotime($end) - strtotime($start)) / 86400 >= self::MAX_DAYS) {
            throw Refusal::invalid('The period ends after it starts and lasts at most a year.', ['period_start', 'period_end']);
        }

        if ($end > $this->businessDate->current($property)->toString()) {
            throw Refusal::invalid('The period has ended or ends today.', ['period_end']);
        }

        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $actorId, $employeeId, $formId, $label, $start, $end, $id): void {
            $employee = $this->employees->employee($property, strtolower($employeeId)) ?? throw Refusal::notFound('Employee not found.');
            $form = $this->store->form($property, strtolower($formId)) ?? throw Refusal::notFound('Form not found.');
            $me = $this->employees->employeeOfUser($property, $actor);
            $manage = $this->access->may($property, $actorId, HrAccess::APPRAISAL);

            if ($me === $employee['id']) {
                throw Refusal::forbidden('Nobody appraises themselves.');
            }

            if (! $manage && ! ($me !== null && $employee['supervisor_id'] === $me)) {
                throw Refusal::forbidden('Only the supervisor of the person, or whoever holds the appraisal right, appraises them.');
            }

            if (! (bool) $form['is_active']) {
                throw Refusal::stateConflict('This form is retired.');
            }

            if ($employee['status'] !== 'active') {
                throw Refusal::stateConflict('This person has left.');
            }

            foreach ($this->store->list($property, $employee['id'], 100) as $a) {
                if ($a['period_label'] === $label && $a['status'] !== 'cancelled') {
                    throw Refusal::stateConflict('This person has an appraisal for this period already.');
                }
            }

            $number = $this->store->nextNumber($property, substr($end, 0, 4));

            if (! $this->store->add($property, ['id' => $id, 'number' => $number, 'employee_id' => $employee['id'], 'form_id' => $form['id'], 'form_name' => $form['name'], 'criteria' => json_decode((string) $form['criteria'], true, 512, JSON_THROW_ON_ERROR),
                'period_label' => $label, 'period_start' => $start, 'period_end' => $end, 'status' => 'draft', 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'appraisal.created', 'appraisal', $id, null, ['employee' => $employee['number'], 'period' => $label, 'form' => $form['name']]));
        });

        return $this->overview($property, $actorId);
    }

    /** The ratings and the comment of the appraiser, saved as often as needed until they sign. @param array<string, mixed> $scores @return array<string, mixed> */
    public function save(PropertyId $property, string $actorId, string $id, array $scores, ?string $comment, int $lock): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $comment = $comment === null ? null : trim($comment);

        if ($comment !== null && mb_strlen($comment) > 1000) {
            throw Refusal::invalid('The comment is at most 1000 characters.', ['comment']);
        }

        $this->transactions->run(function () use ($property, $actor, $actorId, $id, $scores, $comment, $lock): void {
            $a = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Appraisal not found.');
            $this->requireAppraiser($property, $actor, $actorId, $a);

            if ($a['status'] !== 'draft') {
                throw Refusal::stateConflict('Only a draft is changed; the ratings are frozen once signed.');
            }

            $keys = array_column($this->criteria($a), 'key');
            $clean = [];

            foreach ($scores as $key => $score) {
                if (! in_array($key, $keys, true) || ! is_int($score) || $score < 1 || $score > 5) {
                    throw Refusal::invalid('Rate each part of the form from 1 to 5.', ['scores']);
                }

                $clean[$key] = $score;
            }

            if (! $this->store->update($property, $a['id'], $lock, ['scores' => $clean === [] ? null : $clean, 'comment' => $comment === '' ? null : $comment], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This appraisal changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'appraisal.saved', 'appraisal', $a['id'], null, ['employee' => $a['employee_number'], 'rated' => count($clean)]));
        });

        return $this->overview($property, $actorId);
    }

    /** The appraiser signs: the figure is worked out, the objective figures of the period are taken and the ratings are frozen. @return array<string, mixed> */
    public function signAsAppraiser(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $actorId, $id, $lock): void {
            $a = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Appraisal not found.');
            $this->requireAppraiser($property, $actor, $actorId, $a);

            if ($a['status'] !== 'draft') {
                throw Refusal::stateConflict('This appraisal was signed already.');
            }

            $criteria = $this->criteria($a);
            $scores = $a['scores'] === null ? [] : json_decode((string) $a['scores'], true, 512, JSON_THROW_ON_ERROR);

            foreach ($criteria as $c) {
                if (! isset($scores[$c['key']])) {
                    throw Refusal::invalid('Rate every part of the form before signing.', ['scores']);
                }
            }

            if ($a['comment'] === null || trim((string) $a['comment']) === '') {
                throw Refusal::invalid('Write a comment before signing.', ['comment']);
            }

            $overall = 0;

            foreach ($criteria as $c) {
                $overall += $scores[$c['key']] * $c['weight'];
            }

            $row = $this->performance->snapshot($property, $a['employee_id'], substr((string) $a['period_start'], 0, 10), substr((string) $a['period_end'], 0, 10));
            $metrics = $row === null ? null : array_intersect_key($row, array_flip(['scheduled', 'present', 'late_days', 'late_minutes', 'absent', 'punctuality', 'attendance', 'overtime_minutes', 'sop_items', 'complaints']));
            $metrics = $metrics === null ? null : [...$metrics, 'sop_by' => (array) ($row['sop_by'] ?? [])];
            $rating = self::rating($overall);
            $now = $this->clock->nowUtc();
            $hash = hash('sha256', json_encode(['number' => $a['number'], 'employee' => $a['employee_id'], 'period' => [$a['period_start'], $a['period_end']], 'criteria' => $criteria, 'scores' => $scores, 'comment' => $a['comment'], 'overall' => $overall, 'metrics' => $metrics, 'by' => $actor, 'at' => $now->format('c')], JSON_THROW_ON_ERROR));

            if (! $this->store->update($property, $a['id'], $lock, ['status' => 'signed_appraiser', 'overall_x100' => $overall, 'rating' => $rating, 'metrics' => $metrics, 'appraiser_id' => $actor, 'appraiser_signed_at' => $now->format('Y-m-d H:i:s.u'), 'appraiser_hash' => $hash], $now)) {
                throw Refusal::stateConflict('This appraisal changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'appraisal.signed_by_appraiser', 'appraisal', $a['id'], ['status' => 'draft'], ['status' => 'signed_appraiser', 'employee' => $a['employee_number'], 'overall_x100' => $overall, 'rating' => $rating, 'hash' => $hash]));
        });

        return $this->overview($property, $actorId);
    }

    /** The person signs too, saying whether they agree. @return array<string, mixed> */
    public function signAsEmployee(PropertyId $property, string $actorId, string $id, bool $agrees, ?string $comment, int $lock): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $comment = $comment === null ? null : trim($comment);

        if ($comment !== null && mb_strlen($comment) > 500) {
            throw Refusal::invalid('The comment is at most 500 characters.', ['comment']);
        }

        if (! $agrees && ($comment === null || $comment === '')) {
            throw Refusal::invalid('Say why you do not agree.', ['comment']);
        }

        $this->transactions->run(function () use ($property, $actor, $id, $agrees, $comment, $lock): void {
            $a = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Appraisal not found.');

            if ($a['employee_user'] === null || strtolower((string) $a['employee_user']) !== $actor) {
                throw Refusal::forbidden('Only the person appraised signs for themselves.');
            }

            if ($a['status'] !== 'signed_appraiser') {
                throw Refusal::stateConflict($a['status'] === 'completed' ? 'You signed this already.' : 'The appraiser has not signed yet.');
            }

            $now = $this->clock->nowUtc();
            $hash = hash('sha256', json_encode(['appraiser_hash' => $a['appraiser_hash'], 'employee' => $actor, 'agrees' => $agrees, 'comment' => $comment, 'at' => $now->format('c')], JSON_THROW_ON_ERROR));

            if (! $this->store->update($property, $a['id'], $lock, ['status' => 'completed', 'employee_agrees' => $agrees, 'employee_comment' => $comment === '' ? null : $comment, 'employee_signed_by' => $actor, 'employee_signed_at' => $now->format('Y-m-d H:i:s.u'), 'employee_hash' => $hash], $now)) {
                throw Refusal::stateConflict('This appraisal changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'appraisal.signed_by_employee', 'appraisal', $a['id'], ['status' => 'signed_appraiser'], ['status' => 'completed', 'employee' => $a['employee_number'], 'agrees' => $agrees, 'hash' => $hash]));
            $this->outbox->publish(new OutboxEvent($property, 'hr.appraisal.completed', $a['id'], 1, ['appraisal_id' => $a['id'], 'employee_id' => $a['employee_id'], 'rating' => $a['rating'], 'overall_x100' => (int) $a['overall_x100'], 'agrees' => $agrees]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $this->access->assertProperty($property);
        $actor = strtolower($actorId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $this->transactions->run(function () use ($property, $actor, $actorId, $id, $reason, $lock): void {
            $a = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Appraisal not found.');
            $this->requireAppraiser($property, $actor, $actorId, $a);

            if (! in_array($a['status'], ['draft', 'signed_appraiser'], true)) {
                throw Refusal::stateConflict('Only an appraisal that is not completed is cancelled.');
            }

            if (! $this->store->update($property, $a['id'], $lock, ['status' => 'cancelled', 'cancel_reason' => $reason], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This appraisal changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'appraisal.cancelled', 'appraisal', $a['id'], ['status' => $a['status']], ['status' => 'cancelled', 'employee' => $a['employee_number']], $reason));
        });

        return $this->overview($property, $actorId);
    }

    public static function rating(int $overallX100): string
    {
        return match (true) {
            $overallX100 < 200 => 'poor',
            $overallX100 < 300 => 'fair',
            $overallX100 < 400 => 'good',
            $overallX100 < 450 => 'very_good',
            default => 'excellent',
        };
    }

    /** @param array<string, mixed> $a */
    private function requireAppraiser(PropertyId $property, string $actor, string $actorId, array $a): void
    {
        if ($a['employee_user'] !== null && strtolower((string) $a['employee_user']) === $actor) {
            throw Refusal::forbidden('Nobody appraises themselves.');
        }

        if (! $this->mayAppraise($a, $actor, $this->access->may($property, $actorId, HrAccess::APPRAISAL))) {
            throw Refusal::forbidden('Only the supervisor of the person, or whoever holds the appraisal right, appraises them.');
        }
    }

    /** @param array<string, mixed> $a */
    private function mayAppraise(array $a, string $actor, bool $manage): bool
    {
        return $manage || ($a['supervisor_user'] !== null && strtolower((string) $a['supervisor_user']) === $actor);
    }

    /** @param array<string, mixed> $a @return list<array{key: string, label: string, weight: int}> */
    private function criteria(array $a): array
    {
        return is_string($a['criteria']) ? json_decode($a['criteria'], true, 512, JSON_THROW_ON_ERROR) : $a['criteria'];
    }

    /** @param list<array<string, mixed>> $criteria @return list<array{key: string, label: string, weight: int}> */
    private function cleanCriteria(array $criteria): array
    {
        if ($criteria === [] || count($criteria) > self::MAX_CRITERIA) {
            throw Refusal::invalid('A form rates 1 to '.self::MAX_CRITERIA.' parts.', ['criteria']);
        }

        $out = [];
        $sum = 0;

        foreach (array_values($criteria) as $i => $c) {
            $label = trim((string) ($c['label'] ?? ''));
            $weight = $c['weight'] ?? null;

            if ($label === '' || mb_strlen($label) > 80 || ! is_int($weight) || $weight < 1 || $weight > 100) {
                throw Refusal::invalid('Each part has a name of at most 80 characters and a weight from 1 to 100.', ['criteria']);
            }

            $out[] = ['key' => 'c'.($i + 1), 'label' => $label, 'weight' => $weight];
            $sum += $weight;
        }

        if ($sum !== 100) {
            throw Refusal::invalid('The weights add up to 100; they add up to '.$sum.'.', ['criteria']);
        }

        return $out;
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private static function formShape(array $f): array
    {
        return ['id' => $f['id'], 'name' => $f['name'], 'criteria' => json_decode((string) $f['criteria'], true, 512, JSON_THROW_ON_ERROR), 'active' => (bool) $f['is_active'], 'lock_version' => (int) $f['lock_version']];
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function shape(PropertyId $property, string $actor, array $a, bool $manage): array
    {
        $isAppraiser = $this->mayAppraise($a, $actor, $manage) && ($a['employee_user'] === null || strtolower((string) $a['employee_user']) !== $actor);
        $isEmployee = $a['employee_user'] !== null && strtolower((string) $a['employee_user']) === $actor;
        $utc = static fn (mixed $v): ?string => $v === null ? null : (new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

        return [
            'id' => $a['id'], 'number' => $a['number'], 'employee' => ['id' => $a['employee_id'], 'number' => $a['employee_number'], 'name' => $a['employee_name'], 'department' => $a['department'], 'position' => $a['position']], 'form_name' => $a['form_name'],
            'criteria' => $this->criteria($a), 'period_label' => $a['period_label'], 'period_start' => substr((string) $a['period_start'], 0, 10), 'period_end' => substr((string) $a['period_end'], 0, 10), 'status' => $a['status'],
            'scores' => $a['scores'] === null ? (object) [] : json_decode((string) $a['scores'], true, 512, JSON_THROW_ON_ERROR), 'comment' => $a['comment'], 'overall_x100' => $a['overall_x100'] === null ? null : (int) $a['overall_x100'], 'rating' => $a['rating'],
            'metrics' => $a['metrics'] === null ? null : json_decode((string) $a['metrics'], true, 512, JSON_THROW_ON_ERROR), 'appraiser_signed_at' => $utc($a['appraiser_signed_at']), 'appraiser_hash' => $a['appraiser_hash'], 'employee_agrees' => $a['employee_agrees'] === null ? null : (bool) $a['employee_agrees'],
            'employee_comment' => $a['employee_comment'], 'employee_signed_at' => $utc($a['employee_signed_at']), 'employee_hash' => $a['employee_hash'], 'cancel_reason' => $a['cancel_reason'], 'lock_version' => (int) $a['lock_version'],
            'may' => ['edit' => $isAppraiser && $a['status'] === 'draft', 'sign_appraiser' => $isAppraiser && $a['status'] === 'draft', 'sign_employee' => $isEmployee && $a['status'] === 'signed_appraiser', 'cancel' => $isAppraiser && in_array($a['status'], ['draft', 'signed_appraiser'], true)],
        ];
    }
}
