<?php

declare(strict_types=1);

namespace App\Modules\GuestExperience\Presentation\Http\Controllers;

use App\Modules\GuestExperience\Application\GuestSessionService;
use App\Modules\GuestExperience\Presentation\Http\Middleware\ResolveGuestSession;
use App\Shared\Application\Errors\Refusal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** What a guest reaches by scanning a code: a session is opened on that code and the guest is taken to the menu. No account, nothing to install. */
final readonly class GuestEntryController
{
    public function __construct(private GuestSessionService $sessions) {}

    public function enter(Request $request, string $token): SymfonyResponse
    {
        try {
            $opened = $this->sessions->enter($token);
        } catch (Refusal) {
            return Inertia::render('guest/pages/ended', ['reason' => 'code'])->toResponse($request)->setStatusCode(404);
        }

        $minutes = (int) (config('guest.session_minutes')[$opened['kind']] ?? 240);
        $cookie = new Cookie(ResolveGuestSession::COOKIE, $opened['token'], now()->addMinutes($minutes)->toDateTime(), '/g', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);

        return redirect('/g/menu')->withCookie($cookie)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function ended(Request $request): Response
    {
        return Inertia::render('guest/pages/ended', ['reason' => 'session']);
    }
}
