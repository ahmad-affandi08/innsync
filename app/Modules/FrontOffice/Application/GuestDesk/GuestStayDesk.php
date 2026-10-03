<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\GuestDesk;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What the guest self-service may do for a guest who is in the house (FR-GST-015, FR-GST-016, FR-GST-019): ask for something, report a problem, see how those are going, see the bill so far and when the stay
 * ends. The caller has proved the stay to the guest; front office does the work as the guest self-service account, with the same checks, numbering, routing to the departments and audit as a request a
 * receptionist takes. Only a guest who is in the house is served, and only what belongs to that stay is answered.
 */
interface GuestStayDesk
{
    public const CATEGORIES = ['housekeeping', 'food_beverage', 'maintenance', 'front_office', 'other'];

    /**
     * A request for a department, taken once for a key.
     *
     * @return array{id: string, number: string}
     */
    public function openRequest(PropertyId $property, string $stayId, string $category, string $title, ?string $detail, string $clientKey): array;

    /**
     * A complaint, taken once for a key; the staff decide how serious it is.
     *
     * @return array{id: string, number: string}
     */
    public function recordComplaint(PropertyId $property, string $stayId, string $summary, ?string $detail, string $clientKey): array;

    /** @return array{number: string, status: string, resolution: string|null}|null how far a request or complaint of this stay is: `open`, `in_progress`, `done` or `cancelled` */
    public function statusOf(PropertyId $property, string $stayId, string $kind, string $id): ?array;

    /**
     * The bill so far, as the guest would be handed it at the desk: what was charged by outlet and day, what was paid and what is owed.
     *
     * @return array{currency: string, outlets: list<array{outlet: string, lines: list<array{date: string, description: string, total_minor: int}>, total_minor: int}>, payments: list<array{date: string, method: string, amount_minor: int}>, total_minor: int, paid_minor: int, balance_minor: int}|null
     */
    public function runningBill(PropertyId $property, string $stayId): ?array;

    /** @return array{in_house: bool, expected_departure: string, reservation_id: string, room_id: string, guest_name: string|null}|null */
    public function stay(PropertyId $property, string $stayId): ?array;
}
