<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Http\Request;
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
            'timeZone' => fn (): ?string => $this->activeTimeZone($request),
            'auth' => fn (): ?array => $this->activeIdentity($request),
            'app' => [
                'name' => (string) config('app.name'),
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
}
