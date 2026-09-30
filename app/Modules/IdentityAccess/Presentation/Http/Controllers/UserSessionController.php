<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\DTOs\UserSession;
use App\Modules\IdentityAccess\Application\Ports\UserSessionRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class UserSessionController
{
    public function index(Request $request, UserSessionRepository $sessions): Response
    {
        $currentSessionId = $request->session()->getId();

        return Inertia::render('identity-access/pages/sessions', [
            'sessions' => array_map(
                static fn (UserSession $session): array => [
                    'id' => $session->id,
                    'device' => $session->device,
                    'ipAddress' => $session->ipAddress,
                    'lastActivity' => $session->lastActivity,
                    'current' => hash_equals($currentSessionId, $session->id),
                ],
                $sessions->forUser((string) $request->user()->getAuthIdentifier()),
            ),
        ]);
    }

    public function destroy(
        Request $request,
        string $sessionId,
        UserSessionRepository $sessions,
    ): RedirectResponse {
        if (hash_equals($request->session()->getId(), $sessionId)) {
            throw ValidationException::withMessages([
                'session' => 'Use sign out to end the current session.',
            ]);
        }

        $sessions->revoke((string) $request->user()->getAuthIdentifier(), $sessionId);

        return back();
    }

    public function destroyOthers(
        Request $request,
        UserSessionRepository $sessions,
    ): RedirectResponse {
        $sessions->revokeAllExcept(
            (string) $request->user()->getAuthIdentifier(),
            $request->session()->getId(),
        );

        return back();
    }
}
