<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Middleware;

use App\Modules\IdentityAccess\Application\Security\IdentityAccessSecurityEvent;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureActiveUser
{
    public function __construct(private SecurityLog $securityLog) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_active !== true) {
            $actorId = $request->user()?->getAuthIdentifier();
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $this->securityLog->record(new SecurityEvent(
                IdentityAccessSecurityEvent::Authentication->value,
                SecurityEventOutcome::Denied,
                is_string($actorId) ? $actorId : null,
                metadata: ['reason_code' => 'inactive_session_user'],
            ));

            return redirect()->route('login')->withErrors([
                'email' => 'Your account is not available.',
            ]);
        }

        // A person whose password an administrator set must choose their own first: only the password form, sign-out and the checks that lead to it stay open.
        if ($request->user()->must_change_password === true && ! $request->routeIs('logout', 'security.sessions', 'security.password.update', 'password.confirm', 'password.confirm.store', 'mfa.*')) {
            return redirect()->route('security.sessions');
        }

        return $next($request);
    }
}
