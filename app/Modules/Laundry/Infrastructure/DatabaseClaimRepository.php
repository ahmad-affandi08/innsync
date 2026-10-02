<?php

declare(strict_types=1);

namespace App\Modules\Laundry\Infrastructure;

use App\Modules\Laundry\Application\ClaimRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseClaimRepository implements ClaimRepository
{
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void
    {
        DB::table('laundry_claims')->insert([...$row, 'property_id' => $property->toString(), 'status' => 'open', 'lock_version' => 0, 'created_at' => $at, 'updated_at' => $at]);
    }

    public function find(PropertyId $property, string $id): ?array
    {
        $row = $this->base($property)->where('c.id', $id)->first();

        return $row === null ? null : self::shape($row);
    }

    public function list(PropertyId $property, ?string $status, int $limit): array
    {
        return $this->base($property)->when($status !== null, static fn ($q) => $q->where('c.status', $status))->orderByDesc('c.created_at')->orderByDesc('c.id')->limit($limit)->get()->map(static fn ($r): array => self::shape($r))->all();
    }

    public function ofOrder(PropertyId $property, string $orderId): array
    {
        return $this->base($property)->where('c.order_id', $orderId)->orderBy('c.created_at')->get()->map(static fn ($r): array => self::shape($r))->all();
    }

    public function decide(PropertyId $property, string $id, int $expectedLockVersion, string $status, ?int $approvedMinor, ?string $note, string $actorId, DateTimeImmutable $at): bool
    {
        return DB::table('laundry_claims')->where('property_id', $property->toString())->where('id', $id)->where('lock_version', $expectedLockVersion)->where('status', 'open')
            ->update(['status' => $status, 'approved_minor' => $approvedMinor, 'decision_note' => $note, 'decided_by' => $actorId, 'decided_at' => $at, 'lock_version' => $expectedLockVersion + 1, 'updated_at' => $at]) === 1;
    }

    public function settings(PropertyId $property): ?array
    {
        $row = DB::table('laundry_claim_settings')->where('property_id', $property->toString())->first();

        return $row === null ? null : ['cap_multiple' => (int) $row->cap_multiple, 'lock_version' => (int) $row->lock_version];
    }

    public function saveSettings(PropertyId $property, int $capMultiple, ?int $expectedLockVersion, string $actorId, DateTimeImmutable $at): bool
    {
        $pid = $property->toString();

        if ($expectedLockVersion === null) {
            return DB::table('laundry_claim_settings')->insertOrIgnore(['property_id' => $pid, 'cap_multiple' => $capMultiple, 'lock_version' => 0, 'updated_by' => $actorId, 'created_at' => $at, 'updated_at' => $at]) === 1;
        }

        return DB::table('laundry_claim_settings')->where('property_id', $pid)->where('lock_version', $expectedLockVersion)
            ->update(['cap_multiple' => $capMultiple, 'lock_version' => $expectedLockVersion + 1, 'updated_by' => $actorId, 'updated_at' => $at]) === 1;
    }

    private function base(PropertyId $property): Builder
    {
        return DB::table('laundry_claims as c')->join('laundry_orders as o', 'o.id', '=', 'c.order_id')->join('rooms as r', 'r.id', '=', 'o.room_id')->leftJoin('laundry_order_lines as l', 'l.id', '=', 'c.line_id')
            ->where('c.property_id', $property->toString())->select('c.*', 'o.number as order_number', 'o.status as order_status', 'r.number as room', 'l.item_name', 'l.unit_price_minor as line_unit_minor');
    }

    /** @return array<string, mixed> */
    private static function shape(object $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'order_id' => $r->order_id, 'order_number' => $r->order_number, 'room' => $r->room, 'line_id' => $r->line_id, 'item_name' => $r->item_name, 'pieces' => (int) $r->pieces,
            'kind' => $r->kind, 'description' => $r->description, 'claimed_minor' => (int) $r->claimed_minor, 'currency' => $r->currency_code, 'photo_file_id' => $r->photo_file_id, 'status' => $r->status,
            'approved_minor' => $r->approved_minor === null ? null : (int) $r->approved_minor, 'decision_note' => $r->decision_note, 'recorded_by' => $r->recorded_by, 'decided_by' => $r->decided_by,
            'decided_at' => $r->decided_at === null ? null : substr((string) $r->decided_at, 0, 19), 'created_at' => substr((string) $r->created_at, 0, 19), 'lock_version' => (int) $r->lock_version,
        ];
    }
}
