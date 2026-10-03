<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

final readonly class DamageReportService implements DamageReporting
{
    public function __construct(private WorkOrderService $workOrders, private WorkOrderStore $orders, private MaintenanceAccess $access) {}

    public function report(PropertyId $property, string $actorId, string $department, ?string $roomId, ?string $area, string $category, string $title, ?string $detail, bool $urgent, ?string $photo, ?string $photoName): array
    {
        $this->access->assertProperty($property);
        $made = $this->workOrders->report($property, $actorId, $title, $detail, $category, $department, $roomId, $area, $urgent ? 'urgent' : 'normal', $photo, $photoName, null, true);

        $id = (string) $made['work_order']['id'];

        return ['id' => $id, 'number' => (string) ($this->orders->find($property, $id)['number'] ?? '')];
    }

    public function reportedBy(PropertyId $property, string $actorId, string $department, int $limit): array
    {
        $this->access->assertProperty($property);
        $mine = array_filter($this->orders->list($property, null, strtolower($actorId), 200), static fn (array $w): bool => $w['reported_by'] === strtolower($actorId) && $w['reporter_department'] === $department);

        return array_map(static fn (array $w): array => [
            'id' => $w['id'], 'number' => $w['number'], 'title' => $w['title'], 'category' => $w['category'], 'room' => $w['room_number'], 'area' => $w['area'],
            'state' => match ($w['status']) {
                'open', 'assigned' => 'open',
                'in_progress', 'on_hold' => 'in_progress',
                'done' => 'done',
                default => 'cancelled',
            },
            'reported_at' => (new DateTimeImmutable((string) $w['reported_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ], array_slice(array_values($mine), 0, $limit));
    }
}
