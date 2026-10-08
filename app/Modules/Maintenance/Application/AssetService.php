<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\DateMath;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The assets of the property and the routine care they get (FR-MTC-007, -008, -014). An asset has a number, a name and kind, where it is (a room or a place), its serial, the day it
 * was acquired and its warranty, and, if it wears by use, the unit of its meter (hours, kilometres or cycles). It is never deleted: it is retired, with a reason, and its plans stop.
 * Its history is the work orders made for it. A meter reading is kept as it was read and never goes below the one before. A plan is care to be done every so many days, or every so
 * many units of the meter; it is paused and resumed rather than edited, and a different plan is a new one. The work orders a plan makes are `PreventiveService`'s.
 */
final readonly class AssetService
{
    public const CATEGORIES = ['machine', 'equipment', 'vehicle', 'building', 'it', 'other'];

    public const METER_UNITS = ['hours', 'km', 'cycles'];

    public const TRIGGERS = ['calendar', 'meter'];

    /** How soon before the end of a warranty it is flagged. */
    public const WARRANTY_FLAG_DAYS = 60;

    public function __construct(
        private AssetStore $store,
        private MaintenanceAccess $access,
        private RoomCatalogReader $rooms,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->view($property, $actorId);
        $today = $this->businessDate->current($property)->toString();

        return [
            'assets' => array_map(fn (array $a): array => $this->summary($a, $today), $this->store->all($property)),
            'rooms' => array_values(array_map(static fn ($r): array => ['id' => $r->id, 'number' => $r->number], $this->rooms->activeRooms($property))),
            'categories' => self::CATEGORIES, 'meter_units' => self::METER_UNITS, 'business_date' => $today, 'warranty_flag_days' => self::WARRANTY_FLAG_DAYS,
            'may' => ['manage' => $this->access->may($property, $actorId, MaintenanceAccess::MANAGE), 'read' => $this->access->may($property, $actorId, MaintenanceAccess::PERFORM) || $this->access->may($property, $actorId, MaintenanceAccess::MANAGE)],
        ];
    }

    /** @return array<string, mixed> one asset with its repairs, readings and plans */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->view($property, $actorId);
        $asset = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Asset not found.');
        $today = $this->businessDate->current($property)->toString();
        $orders = $this->store->workOrdersOf($property, $asset['id'], 50);
        $readings = $this->store->readings($property, $asset['id'], 20);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($readings, 'read_by'))));
        $current = $this->store->currentReading($property, $asset['id']);

        return [
            'asset' => $this->summary([...$asset, 'reading' => $current, 'next_due_on' => null], $today) + ['notes' => $asset['notes'], 'retired_reason' => $asset['retired_reason'], 'retired_on' => $asset['retired_on'], 'lock_version' => (int) $asset['lock_version']],
            'repairs' => array_map(static fn (array $w): array => ['id' => $w['id'], 'number' => $w['number'], 'title' => $w['title'], 'status' => $w['status'], 'priority' => $w['priority'], 'reported_at' => (new DateTimeImmutable((string) $w['reported_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'), 'preventive' => $w['plan_id'] !== null], $orders),
            'readings' => array_map(fn (array $r): array => ['reading' => (int) $r['reading'], 'read_on' => $r['read_on'], 'by' => $names[$r['read_by']] ?? null], $readings),
            'plans' => array_map(fn (array $p): array => $this->planView($p, $current, $today), $this->store->plans($property, $asset['id'])),
            'may' => ['manage' => $this->access->may($property, $actorId, MaintenanceAccess::MANAGE), 'read_meter' => $asset['meter_unit'] !== null && $asset['status'] === 'active' && ($this->access->may($property, $actorId, MaintenanceAccess::PERFORM) || $this->access->may($property, $actorId, MaintenanceAccess::MANAGE))],
            'business_date' => $today,
        ];
    }

    /** @return array<string, mixed> */
    public function create(PropertyId $property, string $actorId, string $name, string $category, ?string $serial, ?string $roomId, ?string $area, string $acquiredOn, ?string $warrantyUntil, ?string $meterUnit, ?string $notes): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not keep the assets.');
        $name = trim($name);
        $serial = $this->text($serial, 40, 'serial');
        $area = $this->text($area, 80, 'area');
        $notes = $this->text($notes, 300, 'notes');
        $roomId = $roomId === null || $roomId === '' ? null : strtolower($roomId);

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Name the asset, in at most 80 characters.', ['name']);
        }

        if (! in_array($category, self::CATEGORIES, true)) {
            throw Refusal::invalid('Choose the kind of asset.', ['category']);
        }

        if ($meterUnit !== null && $meterUnit !== '' && ! in_array($meterUnit, self::METER_UNITS, true)) {
            throw Refusal::invalid('The meter counts hours, kilometres or cycles.', ['meter_unit']);
        }

        $meterUnit = $meterUnit === '' ? null : $meterUnit;
        $acquired = $this->date($acquiredOn, 'acquired_on');
        $warranty = $warrantyUntil === null || $warrantyUntil === '' ? null : $this->date($warrantyUntil, 'warranty_until');

        if ($warranty !== null && $warranty < $acquired) {
            throw Refusal::invalid('The warranty cannot end before the asset was acquired.', ['warranty_until']);
        }

        $room = $roomId === null ? null : ($this->rooms->room($property, $roomId) ?? throw Refusal::invalid('Choose a room of this property.', ['room_id']));
        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $id, $name, $category, $serial, $room, $area, $acquiredOn, $warrantyUntil, $meterUnit, $notes, $now): void {
            $number = $this->numbers->next($property, 'AST');
            $this->store->add($property, [
                'id' => $id, 'number' => $number, 'name' => $name, 'category' => $category, 'serial' => $serial, 'room_id' => $room?->id, 'room_number' => $room?->number, 'area' => $area, 'acquired_on' => $acquiredOn,
                'warranty_until' => $warrantyUntil === '' ? null : $warrantyUntil, 'meter_unit' => $meterUnit, 'notes' => $notes, 'created_by' => $actor,
            ], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'asset.created', 'asset', $id, null, ['number' => $number, 'name' => $name, 'category' => $category, 'room' => $room?->number, 'area' => $area, 'warranty_until' => $warrantyUntil, 'meter_unit' => $meterUnit]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function retire(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not keep the assets.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $reason, $lock): void {
            $this->store->lock($property, strtolower($id));
            $asset = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Asset not found.');
            $now = $this->clock->nowUtc();

            if ($asset['status'] !== 'active') {
                throw Refusal::stateConflict('This asset is retired already.');
            }

            if (! $this->store->update($property, $asset['id'], $lock, ['status' => 'retired', 'retired_on' => $this->businessDate->current($property)->toString(), 'retired_reason' => $reason], $now)) {
                throw Refusal::stateConflict('This asset changed after you opened it. Reload it.');
            }

            // Its routine care stops with it.
            foreach ($this->store->plans($property, $asset['id']) as $p) {
                if ((bool) $p['is_active']) {
                    $this->store->updatePlan($property, $p['id'], (int) $p['lock_version'], ['is_active' => false], $now);
                }
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'asset.retired', 'asset', $asset['id'], ['status' => 'active'], ['status' => 'retired', 'number' => $asset['number']], $reason));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function readMeter(PropertyId $property, string $actorId, string $id, int $reading): array
    {
        $this->view($property, $actorId);

        if (! $this->access->may($property, $actorId, MaintenanceAccess::PERFORM) && ! $this->access->may($property, $actorId, MaintenanceAccess::MANAGE)) {
            throw Refusal::forbidden('This person may not record meter readings.');
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $reading): void {
            $this->store->lock($property, strtolower($id));
            $asset = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Asset not found.');

            if ($asset['meter_unit'] === null) {
                throw Refusal::stateConflict('This asset has no meter.');
            }

            if ($asset['status'] !== 'active') {
                throw Refusal::stateConflict('This asset is retired.');
            }

            $current = $this->store->currentReading($property, $asset['id']);

            if ($reading < 0 || $reading > 9_000_000_000 || ($current !== null && $reading < $current)) {
                throw Refusal::invalid($current === null ? 'Give the reading as a whole number.' : "A reading never goes below the one before ({$current}).", ['reading']);
            }

            $this->store->addReading($property, ['id' => $this->ids->next(), 'asset_id' => $asset['id'], 'reading' => $reading, 'read_on' => $this->businessDate->current($property)->toString(), 'read_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'asset.meter_read', 'asset', $asset['id'], ['reading' => $current], ['reading' => $reading, 'unit' => $asset['meter_unit'], 'number' => $asset['number']]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function addPlan(PropertyId $property, string $actorId, string $assetId, string $title, ?string $description, string $category, string $priority, string $trigger, int $interval, int $leadDays, ?string $firstDue): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not set preventive plans.');
        $title = trim($title);
        $description = $this->text($description, 300, 'description');

        if ($title === '' || mb_strlen($title) > 80) {
            throw Refusal::invalid('Name the care, in at most 80 characters.', ['title']);
        }

        if (! in_array($category, WorkOrderService::CATEGORIES, true) || ! in_array($priority, WorkOrderService::PRIORITIES, true) || ! in_array($trigger, self::TRIGGERS, true)) {
            throw Refusal::invalid('Choose the kind of work, the priority and what triggers it.', ['category', 'priority', 'trigger_kind']);
        }

        if ($interval < 1 || $interval > ($trigger === 'calendar' ? 3650 : 1_000_000) || $leadDays < 0 || $leadDays > 60) {
            throw Refusal::invalid($trigger === 'calendar' ? 'Every 1 to 3650 days, and the work order is made up to 60 days before.' : 'Every 1 to 1,000,000 units of the meter.', ['interval_value', 'lead_days']);
        }

        $actor = strtolower($actorId);
        $today = $this->businessDate->current($property)->toString();
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $assetId, $title, $description, $category, $priority, $trigger, $interval, $leadDays, $firstDue, $today, $id): void {
            $this->store->lock($property, strtolower($assetId));
            $asset = $this->store->find($property, strtolower($assetId)) ?? throw Refusal::notFound('Asset not found.');

            if ($asset['status'] !== 'active') {
                throw Refusal::stateConflict('This asset is retired.');
            }

            $row = ['id' => $id, 'asset_id' => $asset['id'], 'title' => $title, 'description' => $description, 'category' => $category, 'priority' => $priority, 'trigger_kind' => $trigger, 'interval_value' => $interval, 'lead_days' => $leadDays, 'next_due_on' => null, 'last_meter' => null, 'created_by' => $actor];

            if ($trigger === 'calendar') {
                $due = $firstDue === null || $firstDue === '' ? (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify("+{$interval} days") : $this->date($firstDue, 'first_due_on');
                $row['next_due_on'] = $due->format('Y-m-d');
                $row['lead_days'] = $leadDays;
            } else {
                if ($asset['meter_unit'] === null) {
                    throw Refusal::invalid('This asset has no meter, so care cannot be set by its meter.', ['trigger_kind']);
                }

                $row['last_meter'] = $this->store->currentReading($property, $asset['id']) ?? 0;
                $row['lead_days'] = 0;
            }

            $this->store->addPlan($property, $row, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'pm_plan.created', 'pm_plan', $id, null, ['asset' => $asset['number'], 'title' => $title, 'trigger' => $trigger, 'interval' => $interval, 'next_due_on' => $row['next_due_on'], 'last_meter' => $row['last_meter']]));
        });

        return $this->show($property, $actorId, $assetId);
    }

    /** @return array<string, mixed> */
    public function setPlanActive(PropertyId $property, string $actorId, string $planId, bool $active, int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not set preventive plans.');
        $plan = $this->store->plan($property, strtolower($planId)) ?? throw Refusal::notFound('Plan not found.');
        $asset = $this->store->find($property, $plan['asset_id']) ?? throw Refusal::notFound('Asset not found.');

        if ($active && $asset['status'] !== 'active') {
            throw Refusal::stateConflict('This asset is retired.');
        }

        $this->transactions->run(function () use ($property, $actorId, $plan, $active, $lock): void {
            if (! $this->store->updatePlan($property, $plan['id'], $lock, ['is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This plan changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), $active ? 'pm_plan.resumed' : 'pm_plan.paused', 'pm_plan', $plan['id'], ['active' => (bool) $plan['is_active']], ['active' => $active, 'title' => $plan['title']]));
        });

        return $this->show($property, $actorId, $plan['asset_id']);
    }

    private function view(PropertyId $property, string $actorId): void
    {
        $this->access->assertProperty($property);

        foreach ([MaintenanceAccess::MANAGE, MaintenanceAccess::PERFORM, MaintenanceAccess::REPORT] as $p) {
            if ($this->access->may($property, $actorId, $p)) {
                return;
            }
        }

        throw Refusal::forbidden('This person may not see the assets.');
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private function summary(array $a, string $today): array
    {
        $warranty = $a['warranty_until'];
        $left = $warranty === null ? null : (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable((string) $warranty))->format('%r%a');

        return [
            'id' => $a['id'], 'number' => $a['number'], 'name' => $a['name'], 'category' => $a['category'], 'serial' => $a['serial'], 'room_id' => $a['room_id'], 'room' => $a['room_number'], 'area' => $a['area'],
            'acquired_on' => $a['acquired_on'], 'warranty_until' => $warranty, 'warranty' => $left === null ? null : ($left < 0 ? 'expired' : ($left <= self::WARRANTY_FLAG_DAYS ? 'ending' : 'valid')), 'meter_unit' => $a['meter_unit'],
            'reading' => $a['reading'] ?? null, 'status' => $a['status'], 'next_due_on' => $a['next_due_on'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function planView(array $p, ?int $current, string $today): array
    {
        $calendar = $p['trigger_kind'] === 'calendar';

        return [
            'id' => $p['id'], 'title' => $p['title'], 'description' => $p['description'], 'category' => $p['category'], 'priority' => $p['priority'], 'trigger' => $p['trigger_kind'], 'interval' => (int) $p['interval_value'], 'lead_days' => (int) $p['lead_days'],
            'next_due_on' => $p['next_due_on'], 'next_meter' => $calendar ? null : (int) $p['last_meter'] + (int) $p['interval_value'], 'last_done_on' => $p['last_done_on'], 'active' => (bool) $p['is_active'], 'lock_version' => (int) $p['lock_version'],
            'due' => $calendar ? $today >= DateMath::format('Y-m-d', $p['next_due_on'].' -'.(int) $p['lead_days'].' days') : ($current ?? 0) >= (int) $p['last_meter'] + (int) $p['interval_value'],
        ];
    }

    private function text(?string $text, int $max, string $field): ?string
    {
        $text = $text === null ? null : trim($text);
        $text = $text === '' ? null : $text;

        if ($text !== null && mb_strlen($text) > $max) {
            throw Refusal::invalid("This is at most {$max} characters.", [$field]);
        }

        return $text;
    }

    private function date(string $value, string $field): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $d;
    }
}
