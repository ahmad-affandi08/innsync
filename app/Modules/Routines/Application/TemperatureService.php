<?php

declare(strict_types=1);

namespace App\Modules\Routines\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
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
use DateTimeImmutable;
use DateTimeZone;

/**
 * Storage temperatures (FR-KIT-008): the places to be read (a chiller, a freezer) each with the range it must stay in, and the readings of them. A reading outside the range needs the action
 * taken (moved the food, called maintenance) and is told to whoever follows food safety. A reading is never changed. Management writes the places and ranges, in tenths of a degree.
 */
final readonly class TemperatureService
{
    public function __construct(
        private RoutineStore $routine,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array{points: list<array<string, mixed>>, readings: list<array<string, mixed>>, may: array{record: bool, manage: bool}, business_date: string} */
    public function overview(PropertyId $property, string $department, string $actorId, ?string $from, ?string $to): array
    {
        $dept = RoutineDepartment::of($department);
        $may = $this->may($property, $dept, $actorId);

        if (! $may['record'] && ! $may['manage'] && ! $this->permissions->allowsInProperty($actorId, $dept->permissions['view'], $property)) {
            throw Refusal::forbidden('This person may not see the temperatures.');
        }

        $today = $this->businessDate->current($property)->toString();
        $from = $from === null || $from === '' ? date('Y-m-d', strtotime($today.' -6 days')) : $from;
        $to = $to === null || $to === '' ? $today : $to;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $to < $from || (strtotime($to) - strtotime($from)) / 86400 > 92) {
            throw Refusal::invalid('Choose a range of at most 93 days that ends after it starts.', ['from', 'to']);
        }

        $readings = $this->routine->readings($property, $dept->code, $from, $to, null, 300);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($readings, 'recorded_by'))));

        return [
            'points' => array_map(static fn (array $p): array => ['id' => $p['id'], 'name' => $p['name'], 'min_tenth' => (int) $p['min_tenth'], 'max_tenth' => (int) $p['max_tenth'], 'active' => (bool) $p['is_active'], 'lock_version' => (int) $p['lock_version']], $this->routine->points($property, $dept->code)),
            'readings' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'point' => $r['point_name'], 'value_tenth' => (int) $r['value_tenth'], 'min_tenth' => (int) $r['min_tenth'], 'max_tenth' => (int) $r['max_tenth'], 'in_range' => (bool) $r['in_range'], 'action_taken' => $r['action_taken'],
                'by' => $names[$r['recorded_by']] ?? null, 'at' => (new DateTimeImmutable((string) $r['recorded_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            ], $readings),
            'may' => $may, 'business_date' => $today, 'from' => $from, 'to' => $to,
        ];
    }

    /** @return array<string, mixed> */
    public function definePoint(PropertyId $property, string $department, string $actorId, string $name, int $minTenth, int $maxTenth): array
    {
        $dept = RoutineDepartment::of($department);
        $this->require($property, $actorId, $dept->permissions['manage']);
        $name = trim($name);

        $this->checkPoint($name, $minTenth, $maxTenth);
        $id = $this->ids->next();
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $dept, $actor, $id, $name, $minTenth, $maxTenth): void {
            if (! $this->routine->addPoint($property, ['id' => $id, 'department' => $dept->code, 'name' => $name, 'min_tenth' => $minTenth, 'max_tenth' => $maxTenth, 'is_active' => true], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A place with this name exists already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $dept->code.'.temperature_point.created', 'temperature_point', $id, null, ['name' => $name, 'min_tenth' => $minTenth, 'max_tenth' => $maxTenth]));
        });

        return $this->pointShape($this->routine->point($property, $dept->code, $id) ?? throw Refusal::notFound('Place not found.'));
    }

    /** @return array<string, mixed> */
    public function updatePoint(PropertyId $property, string $department, string $actorId, string $id, string $name, int $minTenth, int $maxTenth, bool $active, int $lock): array
    {
        $dept = RoutineDepartment::of($department);
        $this->require($property, $actorId, $dept->permissions['manage']);
        $name = trim($name);
        $this->checkPoint($name, $minTenth, $maxTenth);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $dept, $actor, $id, $name, $minTenth, $maxTenth, $active, $lock): void {
            $before = $this->routine->point($property, $dept->code, strtolower($id)) ?? throw Refusal::notFound('Place not found.');

            if (! $this->routine->updatePoint($property, $before['id'], $lock, ['name' => $name, 'min_tenth' => $minTenth, 'max_tenth' => $maxTenth, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This place changed after you opened it, or its name is taken. Reload it.');
            }

            // Readings already made keep the range they were judged by.
            $this->audit->record(new AuditEntry($property->toString(), $actor, $dept->code.'.temperature_point.changed', 'temperature_point', $before['id'], ['name' => $before['name'], 'min_tenth' => (int) $before['min_tenth'], 'max_tenth' => (int) $before['max_tenth'], 'active' => (bool) $before['is_active']], ['name' => $name, 'min_tenth' => $minTenth, 'max_tenth' => $maxTenth, 'active' => $active]));
        });

        return $this->pointShape($this->routine->point($property, $dept->code, strtolower($id)) ?? throw Refusal::notFound('Place not found.'));
    }

    /** @return array<string, mixed> the reading, with whether it was inside the range */
    public function record(PropertyId $property, string $department, string $actorId, string $pointId, int $valueTenth, ?string $action): array
    {
        $dept = RoutineDepartment::of($department);
        $this->require($property, $actorId, $dept->permissions['temperature']);

        if ($valueTenth < -600 || $valueTenth > 1500) {
            throw Refusal::invalid('Give a temperature from -60 to 150 degrees.', ['value']);
        }

        $action = $action === null || trim($action) === '' ? null : trim($action);

        if ($action !== null && mb_strlen($action) > 200) {
            throw Refusal::invalid('The action is at most 200 characters.', ['action_taken']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();
        $point = $this->routine->point($property, $dept->code, strtolower($pointId)) ?? throw Refusal::invalid('Choose a place.', ['point_id']);

        if (! (bool) $point['is_active']) {
            throw Refusal::stateConflict('This place is no longer read.');
        }

        $inRange = $valueTenth >= (int) $point['min_tenth'] && $valueTenth <= (int) $point['max_tenth'];

        if (! $inRange && $action === null) {
            throw Refusal::invalid('The temperature is outside its range. Say what was done about it.', ['action_taken']);
        }

        $this->transactions->run(function () use ($property, $dept, $actor, $id, $point, $valueTenth, $inRange, $action): void {
            $this->routine->addReading($property, [
                'id' => $id, 'point_id' => $point['id'], 'department' => $dept->code, 'value_tenth' => $valueTenth, 'min_tenth' => (int) $point['min_tenth'], 'max_tenth' => (int) $point['max_tenth'], 'in_range' => $inRange,
                'action_taken' => $inRange ? null : $action, 'recorded_by' => $actor, 'business_date' => $this->businessDate->current($property)->toString(),
            ], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, $dept->code.'.temperature.recorded', 'temperature_point', $point['id'], null, ['name' => $point['name'], 'value_tenth' => $valueTenth, 'in_range' => $inRange], $inRange ? null : $action));

            if (! $inRange) {
                $this->outbox->publish(new OutboxEvent($property, $dept->event('temperature.out_of_range'), $id, 1, ['reading_id' => $id, 'point_id' => $point['id'], 'point' => $point['name'], 'value_tenth' => $valueTenth, 'actor_id' => $actor]));
            }
        });

        return ['id' => $id, 'point' => $point['name'], 'value_tenth' => $valueTenth, 'in_range' => $inRange];
    }

    private function checkPoint(string $name, int $min, int $max): void
    {
        if ($name === '' || mb_strlen($name) > 60) {
            throw Refusal::invalid('Name the place in at most 60 characters.', ['name']);
        }

        if ($min >= $max || $min < -600 || $max > 1500) {
            throw Refusal::invalid('The lowest temperature must be below the highest, between -60 and 150 degrees.', ['min_tenth', 'max_tenth']);
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function pointShape(array $p): array
    {
        return ['id' => $p['id'], 'name' => $p['name'], 'min_tenth' => (int) $p['min_tenth'], 'max_tenth' => (int) $p['max_tenth'], 'active' => (bool) $p['is_active'], 'lock_version' => (int) $p['lock_version']];
    }

    /** @return array{record: bool, manage: bool} */
    private function may(PropertyId $property, RoutineDepartment $dept, string $actorId): array
    {
        $this->assertProperty($property);

        return ['record' => $this->permissions->allowsInProperty($actorId, $dept->permissions['temperature'], $property), 'manage' => $this->permissions->allowsInProperty($actorId, $dept->permissions['manage'], $property)];
    }

    private function require(PropertyId $property, string $actorId, string $permission): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, $permission, $property)) {
            throw Refusal::forbidden('This person may not do this with the temperatures.');
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
