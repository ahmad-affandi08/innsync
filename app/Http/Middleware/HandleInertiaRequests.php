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
}
