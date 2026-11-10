<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\FrontOffice\Application\Cashier\CashierRepository;
use App\Modules\FrontOffice\Application\Reminders\ReminderService;
use App\Modules\GuestExperience\Application\OnlineBookingAdminService;
use App\Modules\HumanResource\Application\AttendanceReviewService;
use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\Property\Application\Branding\PropertyBranding;
use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Setup\ModuleAccess;
use App\Shared\Application\Setup\ModuleSettings;
use App\Shared\Application\Setup\OptionalFeatures;
use App\Shared\Application\Setup\PropertyFeatures;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Throwable;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'status' => fn (): ?string => is_string($status = $request->session()->get('status')) ? $status : null,
            'timeZone' => fn (): ?string => $this->activeTimeZone($request),
            'auth' => fn (): ?array => $this->activeIdentity($request),
            'shell' => fn (): ?array => $this->shell($request),
            'brand' => fn (): array => $this->brand($request),
            'app' => [
                'name' => (string) config('app.name'),
                'version' => (string) config('app.version'),
            ],
        ];
    }

    /**
     * IANA zone of the active property, for displaying instants (NFR-26). Only
     * shared for an authenticated user with a selected property; an unreadable
     * stored zone is reported and the client falls back to an explicit UTC label.
     */
    private function activeTimeZone(Request $request): ?string
    {
        $propertyId = $request->user() !== null ? $request->session()->get('auth.active_property_id') : null;

        if (! is_string($propertyId) || $propertyId === '') {
            return null;
        }

        try {
            return app(PropertyTimeZoneReader::class)
                ->forProperty(PropertyId::fromString($propertyId))
                ?->identifier();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Opaque identifiers the browser needs to scope its offline queue to the signed-in user and active
     * property. No name, e-mail or other personal data. The server re-checks both on every synchronization.
     *
     * @return array{userId: string, propertyId: string}|null
     */
    private function activeIdentity(Request $request): ?array
    {
        $propertyId = $request->session()->get('auth.active_property_id');

        if ($request->user() === null || ! is_string($propertyId) || $propertyId === '') {
            return null;
        }

        return ['userId' => strtolower((string) $request->user()->getAuthIdentifier()), 'propertyId' => strtolower($propertyId)];
    }

    /**
     * What the frame around every page shows: the property, its business date and who is signed in. Shown only to the signed-in
     * person themselves; the business date is null before go-live.
     *
     * @return array{propertyName: string|null, businessDate: string|null, userName: string, disabledModules?: list<string>, attention?: list<array{key: string, count: int}>, accessibleModules?: list<string>|null, permissionAreas?: list<string>|null, features?: array{loyalty: bool}}|null
     */
    private function shell(Request $request): ?array
    {
        $propertyId = $request->session()->get('auth.active_property_id');

        if ($request->user() === null || ! is_string($propertyId) || $propertyId === '') {
            return null;
        }

        try {
            $property = PropertyId::fromString($propertyId);
            $name = app(PropertyProfileReader::class)->nameOf($property);
        } catch (Throwable $exception) {
            report($exception);

            return ['propertyName' => null, 'businessDate' => null, 'userName' => (string) $request->user()->name];
        }

        try {
            $date = app(BusinessDateProvider::class)->current($property)->toString();
        } catch (Throwable) {
            $date = null;
        }

        try {
            $disabled = app(ModuleSettings::class)->disabled($property);
        } catch (Throwable) {
            $disabled = [];
        }

        try {
            $accessible = app(ModuleAccess::class)->forUser((string) $request->user()->getAuthIdentifier(), $property);
        } catch (Throwable) {
            $accessible = null; // when it cannot be read, the whole menu is offered rather than none of it
        }

        try {
            $areas = app(ModuleAccess::class)->areasOf((string) $request->user()->getAuthIdentifier(), $property);
        } catch (Throwable) {
            $areas = null; // unreadable: every page of a department is offered, as for the departments above
        }

        try {
            $currency = app(PropertyCurrencyReader::class)->currencyOf($property);
        } catch (Throwable) {
            $currency = null;
        }

        // The cashier shift this person has open, so the frame shows it on every page (the desk takes money from many screens).
        try {
            $open = app(CashierRepository::class)->openOf($property, strtolower((string) $request->user()->getAuthIdentifier()));
            $shift = $open === null ? null : ['number' => (string) $open['number'], 'opened_business_date' => (string) $open['opened_business_date']];
        } catch (Throwable) {
            $shift = null;
        }

        // Features a property has switched off, so the menu does not offer their pages. The loyalty programme is a switch of the installation (LOYALTY_ENABLED).
        $features = ['loyalty' => (bool) config('frontdesk.loyalty.enabled')];

        try {
            $on = app(PropertyFeatures::class)->enabled($property);
        } catch (Throwable) {
            $on = null; // unreadable: the optional pages are offered rather than hidden
        }

        foreach (OptionalFeatures::ALL as $feature) {
            $features[$feature] = $on === null || in_array($feature, $on, true);
        }

        try {
            $logo = app(PropertyBranding::class)->url($property);
        } catch (Throwable) {
            $logo = null;
        }

        // What waits for this person, counted at most once a minute so the frame costs nothing on every page. A count the person has no right to see is simply left out.
        $userId = (string) $request->user()->getAuthIdentifier();
        $attention = [];

        foreach ([
            'approvals' => static fn (): int => count(app(ApprovalService::class)->pendingFor($property, $userId, 99)),
            'attendance' => static fn (): int => count(app(AttendanceReviewService::class)->queue($property, $userId)),
            'reminders' => static fn (): int => app(ReminderService::class)->dueCount($property, $userId),
            'online' => static fn (): int => app(OnlineBookingAdminService::class)->awaitingFor($property, $userId),
        ] as $key => $count) {
            try {
                // Counting approvals and clock-ins is heavy, so they are counted once a minute; reminders and web bookings are one cheap count and always current.
                $n = in_array($key, ['reminders', 'online'], true) ? (int) $count() : (int) Cache::remember('shell.attention.'.$key.'.'.$propertyId.'.'.$userId, 60, $count);
            } catch (Throwable $exception) {
                report($exception);
                $n = 0;
            }

            if ($n > 0) {
                $attention[] = ['key' => $key, 'count' => $n];
            }
        }

        return ['propertyName' => $name, 'businessDate' => $date, 'userName' => (string) $request->user()->name, 'disabledModules' => $disabled, 'accessibleModules' => $accessible, 'permissionAreas' => $areas, 'features' => $features, 'attention' => $attention, 'currency' => $currency, 'shift' => $shift];
    }

    /**
     * The property's logo and whether "Powered by InnSYnc" shows, for every page: the signed-in person's property, the property of a guest's code or link, or,
     * on the sign-in page, the only property of the installation. With several properties and nobody signed in, no logo is shown since it cannot be known which is meant.
     *
     * @return array{logoUrl: string|null, poweredBy: bool}
     */
    private function brand(Request $request): array
    {
        $none = ['logoUrl' => null, 'poweredBy' => false];

        try {
            $branding = app(PropertyBranding::class);
            $sessionProperty = $request->user() !== null ? $request->session()->get('auth.active_property_id') : null;
            $context = app(PropertyContext::class);

            $brand = match (true) {
                is_string($sessionProperty) && $sessionProperty !== '' => $branding->brand(PropertyId::fromString($sessionProperty)),
                $context->hasActiveProperty() => $branding->brand($context->current()),
                $request->user() === null => $branding->soleBrand(),
                default => null,
            };

            return $brand ?? $none;
        } catch (Throwable $exception) {
            report($exception);

            return $none;
        }
    }
}
