<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Domain\Tenancy\PropertyId;

interface OnlineBookingStore
{
    /** @return array{enabled: bool, rate_plan_id: string|null, max_nights: int, notify_email: string|null, notice: string|null, actor_user_id: string|null, updated_at: string, remind_before_arrival: bool, thank_after_stay: bool}|null */
    public function settings(PropertyId $property): ?array;

    public function save(PropertyId $property, bool $enabled, ?string $ratePlanId, int $maxNights, ?string $notifyEmail, ?string $notice, ?string $actorUserId, string $actorId): void;

    /** The property a web address names, when it exists, switched on and has a rate plan to offer; null otherwise. */
    public function bookable(string $propertyId): ?PropertyId;

    public function saveMessages(PropertyId $property, bool $remindBeforeArrival, bool $thankAfterStay): void;

    /** @return list<string> properties that switched at least one guest message on */
    public function propertiesWithMessages(): array;

    /**
     * Web bookings that a message may be sent about on a day: `pre_arrival` (arriving that day, not cancelled) or `thank_you` (left that day or the day before), that have an email address and no message of this kind yet.
     *
     * @return list<array{id: string, number: string, guest_name: string, guest_email: string, arrival: string, departure: string}>
     */
    public function messageCandidates(PropertyId $property, string $kind, string $day): array;

    /** True when this message was not logged before. */
    public function logSent(PropertyId $property, string $reservationId, string $kind): bool;
}
