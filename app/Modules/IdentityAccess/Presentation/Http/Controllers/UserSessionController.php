<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\DTOs\UserSession;
use App\Modules\IdentityAccess\Application\Ports\UserSessionRepository;
use App\Modules\IdentityAccess\Application\Security\IdentityAccessSecurityEvent;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
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
            'mustChangePassword' => $request->user()->must_change_password === true,
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
        SecurityLog $securityLog,
    ): RedirectResponse {
        if (hash_equals($request->session()->getId(), $sessionId)) {
            $securityLog->record(new SecurityEvent(
                IdentityAccessSecurityEvent::SessionRevocation->value,
                SecurityEventOutcome::Denied,
                (string) $request->user()->getAuthIdentifier(),
                metadata: ['reason_code' => 'current_session'],
            ));

            throw ValidationException::withMessages([
                'session' => __('identity.use_sign_out'),
            ]);
        }

        $userId = (string) $request->user()->getAuthIdentifier();
        $revoked = $sessions->revoke($userId, $sessionId);
        $securityLog->record(new SecurityEvent(
            IdentityAccessSecurityEvent::SessionRevocation->value,
            $revoked ? SecurityEventOutcome::Success : SecurityEventOutcome::Denied,
            $userId,
            metadata: ['scope' => 'single'],
        ));

        return back();
    }

    public function destroyOthers(
        Request $request,
        UserSessionRepository $sessions,
        SecurityLog $securityLog,
    ): RedirectResponse {
        $userId = (string) $request->user()->getAuthIdentifier();
        $revoked = $sessions->revokeAllExcept(
            $userId,
            $request->session()->getId(),
        );
        $securityLog->record(new SecurityEvent(
            IdentityAccessSecurityEvent::SessionRevocation->value,
            SecurityEventOutcome::Success,
            $userId,
            metadata: ['scope' => 'others', 'revoked_count' => $revoked],
        ));

        return back();
    }
}
