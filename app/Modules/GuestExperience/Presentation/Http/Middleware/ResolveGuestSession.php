<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Middleware;

use App\Modules\GuestExperience\Application\GuestSessionService;
use App\Shared\Application\Tenancy\PropertyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the session cookie of a guest into the property and the code it belongs to (FR-GST-018). A guest has no account: the cookie holds a random token that opens one session on one code, and only for as long as
 * the session lasts. With none, or an ended one, the guest is sent to scan the code again. The property context is set for the request and cleared after, as for signed-in staff.
 */
final readonly class ResolveGuestSession
{
    public const COOKIE = 'ge_session';

    public function __construct(private GuestSessionService $sessions, private PropertyContext $property) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::COOKIE);
        $session = is_string($token) && $token !== '' ? $this->sessions->resolve($token) : null;

        if ($session === null) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['error' => ['code' => 'session_ended', 'message_key' => 'guest.session.ended']], 401)->header('Cache-Control', 'no-store')
                : redirect('/g/ended');
        }

        $request->attributes->set('guest.session', $session);
        $this->property->activate($session['property']);

        try {
            return $next($request);
        } finally {
            $this->property->clear();
        }
    }
}
