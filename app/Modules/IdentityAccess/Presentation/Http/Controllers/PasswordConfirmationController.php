<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\Security\IdentityAccessSecurityEvent;
use App\Modules\IdentityAccess\Presentation\Http\Requests\ConfirmPasswordRequest;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PasswordConfirmationController
{
    public function show(): Response
    {
        return Inertia::render('identity-access/pages/confirm-password');
    }

    public function store(ConfirmPasswordRequest $request, SecurityLog $securityLog): RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();

        if (! Hash::check(
            $request->string('password')->toString(),
            $request->user()->getAuthPassword(),
        )) {
            $securityLog->record(new SecurityEvent(
                IdentityAccessSecurityEvent::PasswordConfirmation->value,
                SecurityEventOutcome::Failure,
                $userId,
            ));

            throw ValidationException::withMessages([
                'password' => __('identity.password_incorrect'),
            ]);
        }

        $securityLog->record(new SecurityEvent(
            IdentityAccessSecurityEvent::PasswordConfirmation->value,
            SecurityEventOutcome::Success,
            $userId,
        ));
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('security.sessions'));
    }
}
