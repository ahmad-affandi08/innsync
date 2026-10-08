<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Middleware;

use App\Modules\GuestExperience\Application\OnlineBookingService;
use App\Shared\Application\Tenancy\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the address of the booking page into the property it names, when that property takes bookings. A property that does not exist, has switched booking off or has nothing
 * to offer answers the same way. Nothing is cached and no referrer is passed on.
 */
final readonly class ResolveOnlineBookingProperty
{
    public function __construct(private OnlineBookingService $booking, private PropertyContext $property) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $property = $this->booking->resolve((string) $request->route('property'));

        if ($property === null) {
            $response = $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['error' => ['code' => 'not_found', 'message_key' => 'guest.booking.off']], 404)
                : Inertia::render('guest/pages/ended', ['reason' => 'booking'])->toResponse($request)->setStatusCode(404);

            return $this->harden($response);
        }

        $request->attributes->set('online.property', $property);
        $this->property->activate($property);

        try {
            return $this->harden($next($request));
        } finally {
            $this->property->clear();
        }
    }

    private function harden(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
