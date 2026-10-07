<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Infrastructure;

use App\Modules\FrontOffice\Application\Stays\LaundryExceptionStore;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseLaundryExceptionStore implements LaundryExceptionStore
{
    public function add(PropertyId $property, string $id, string $stayId, string $reservationId, string $mode, string $reason, string $approvalId, array $orderIds, string $actorId, DateTimeImmutable $at): bool
    {
        try {
            DB::table('stay_laundry_exceptions')->insert([
                'id' => $id, 'property_id' => $property->toString(), 'stay_id' => $stayId, 'reservation_id' => $reservationId, 'mode' => $mode, 'reason' => $reason,
                'approval_id' => $approvalId, 'order_ids' => json_encode($orderIds, JSON_THROW_ON_ERROR), 'created_by' => $actorId, 'created_at' => $at,
            ]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function ofStay(PropertyId $property, string $stayId): ?array
    {
        return $this->shape(DB::table('stay_laundry_exceptions')->where('property_id', $property->toString())->where('stay_id', $stayId)->first());
    }

    public function ofReservation(PropertyId $property, string $reservationId): ?array
    {
        return $this->shape(DB::table('stay_laundry_exceptions')->where('property_id', $property->toString())->where('reservation_id', $reservationId)->first());
    }

    /** @return array{id: string, stay_id: string, reservation_id: string, mode: string, reason: string, approval_id: string, order_ids: list<string>, created_at: string}|null */
    private function shape(?stdClass $r): ?array
    {
        if ($r === null) {
            return null;
        }

        /** @var list<string> $orders */
        $orders = json_decode((string) $r->order_ids, true, 512, JSON_THROW_ON_ERROR);

        return ['id' => $r->id, 'stay_id' => $r->stay_id, 'reservation_id' => $r->reservation_id, 'mode' => $r->mode, 'reason' => $r->reason, 'approval_id' => $r->approval_id, 'order_ids' => $orders, 'created_at' => (string) $r->created_at];
    }
}
