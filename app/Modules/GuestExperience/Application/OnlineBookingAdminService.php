<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Application;

use App\Modules\FrontOffice\Application\GuestDesk\OnlineBookingDesk;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Security\SystemActors;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Switches booking from the hotel's own web page on and off and sets how it works: which rate plan is offered, how many nights at most, where a new booking is announced and the
 * hotel's own words for the guest. It is off until the hotel turns it on. A booking made there is tentative and staff confirm it; nothing is charged online.
 */
final readonly class OnlineBookingAdminService
{
    public const MANAGE = 'property.settings.manage';

    public const BOOKING_PERMISSION = 'front-office.reservation.manage';

    public function __construct(private OnlineBookingStore $store, private OnlineBookingDesk $desk, private PermissionChecker $permissions, private SystemActors $actors, private PropertyContext $property, private TransactionRunner $transactions, private AuditTrail $audit) {}

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $baseUrl): array
    {
        $this->authorize($property, $actorId);
        $s = $this->store->settings($property);

        return [
            'enabled' => $s['enabled'] ?? false, 'rate_plan_id' => $s['rate_plan_id'] ?? null, 'max_nights' => $s['max_nights'] ?? 14, 'notify_email' => $s['notify_email'] ?? null, 'notice' => $s['notice'] ?? null, 'remind_before_arrival' => $s['remind_before_arrival'] ?? false, 'thank_after_stay' => $s['thank_after_stay'] ?? false,
            'plans' => $this->desk->ratePlans($property), 'url' => rtrim($baseUrl, '/').'/book/'.$property->toString(),
            'awaiting' => $this->actorAwaiting($property),
        ];
    }

    public function save(PropertyId $property, string $actorId, bool $enabled, ?string $ratePlanId, int $maxNights, ?string $notifyEmail, ?string $notice, ?bool $remindBeforeArrival = null, ?bool $thankAfterStay = null): void
    {
        $this->authorize($property, $actorId);
        $ratePlanId = $ratePlanId === null || $ratePlanId === '' ? null : strtolower($ratePlanId);
        $notifyEmail = $notifyEmail === null || trim($notifyEmail) === '' ? null : trim($notifyEmail);
        $notice = $notice === null || trim($notice) === '' ? null : trim($notice);

        if ($maxNights < 1 || $maxNights > 60) {
            throw Refusal::invalid('Choose between 1 and 60 nights.', ['max_nights']);
        }

        if ($notifyEmail !== null && (mb_strlen($notifyEmail) > 190 || filter_var($notifyEmail, FILTER_VALIDATE_EMAIL) === false)) {
            throw Refusal::invalid('Give a valid email address.', ['notify_email']);
        }

        if ($notice !== null && mb_strlen($notice) > 500) {
            throw Refusal::invalid('The text is at most 500 characters.', ['notice']);
        }

        if ($ratePlanId !== null && ! in_array($ratePlanId, array_column($this->desk->ratePlans($property), 'id'), true)) {
            throw Refusal::invalid('Choose one of the active rate plans.', ['rate_plan_id']);
        }

        if ($enabled && $ratePlanId === null) {
            throw Refusal::invalid('Choose the rate plan guests will book at before switching it on.', ['rate_plan_id']);
        }

        $before = $this->store->settings($property);

        $this->transactions->run(function () use ($property, $actorId, $enabled, $ratePlanId, $maxNights, $notifyEmail, $notice, $before, $remindBeforeArrival, $thankAfterStay): void {
            // The account the web bookings are recorded under is made the first time it is switched on, and remembered so the header never has to look for it.
            $account = $enabled ? $this->actors->onlineBooking($property, [self::BOOKING_PERMISSION]) : null;
            $this->store->save($property, $enabled, $ratePlanId, $maxNights, $notifyEmail, $notice, $account, strtolower($actorId));
            $this->store->saveMessages($property, $remindBeforeArrival ?? (bool) ($before['remind_before_arrival'] ?? false), $thankAfterStay ?? (bool) ($before['thank_after_stay'] ?? false));
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'online_booking.saved', 'online_booking', $property->toString(), $before === null ? null : ['enabled' => $before['enabled'], 'rate_plan_id' => $before['rate_plan_id'], 'max_nights' => $before['max_nights']], ['enabled' => $enabled, 'rate_plan_id' => $ratePlanId, 'max_nights' => $maxNights, 'has_notify_email' => $notifyEmail !== null]));
        });
    }

    /** Web bookings nobody has dealt with, for whoever may see reservations. */
    public function awaitingFor(PropertyId $property, string $actorId): int
    {
        if (! $this->permissions->allowsInProperty($actorId, 'front-office.reservation.view', $property) && ! $this->permissions->allowsInProperty($actorId, 'front-office.reservation.manage', $property)) {
            return 0;
        }

        return $this->actorAwaiting($property);
    }

    private function actorAwaiting(PropertyId $property): int
    {
        $account = $this->store->settings($property)['actor_user_id'] ?? null;

        return $account === null ? 0 : $this->desk->awaiting($property, $account);
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, self::MANAGE, $property)) {
            throw Refusal::forbidden('This person may not change how the property takes bookings.');
        }
    }
}
