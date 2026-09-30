<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Presentation\Http\Requests\ConfirmPasswordRequest;
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

    public function store(ConfirmPasswordRequest $request): RedirectResponse
    {
        if (! Hash::check(
            $request->string('password')->toString(),
            $request->user()->getAuthPassword(),
        )) {
            throw ValidationException::withMessages([
                'password' => 'The password is incorrect.',
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('security.sessions'));
    }
}
