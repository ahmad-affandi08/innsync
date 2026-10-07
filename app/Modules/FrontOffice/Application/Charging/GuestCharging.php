<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\Charging;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * How other contexts (laundry, restaurant) find the guest in a room and put a charge on the guest's folio. The caller has
 * already checked its own permission; Front Office decides the tax, the folio and idempotency (BR-005).
 */
interface GuestCharging
{
    /** @return array{stay_id: string, reservation_id: string, guest_name?: string}|null null when nobody is in the room; `guest_name` is the name on the reservation, for a cashier to match against what the guest says */
    public function inHouseStayOfRoom(PropertyId $property, string $roomId): ?array;

    /**
     * Charges `$quotedMinor` (service charge and tax added on top, with the scheme of `$scope` in force today) to the guest's
     * first open folio. The same `$source` and `$sourceRef` are posted once, however often they are sent.
     *
     * @return array{posting_id: string, total_minor: int, currency: string, replayed: bool}
     *
     * @throws Refusal when the guest has no open folio or no scheme is configured for the scope
     */
    public function charge(PropertyId $property, string $actorId, string $reservationId, string $scope, string $code, string $description, int $quotedMinor, string $source, string $sourceRef): array;

    /**
     * The same charge for a guest who has checked out leaving something in hand that was approved to be charged late (FR-LDY-012): it goes to the late folio of the
     * stay (FR-FO-038), never to a closed folio and never to a changed past day. It is posted once per `$source` and `$sourceRef`.
     *
     * @return array{posting_id: string, total_minor: int, currency: string, replayed: bool}
     *
     * @throws Refusal when no exception for a late charge was recorded at the guest's check-out, or no scheme is configured for the scope
     */
    public function chargeLate(PropertyId $property, string $actorId, string $reservationId, string $scope, string $code, string $description, int $quotedMinor, string $source, string $sourceRef, string $reason): array;
}
