<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Modules\FrontOffice\Application\Inventory\RoomBlocking;
use App\Modules\Property\Application\Catalog\RoomCatalogReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
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
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The work orders of maintenance (FR-MTC-001 to -006). Anyone may report something broken, with where it is (a room or a place), what, which kind of work and a photo; the report is
 * one work order with a number, a priority and the time that priority may take (the service level, a baseline the owner changes), counted from the report. The manager gives it to
 * a technician, who starts it, may put it on hold with a reason (waiting for parts, a vendor, or access to the room) and finishes it: a work order is never done without a
 * photo of the work and a note. A manager may take the room of a work order off sale, out of order or out of service until a date, through the front office, and the room goes back
 * on sale when the work is done. Every step is kept in the history of the work order and in the audit trail, and a work order is never deleted.
 */
final readonly class WorkOrderService
{
    public const CATEGORIES = ['electrical', 'plumbing', 'hvac', 'furniture', 'appliance', 'structural', 'it', 'other'];

    public const DEPARTMENTS = ['front_office', 'housekeeping', 'laundry', 'fnb', 'kitchen', 'engineering', 'finance', 'hr', 'other'];

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    public const HOLD_REASONS = ['waiting_parts', 'waiting_vendor', 'waiting_access'];

    /** The minutes each priority may take until the owner sets them: an hour, four hours, a day and three days. */
    public const BASELINE_SLA = ['urgent' => 60, 'high' => 240, 'normal' => 1440, 'low' => 4320];

    /** Until the owner sets them: warn at 75 percent of the time, escalate at 100, and the night shift from 22:00 to 06:00. */
    public const BASELINE_ESCALATION = ['warn' => 75, 'escalate' => 100, 'night_from' => 22, 'night_to' => 6];

    public const PHOTO_PURPOSE = 'maintenance.workorder';

    public const PHOTO_MAX_BYTES = 5_242_880;

    private const OPEN = ['open', 'assigned', 'in_progress', 'on_hold'];

    public function __construct(
        private WorkOrderStore $store,
        private AssetStore $assets,
        private MaintenanceAccess $access,
        private RoomCatalogReader $rooms,
        private RoomBlocking $blocking,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
        private StoredFileRepository $files,
        private RetentionPolicies $retention,
        private PermissionChecker $permissions,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $caps = $this->capabilities($property, $actorId);
        $now = $this->clock->nowUtc();
        $rows = $this->store->list($property, null, $caps['manage'] ? null : strtolower($actorId), 300);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([...array_column($rows, 'reported_by'), ...array_column($rows, 'assigned_to')]))));
        $counts = array_fill_keys(['open', 'assigned', 'in_progress', 'on_hold', 'done', 'cancelled'], 0);
        $overdue = 0;

        foreach ($rows as $r) {
            $counts[$r['status']]++;
            $overdue += $this->isOverdue($r, $now) ? 1 : 0;
        }

        return [
            'work_orders' => array_map(fn (array $r): array => $this->summary($r, $names, $now), $rows),
            'counts' => $counts, 'overdue' => $overdue, 'me' => strtolower($actorId),
            'rooms' => $caps['may_report'] ? array_values(array_map(static fn ($r): array => ['id' => $r->id, 'number' => $r->number], $this->rooms->activeRooms($property))) : [],
            'technicians' => $caps['manage'] ? $this->staff->withPermission($property, MaintenanceAccess::PERFORM) : [],
            'assets' => $caps['may_report'] ? array_values(array_map(static fn (array $a): array => ['id' => $a['id'], 'number' => $a['number'], 'name' => $a['name']], array_filter($this->assets->all($property), static fn (array $a): bool => $a['status'] === 'active'))) : [], 'categories' => self::CATEGORIES, 'departments' => self::DEPARTMENTS, 'priorities' => self::PRIORITIES, 'hold_reasons' => self::HOLD_REASONS,
            'sla' => $this->sla($property), 'sla_is_baseline' => $this->store->settings($property) === null, 'sla_lock_version' => $this->store->settings($property)['lock_version'] ?? null, 'escalation' => $this->escalationSettings($property),
            'business_date' => $this->businessDate->current($property)->toString(),
            'may' => ['report' => $caps['may_report'], 'manage' => $caps['manage'], 'perform' => $caps['perform']],
        ];
    }

    /** @return array<string, mixed> one work order with its history */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $caps = $this->capabilities($property, $actorId);
        $row = $this->visible($property, $actorId, $caps, strtolower($id));
        $events = $this->store->events($property, $row['id']);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([$row['reported_by'], $row['assigned_to'], $row['done_by'], ...array_column($events, 'actor_id')]))));
        $mine = $row['assigned_to'] === strtolower($actorId);
        $open = in_array($row['status'], self::OPEN, true);

        return [
            'work_order' => $this->summary($row, $names, $this->clock->nowUtc()) + [
                'description' => $row['description'], 'hold_note' => $row['hold_note'], 'done_note' => $row['done_note'], 'cancel_reason' => $row['cancel_reason'], 'has_report_photo' => $row['report_photo_file_id'] !== null, 'has_done_photo' => $row['done_photo_file_id'] !== null,
                'block' => $row['block_id'] === null ? null : ['id' => $row['block_id'], 'kind' => $row['block_kind']], 'asset' => $this->assetLabel($property, $row['asset_id']), 'preventive' => $row['plan_id'] !== null, 'done_by' => $names[$row['done_by'] ?? ''] ?? null, 'done_at' => $this->utc($row['done_at']), 'started_at' => $this->utc($row['started_at']),
            ],
            'events' => array_map(fn (array $e): array => ['kind' => $e['kind'], 'note' => $e['note'], 'by' => $names[$e['actor_id']] ?? null, 'at' => $this->utc($e['at'])], $events),
            'may' => [
                'assign' => $caps['manage'] && $open, 'prioritize' => $caps['manage'] && $open, 'cancel' => $caps['manage'] && $open,
                'start' => $open && in_array($row['status'], ['open', 'assigned'], true) && ($mine || $caps['manage']) && $row['assigned_to'] !== null,
                'hold' => $row['status'] === 'in_progress' && ($mine || $caps['manage']), 'resume' => $row['status'] === 'on_hold' && ($mine || $caps['manage']),
                'complete' => $row['status'] === 'in_progress' && ($mine || $caps['manage']), 'block' => $caps['manage'] && $open && $row['room_id'] !== null && $row['block_id'] === null,
                'release' => $caps['manage'] && $open && $row['block_id'] !== null,
            ],
            'technicians' => $caps['manage'] ? $this->staff->withPermission($property, MaintenanceAccess::PERFORM) : [], 'business_date' => $this->businessDate->current($property)->toString(),
        ];
    }

    /** @return array<string, mixed> */
    public function report(PropertyId $property, string $actorId, string $title, ?string $description, string $category, string $department, ?string $roomId, ?string $area, string $priority, ?string $photo, ?string $photoName, ?string $assetId = null): array
    {
        $caps = $this->capabilities($property, $actorId);

        if (! $caps['may_report']) {
            throw Refusal::forbidden('This person may not report faults.');
        }

        $title = trim($title);
        $description = $description === null || trim($description) === '' ? null : trim($description);
        $area = $area === null || trim($area) === '' ? null : trim($area);
        $roomId = $roomId === null || $roomId === '' ? null : strtolower($roomId);

        if ($title === '' || mb_strlen($title) > 80 || ($description !== null && mb_strlen($description) > 500) || ($area !== null && mb_strlen($area) > 80)) {
            throw Refusal::invalid('Say what is wrong in a title of at most 80 characters; the details are at most 500 and the place at most 80.', ['title', 'description', 'area']);
        }

        if (! in_array($category, self::CATEGORIES, true)) {
            throw Refusal::invalid('Choose the kind of work.', ['category']);
        }

        if (! in_array($department, self::DEPARTMENTS, true)) {
            throw Refusal::invalid('Choose the department that reports it.', ['reporter_department']);
        }

        if (! in_array($priority, self::PRIORITIES, true)) {
            throw Refusal::invalid('Choose how urgent it is.', ['priority']);
        }

        $room = null;
        $asset = null;

        if ($assetId !== null && $assetId !== '') {
            $asset = $this->assets->find($property, strtolower($assetId));

            if ($asset === null || $asset['status'] !== 'active') {
                throw Refusal::invalid('Choose an asset that is in use.', ['asset_id']);
            }

            // Where the asset is, when the report does not say.
            if ($roomId === null && $area === null) {
                $roomId = $asset['room_id'];
                $area = $asset['area'];
            }
        }

        if ($roomId !== null) {
            $room = $this->rooms->room($property, $roomId) ?? throw Refusal::invalid('Choose a room of this property.', ['room_id']);
        }

        if ($room === null && $area === null) {
            throw Refusal::invalid('Say where it is: choose a room or name the place.', ['room_id', 'area']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $file = $this->storePhoto($property, $actor, $id, $photo, $photoName, 'photo');
        $now = $this->clock->nowUtc();

        $this->transactions->run(function () use ($property, $actor, $id, $title, $description, $category, $department, $room, $area, $priority, $file, $now, $asset): void {
            $number = $this->numbers->next($property, 'WO');
            $due = $now->modify('+'.$this->sla($property)[$priority].' minutes');
            $this->store->add($property, [
                'id' => $id, 'number' => $number, 'title' => $title, 'description' => $description, 'category' => $category, 'reporter_department' => $department, 'room_id' => $room?->id, 'room_number' => $room?->number, 'area' => $area,
                'priority' => $priority, 'reported_at' => $now, 'reported_by' => $actor, 'due_at' => $due, 'report_photo_file_id' => $file?->id, 'asset_id' => $asset['id'] ?? null,
            ], $now);
            $this->event($property, $id, 'reported', $title, $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'work_order.reported', 'work_order', $id, null, ['number' => $number, 'title' => $title, 'priority' => $priority, 'room' => $room?->number, 'area' => $area, 'department' => $department, 'has_photo' => $file !== null]));
        });

        return $this->show($property, $actorId, $id);
    }

    /**
     * The work order a preventive plan calls for. Made by the system for the person who set the plan; once for each cycle of the plan.
     *
     * @param  array<string, mixed>  $plan  a plan with the asset's name, number and place, as `AssetStore::plans` gives it
     * @return bool false when the cycle has its work order already
     */
    public function createPreventive(PropertyId $property, array $plan, string $marker): bool
    {
        $id = $this->ids->next();
        $now = $this->clock->nowUtc();
        $made = false;
        $title = mb_substr("{$plan['title']} · {$plan['asset_name']}", 0, 80);

        $this->transactions->run(function () use ($property, $plan, $marker, $id, $now, $title, &$made): void {
            if ($this->store->cycleTaken($property, $plan['id'], $marker)) {
                return;
            }

            $number = $this->numbers->next($property, 'WO');
            $this->store->add($property, [
                'id' => $id, 'number' => $number, 'title' => $title, 'description' => $plan['description'], 'category' => $plan['category'], 'reporter_department' => 'engineering', 'room_id' => $plan['asset_room_id'], 'room_number' => $plan['asset_room_number'],
                'area' => $plan['asset_room_id'] === null ? ($plan['asset_area'] ?? "Asset {$plan['asset_number']}") : null, 'priority' => $plan['priority'], 'reported_at' => $now, 'reported_by' => $plan['created_by'],
                'due_at' => $now->modify('+'.$this->sla($property)[$plan['priority']].' minutes'), 'asset_id' => $plan['asset_id'], 'plan_id' => $plan['id'], 'pm_due' => $marker,
            ], $now);
            $this->event($property, $id, 'reported', "Preventive: {$plan['title']}", $plan['created_by'], $now);
            $this->audit->record(new AuditEntry($property->toString(), null, 'work_order.reported', 'work_order', $id, null, ['number' => $number, 'title' => $title, 'priority' => $plan['priority'], 'preventive_plan' => $plan['id'], 'cycle' => $marker, 'asset' => $plan['asset_number']]));
            $made = true;
        });

        return $made;
    }

    /** @return array<string, mixed> */
    public function assign(PropertyId $property, string $actorId, string $id, string $technicianId, int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not give work orders to technicians.');
        $technicianId = strtolower($technicianId);

        $names = array_column($this->staff->withPermission($property, MaintenanceAccess::PERFORM), 'name', 'id');

        if (! isset($names[$technicianId])) {
            throw Refusal::invalid('Choose a technician.', ['technician_id']);
        }

        return $this->change($property, $actorId, $id, $lock, self::OPEN, function (array $w) use ($technicianId, $names): array {
            return [['assigned_to' => $technicianId, 'assigned_at' => $this->clock->nowUtc(), 'status' => $w['status'] === 'open' ? 'assigned' : $w['status']], 'assigned', $names[$technicianId]];
        }, 'work_order.assigned');
    }

    /** @return array<string, mixed> */
    public function reprioritize(PropertyId $property, string $actorId, string $id, string $priority, int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not change priorities.');

        if (! in_array($priority, self::PRIORITIES, true)) {
            throw Refusal::invalid('Choose how urgent it is.', ['priority']);
        }

        return $this->change($property, $actorId, $id, $lock, self::OPEN, function (array $w) use ($property, $priority): array {
            $due = (new DateTimeImmutable((string) $w['reported_at'], new DateTimeZone('UTC')))->modify('+'.$this->sla($property)[$priority].' minutes');

            return [['priority' => $priority, 'due_at' => $due], 'reprioritized', $w['priority'].' → '.$priority];
        }, 'work_order.reprioritized');
    }

    /** @return array<string, mixed> */
    public function start(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        return $this->change($property, $actorId, $id, $lock, ['open', 'assigned'], function (array $w) use ($actorId): array {
            $this->mayWork($w, $actorId);

            if ($w['assigned_to'] === null) {
                throw Refusal::stateConflict('Give this work order to a technician first.');
            }

            return [['status' => 'in_progress', 'started_at' => $w['started_at'] ?? $this->clock->nowUtc(), 'hold_reason' => null, 'hold_note' => null], 'started', null];
        }, 'work_order.started');
    }

    /** @return array<string, mixed> */
    public function hold(PropertyId $property, string $actorId, string $id, string $reason, ?string $note, int $lock): array
    {
        if (! in_array($reason, self::HOLD_REASONS, true)) {
            throw Refusal::invalid('Say what the work waits for: parts, a vendor or access to the room.', ['reason']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        return $this->change($property, $actorId, $id, $lock, ['in_progress'], function (array $w) use ($actorId, $reason, $note): array {
            $this->mayWork($w, $actorId);

            return [['status' => 'on_hold', 'hold_reason' => $reason, 'hold_note' => $note], 'held', $reason.($note === null ? '' : ': '.$note)];
        }, 'work_order.held');
    }

    /** @return array<string, mixed> */
    public function resume(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        return $this->change($property, $actorId, $id, $lock, ['on_hold'], function (array $w) use ($actorId): array {
            $this->mayWork($w, $actorId);

            return [['status' => 'in_progress', 'hold_reason' => null, 'hold_note' => null], 'resumed', null];
        }, 'work_order.resumed');
    }

    /** @return array<string, mixed> */
    public function complete(PropertyId $property, string $actorId, string $id, string $note, ?string $photo, ?string $photoName, int $lock): array
    {
        $caps = $this->capabilities($property, $actorId);
        $note = trim($note);

        if ($note === '' || mb_strlen($note) > 300) {
            throw Refusal::invalid('Say what was done, in at most 300 characters.', ['note']);
        }

        if ($photo === null || $photo === '') {
            throw Refusal::invalid('Attach a photo of the finished work. A work order is not closed without one.', ['photo']);
        }

        $row = $this->visible($property, $actorId, $caps, strtolower($id));
        $this->mayWork($row, $actorId);
        $file = $this->storePhoto($property, strtolower($actorId), $row['id'], $photo, $photoName, 'photo') ?? throw Refusal::invalid('Attach a photo of the finished work.', ['photo']);

        return $this->change($property, $actorId, $id, $lock, ['in_progress'], function (array $w) use ($property, $actorId, $note, $file): array {
            $now = $this->clock->nowUtc();
            $fields = ['status' => 'done', 'done_at' => $now, 'done_by' => strtolower($actorId), 'done_note' => $note, 'done_photo_file_id' => $file->id, 'hold_reason' => null, 'hold_note' => null];

            // The room goes back on sale when the work that took it off sale is done.
            if ($w['block_id'] !== null) {
                $this->releaseQuietly($property, strtolower($actorId), $w, "Work order {$w['number']} is done", 'Back on sale with the work done');
            }

            $this->setExpiry($property, [$w['report_photo_file_id'], $file->id]);
            $this->rollPlan($property, $w);

            return [$fields, 'done', $note];
        }, 'work_order.done');
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, string $reason, int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not cancel work orders.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        return $this->change($property, $actorId, $id, $lock, self::OPEN, function (array $w) use ($property, $actorId, $reason): array {
            if ($w['block_id'] !== null) {
                $this->releaseQuietly($property, strtolower($actorId), $w, "Work order {$w['number']} was cancelled", 'Back on sale: the work order was cancelled');
            }

            $this->setExpiry($property, [$w['report_photo_file_id']]);

            // The cycle of a preventive plan that was cancelled is free again, so the care falls due again.
            return [['status' => 'cancelled', 'cancel_reason' => $reason, 'pm_due' => null], 'cancelled', $reason];
        }, 'work_order.cancelled');
    }

    /**
     * Takes the room of the work order off sale until a date. @return array<string, mixed> the work order as `show` gives it, with the nights that are now oversold
     */
    public function blockRoom(PropertyId $property, string $actorId, string $id, string $kind, string $until, int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not take rooms off sale.');
        $oversold = [];

        $view = $this->change($property, $actorId, $id, $lock, self::OPEN, function (array $w) use ($property, $actorId, $kind, $until, &$oversold): array {
            if ($w['room_id'] === null) {
                throw Refusal::stateConflict('This work order is not about a room.');
            }

            if ($w['block_id'] !== null) {
                throw Refusal::stateConflict('This room is off sale for this work order already.');
            }

            $from = $this->businessDate->current($property)->toString();
            $done = $this->blocking->block($property, strtolower($actorId), $w['room_id'], $kind, $from, $until, "Work order {$w['number']}: {$w['title']}");
            $oversold = $done['oversold_nights'];
            $this->store->addRoomBlock($property, ['id' => $this->ids->next(), 'work_order_id' => $w['id'], 'block_id' => $done['block_id'], 'room_id' => $w['room_id'], 'room_number' => (string) $w['room_number'], 'kind' => $kind, 'from_date' => $from, 'until_date' => $until]);

            return [['block_id' => $done['block_id'], 'block_kind' => $kind], 'room_blocked', $kind.' until '.$until];
        }, 'work_order.room_blocked');

        return [...$view, 'oversold_nights' => $oversold];
    }

    /** @return array<string, mixed> */
    public function releaseRoom(PropertyId $property, string $actorId, string $id, int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not put rooms back on sale.');

        return $this->change($property, $actorId, $id, $lock, self::OPEN, function (array $w) use ($property, $actorId): array {
            if ($w['block_id'] === null) {
                throw Refusal::stateConflict('This work order has not taken the room off sale.');
            }

            $this->blocking->release($property, strtolower($actorId), $w['block_id'], "Work order {$w['number']}: put back on sale");
            $this->store->releaseRoomBlock($property, $w['block_id'], $this->businessDate->current($property)->toString());

            return [['block_id' => null, 'block_kind' => null], 'room_released', null];
        }, 'work_order.room_released');
    }

    /**
     * @param  array{urgent: int, high: int, normal: int, low: int, warn: int, escalate: int, night_from: int, night_to: int}  $v
     * @return array<string, mixed>
     */
    public function saveSla(PropertyId $property, string $actorId, array $v, ?int $lock): array
    {
        $this->access->require($property, $actorId, MaintenanceAccess::MANAGE, 'This person may not set the service levels.');

        foreach ([$v['urgent'], $v['high'], $v['normal'], $v['low']] as $m) {
            if ($m < 5 || $m > 43200) {
                throw Refusal::invalid('Each time is between 5 minutes and 30 days.', ['sla']);
            }
        }

        if (! ($v['urgent'] <= $v['high'] && $v['high'] <= $v['normal'] && $v['normal'] <= $v['low'])) {
            throw Refusal::invalid('A more urgent priority may not take longer than a less urgent one.', ['sla']);
        }

        if ($v['warn'] < 10 || $v['warn'] > 100 || $v['escalate'] < 50 || $v['escalate'] > 300 || $v['warn'] > $v['escalate']) {
            throw Refusal::invalid('Warn between 10 and 100 percent of the time, and escalate between 50 and 300 percent, never before the warning.', ['warn_percent', 'escalate_percent']);
        }

        if ($v['night_from'] < 0 || $v['night_from'] > 23 || $v['night_to'] < 0 || $v['night_to'] > 23) {
            throw Refusal::invalid('The night shift starts and ends at an hour from 0 to 23.', ['night_from_hour', 'night_to_hour']);
        }

        $before = $this->store->settings($property);

        $this->transactions->run(function () use ($property, $actorId, $v, $lock, $before): void {
            if (($before['lock_version'] ?? null) !== $lock || ! $this->store->saveSettings($property, $v, $before === null ? null : $before['lock_version'], strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The service levels changed after you opened them. Reload them.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'maintenance_sla.changed', 'maintenance_settings', $property->toString(), $before === null ? [...self::BASELINE_SLA, ...self::BASELINE_ESCALATION] : array_diff_key($before, ['lock_version' => 1]), $v));
        });

        return ['sla' => $this->sla($property), 'escalation' => $this->escalationSettings($property), 'sla_lock_version' => $this->store->settings($property)['lock_version'] ?? null];
    }

    public function photo(PropertyId $property, string $actorId, string $id, string $which): FileContent
    {
        $caps = $this->capabilities($property, $actorId);
        $row = $this->visible($property, $actorId, $caps, strtolower($id));
        $fileId = $which === 'done' ? $row['done_photo_file_id'] : $row['report_photo_file_id'];

        if ($fileId === null) {
            throw Refusal::notFound('This work order has no such photo.');
        }

        $policy = new class($this->permissions, $property) implements FileAccessPolicy
        {
            public function __construct(private PermissionChecker $permissions, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                foreach ([MaintenanceAccess::MANAGE, MaintenanceAccess::PERFORM, MaintenanceAccess::REPORT] as $p) {
                    if ($this->permissions->allowsInProperty($actorId, $p, $this->property)) {
                        return true;
                    }
                }

                return false;
            }
        };

        try {
            return $this->downloadFile->execute($property, $fileId, strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The photo is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not see work orders.');
        }
    }

    /**
     * Runs one change of a work order: the right state, the version the person saw, the new fields, the history and the audit.
     *
     * @param  list<string>  $from
     * @param  callable(array<string, mixed>): array{0: array<string, mixed>, 1: string, 2: string|null}  $decide
     * @return array<string, mixed>
     */
    private function change(PropertyId $property, string $actorId, string $id, int $lock, array $from, callable $decide, string $auditAction): array
    {
        $caps = $this->capabilities($property, $actorId);
        $actor = strtolower($actorId);
        $id = strtolower($id);

        $this->transactions->run(function () use ($property, $actor, $id, $lock, $from, $decide, $auditAction, $caps): void {
            $this->store->lock($property, $id);
            $w = $this->visible($property, $actor, $caps, $id);

            if (! in_array($w['status'], $from, true)) {
                throw Refusal::stateConflict($w['status'] === 'done' || $w['status'] === 'cancelled' ? 'This work order is closed.' : 'This work order is not at the step you chose. Reload it.');
            }

            if ((int) $w['lock_version'] !== $lock) {
                throw Refusal::stateConflict('This work order changed after you opened it. Reload it.');
            }

            [$fields, $kind, $note] = $decide($w);
            $now = $this->clock->nowUtc();

            if (! $this->store->update($property, $id, $lock, $fields, $now)) {
                throw Refusal::stateConflict('This work order changed after you opened it. Reload it.');
            }

            $this->event($property, $id, $kind, $note, $actor, $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, $auditAction, 'work_order', $id, ['status' => $w['status'], 'priority' => $w['priority'], 'assigned_to' => $w['assigned_to']], ['number' => $w['number'], ...array_map(static fn ($v) => $v instanceof DateTimeImmutable ? $v->format('Y-m-d\TH:i:s\Z') : $v, $fields)], $note));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array{manage: bool, perform: bool, may_report: bool} */
    private function capabilities(PropertyId $property, string $actorId): array
    {
        $this->access->assertProperty($property);
        $manage = $this->access->may($property, $actorId, MaintenanceAccess::MANAGE);
        $perform = $this->access->may($property, $actorId, MaintenanceAccess::PERFORM);
        $report = $this->access->may($property, $actorId, MaintenanceAccess::REPORT);

        if (! $manage && ! $perform && ! $report) {
            throw Refusal::forbidden('This person may not see work orders.');
        }

        return ['manage' => $manage, 'perform' => $perform, 'may_report' => $manage || $perform || $report];
    }

    /**
     * @param  array{manage: bool, perform: bool, may_report: bool}  $caps
     * @return array<string, mixed>
     */
    private function visible(PropertyId $property, string $actorId, array $caps, string $id): array
    {
        $row = $this->store->find($property, $id) ?? throw Refusal::notFound('Work order not found.');
        $actor = strtolower($actorId);

        if (! $caps['manage'] && $row['reported_by'] !== $actor && $row['assigned_to'] !== $actor) {
            throw Refusal::notFound('Work order not found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $w */
    private function mayWork(array $w, string $actorId): void
    {
        if ($w['assigned_to'] !== strtolower($actorId) && ! $this->access->may(PropertyId::fromString((string) $w['property_id']), $actorId, MaintenanceAccess::MANAGE)) {
            throw Refusal::forbidden('A work order is worked by the technician it was given to.');
        }
    }

    /** The care of a plan was done: it falls due again an interval from today, or an interval further on the meter. @param array<string, mixed> $w */
    private function rollPlan(PropertyId $property, array $w): void
    {
        if ($w['plan_id'] === null) {
            return;
        }

        $plan = $this->assets->plan($property, $w['plan_id']);

        if ($plan === null) {
            return;
        }

        $today = $this->businessDate->current($property)->toString();
        $fields = $plan['trigger_kind'] === 'calendar'
            ? ['last_done_on' => $today, 'next_due_on' => date('Y-m-d', strtotime($today.' +'.(int) $plan['interval_value'].' days'))]
            : ['last_done_on' => $today, 'last_meter' => max((int) $plan['last_meter'], $this->assets->currentReading($property, $plan['asset_id']) ?? 0)];
        $this->assets->updatePlan($property, $plan['id'], (int) $plan['lock_version'], $fields, $this->clock->nowUtc());
    }

    /** Puts the room back on sale; a block the front office released already is not an obstacle to closing the work order. @param array<string, mixed> $w */
    private function releaseQuietly(PropertyId $property, string $actor, array $w, string $reason, string $note): void
    {
        try {
            $this->blocking->release($property, $actor, $w['block_id'], $reason);
        } catch (Refusal) {
            $this->store->releaseRoomBlock($property, $w['block_id'], $this->businessDate->current($property)->toString());

            return;
        }

        $this->store->releaseRoomBlock($property, $w['block_id'], $this->businessDate->current($property)->toString());

        $this->event($property, $w['id'], 'room_released', $note, $actor, $this->clock->nowUtc());
    }

    /** @return array{warn: int, escalate: int, night_from: int, night_to: int} */
    private function escalationSettings(PropertyId $property): array
    {
        $s = $this->store->settings($property);

        return $s === null ? self::BASELINE_ESCALATION : ['warn' => $s['warn'], 'escalate' => $s['escalate'], 'night_from' => $s['night_from'], 'night_to' => $s['night_to']];
    }

    /** @return array{urgent: int, high: int, normal: int, low: int} */
    private function sla(PropertyId $property): array
    {
        $s = $this->store->settings($property);

        return $s === null ? self::BASELINE_SLA : ['urgent' => $s['urgent'], 'high' => $s['high'], 'normal' => $s['normal'], 'low' => $s['low']];
    }

    /** @param array<string, mixed> $w */
    private function isOverdue(array $w, DateTimeImmutable $now): bool
    {
        return in_array($w['status'], self::OPEN, true) && (new DateTimeImmutable((string) $w['due_at'], new DateTimeZone('UTC'))) < $now;
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function summary(array $w, array $names, DateTimeImmutable $now): array
    {
        $due = new DateTimeImmutable((string) $w['due_at'], new DateTimeZone('UTC'));

        return [
            'id' => $w['id'], 'number' => $w['number'], 'title' => $w['title'], 'category' => $w['category'], 'department' => $w['reporter_department'], 'room_id' => $w['room_id'], 'room' => $w['room_number'], 'area' => $w['area'],
            'priority' => $w['priority'], 'status' => $w['status'], 'hold_reason' => $w['hold_reason'], 'reported_at' => $this->utc($w['reported_at']), 'reported_by' => $names[$w['reported_by']] ?? null, 'due_at' => $this->utc($w['due_at']),
            'assigned_to' => $w['assigned_to'], 'assigned_name' => $w['assigned_to'] === null ? null : ($names[$w['assigned_to']] ?? null), 'overdue' => $this->isOverdue($w, $now),
            'minutes_left' => in_array($w['status'], self::OPEN, true) ? intdiv($due->getTimestamp() - $now->getTimestamp(), 60) : null, 'lock_version' => (int) $w['lock_version'], 'off_sale' => $w['block_id'] !== null,
        ];
    }

    /** @return array{id: string, number: string, name: string}|null */
    private function assetLabel(PropertyId $property, ?string $assetId): ?array
    {
        $a = $assetId === null ? null : $this->assets->find($property, $assetId);

        return $a === null ? null : ['id' => $a['id'], 'number' => $a['number'], 'name' => $a['name']];
    }

    private function utc(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function event(PropertyId $property, string $id, string $kind, ?string $note, string $actor, DateTimeImmutable $at): void
    {
        $this->store->addEvent($property, ['id' => $this->ids->next(), 'work_order_id' => $id, 'kind' => $kind, 'note' => $note === null ? null : mb_substr($note, 0, 300), 'actor_id' => $actor], $at);
    }

    private function storePhoto(PropertyId $property, string $actor, string $ownerId, ?string $photo, ?string $name, string $field): ?StoredFile
    {
        if ($photo === null || $photo === '') {
            return null;
        }

        try {
            return $this->storeFile->execute(new FileUpload($property, $actor, self::PHOTO_PURPOSE, 'work-order', $ownerId, $photo, new FilePolicy(['image/jpeg', 'image/png'], self::PHOTO_MAX_BYTES, FileSensitivity::Standard, false), $name));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), [$field]);
        }
    }

    /** @param list<string|null> $fileIds */
    private function setExpiry(PropertyId $property, array $fileIds): void
    {
        $anchor = new DateTimeImmutable($this->businessDate->current($property)->toString().' 00:00:00', new DateTimeZone('UTC'));

        foreach ($fileIds as $fileId) {
            if ($fileId !== null) {
                $this->files->setExpiryOnce($property, $fileId, $this->retention->expiryFor($property, 'maintenance_photo', $anchor));
            }
        }
    }
}
