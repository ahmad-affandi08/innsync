<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\IdentityAccess\Application\Approval\ApprovalService;
use App\Modules\Property\Application\Ports\PropertyProfileReader;
use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Setup\ModuleAccess;
use App\Shared\Application\Setup\ModuleSettings;
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
     * @return array{propertyName: string|null, businessDate: string|null, userName: string, disabledModules?: list<string>, waitingApprovals?: int, accessibleModules?: list<string>|null}|null
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

        // What waits for this person's decision, counted at most once a minute so the frame costs nothing on every page.
        $userId = (string) $request->user()->getAuthIdentifier();

        try {
            $waiting = (int) Cache::remember(
                'shell.waiting.'.$propertyId.'.'.$userId,
                60,
                static fn (): int => count(app(ApprovalService::class)->pendingFor($property, $userId, 99)),
            );
        } catch (Throwable) {
            $waiting = 0;
        }

        return ['propertyName' => $name, 'businessDate' => $date, 'userName' => (string) $request->user()->name, 'disabledModules' => $disabled, 'accessibleModules' => $accessible, 'waitingApprovals' => $waiting];
    }
}
