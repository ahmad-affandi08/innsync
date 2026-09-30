<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\Commands\AuthenticateCredentials;
use App\Modules\IdentityAccess\Presentation\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AuthenticatedSessionController
{
    public function create(): Response
    {
        return Inertia::render('identity-access/pages/login');
    }

    public function store(
        LoginRequest $request,
        AuthenticateCredentials $authenticate,
    ): RedirectResponse {
        $identity = $authenticate->handle(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        if ($identity === null || ! Auth::guard('web')->loginUsingId($identity->userId)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials cannot be used to sign in.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('auth.password_confirmed_at', time());
        $request->session()->forget(['auth.active_property_id', 'auth.mfa_passed_user_id']);

        if ($identity->mfaRequired) {
            return redirect()->route($identity->mfaConfirmed ? 'mfa.challenge' : 'mfa.setup');
        }

        $request->session()->put('auth.mfa_passed_user_id', $identity->userId);

        return redirect()->route('properties.select');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
