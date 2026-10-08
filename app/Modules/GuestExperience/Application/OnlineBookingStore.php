<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Shared\Domain\Tenancy\PropertyId;

interface OnlineBookingStore
{
    /** @return array{enabled: bool, rate_plan_id: string|null, max_nights: int, notify_email: string|null, notice: string|null, actor_user_id: string|null, updated_at: string}|null */
    public function settings(PropertyId $property): ?array;

    public function save(PropertyId $property, bool $enabled, ?string $ratePlanId, int $maxNights, ?string $notifyEmail, ?string $notice, ?string $actorUserId, string $actorId): void;

    /** The property a web address names, when it exists, switched on and has a rate plan to offer; null otherwise. */
    public function bookable(string $propertyId): ?PropertyId;
}
