<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Middleware;

use App\Modules\GuestExperience\Application\SelfCheckInService;
use App\Shared\Application\Tenancy\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the token in the address of a self check-in link into the property and the reservation it opens (FR-GST-006). A guest has no account: the token is the whole proof, so a link that is unknown, withdrawn
 * or expired gives one and the same answer, and nothing about the page is cached or passed on in a referrer. The property context is set for the request and cleared after, as for signed-in staff.
 */
final readonly class ResolveCheckInLink
{
    public function __construct(private SelfCheckInService $checkins, private PropertyContext $property) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $link = $this->checkins->resolve((string) $request->route('token'));

        if ($link === null) {
            $response = $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['error' => ['code' => 'not_found', 'message_key' => 'guest.checkin.linkEnded']], 404)
                : Inertia::render('guest/pages/ended', ['reason' => 'link'])->toResponse($request)->setStatusCode(404);

            return $this->harden($response);
        }

        $request->attributes->set('guest.link', $link);
        $this->property->activate($link['property']);

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
