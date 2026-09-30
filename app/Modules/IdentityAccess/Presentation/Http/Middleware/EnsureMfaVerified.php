<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Middleware;

use App\Modules\IdentityAccess\Application\Mfa\MfaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureMfaVerified
{
    public function __construct(private MfaService $mfa) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $profile = $this->mfa->profile($userId);

        if (! $profile->required) {
            return $next($request);
        }

        if (! $profile->confirmed) {
            return redirect()->route('mfa.setup');
        }

        if ($request->session()->get('auth.mfa_passed_user_id') !== $userId) {
            return redirect()->route('mfa.challenge');
        }

        return $next($request);
    }
}
