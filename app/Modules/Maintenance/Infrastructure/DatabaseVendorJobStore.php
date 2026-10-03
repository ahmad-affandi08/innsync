<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Infrastructure;

use App\Modules\Maintenance\Application\VendorJobStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class DatabaseVendorJobStore implements VendorJobStore
{
    private const OPEN = ['quoting', 'pending_approval', 'approved', 'scheduled'];

    public function add(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('maintenance_vendor_jobs', [...$row, 'property_id' => $property->toString(), 'lock_version' => 0, 'created_at' => $at->format('Y-m-d H:i:s.u'), 'updated_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $r = DB::table('maintenance_vendor_jobs')->where('property_id', $property->toString())->where('id', $id)->first();

        return $r === null ? null : (array) $r;
    }

    public function lock(PropertyId $property, string $id): void
    {
        DB::table('maintenance_vendor_jobs')->where('property_id', $property->toString())->where('id', $id)->lockForUpdate()->value('id');
    }

    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool
    {
        return DB::table('maintenance_vendor_jobs')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $lock)
            ->update([...$fields, 'lock_version' => $lock + 1, 'updated_at' => $at->format('Y-m-d H:i:s.u')]) === 1;
    }

    public function list(PropertyId $property, ?string $status, ?string $workOrderId, int $limit): array
    {
        return DB::table('maintenance_vendor_jobs as j')->join('maintenance_work_orders as w', 'w.id', '=', 'j.work_order_id')->where('j.property_id', $property->toString())
            ->when($status !== null, static fn ($q) => $q->where('j.status', $status))->when($workOrderId !== null, static fn ($q) => $q->where('j.work_order_id', $workOrderId))
            ->orderByDesc('j.created_at')->orderByDesc('j.id')->limit($limit)->select('j.*', 'w.number as work_order_number', 'w.title as work_order_title', 'w.status as work_order_status')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function addQuote(PropertyId $property, array $row, DateTimeImmutable $at): bool
    {
        return $this->insert('maintenance_vendor_quotes', [...$row, 'created_at' => $at->format('Y-m-d H:i:s.u')]);
    }

    public function quotes(PropertyId $property, string $jobId): array
    {
        return DB::table('maintenance_vendor_quotes as q')->join('maintenance_vendor_jobs as j', 'j.id', '=', 'q.job_id')->where('j.property_id', $property->toString())->where('q.job_id', $jobId)
            ->orderBy('q.amount_minor')->orderBy('q.id')->select('q.*')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    public function openCount(PropertyId $property, string $workOrderId): int
    {
        return DB::table('maintenance_vendor_jobs')->where('property_id', $property->toString())->where('work_order_id', $workOrderId)->whereIn('status', self::OPEN)->count();
    }

    public function doneBetween(PropertyId $property, string $from, string $to): array
    {
        return DB::table('maintenance_vendor_jobs as j')->join('maintenance_work_orders as w', 'w.id', '=', 'j.work_order_id')->where('j.property_id', $property->toString())->where('j.status', 'done')->whereBetween('j.done_on', [$from, $to])
            ->select('j.supplier_name', 'j.actual_minor', 'j.agreed_minor', 'j.over_quote', 'w.category')->get()->map(static fn ($r): array => (array) $r)->all();
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): bool
    {
        try {
            DB::table($table)->insert($row);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
