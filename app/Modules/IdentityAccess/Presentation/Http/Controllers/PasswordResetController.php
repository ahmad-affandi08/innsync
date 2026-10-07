<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** A forgotten password, by a one-time link to the account's own email. The answer never says whether an email has an account. */
final class PasswordResetController
{
    public function request(): Response
    {
        return Inertia::render('identity-access/pages/forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);

        // Only an active account is sent a link; the reply is the same either way.
        Password::broker()->sendResetLink(['email' => $data['email'], 'is_active' => 1]);

        return back()->with('status', __('identity.reset_sent'));
    }

    public function form(Request $request, string $token): Response
    {
        return Inertia::render('identity-access/pages/reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        $status = Password::broker()->reset(
            ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $data['password']],
            function (UserRecord $user, string $password): void {
                if (! $user->is_active) {
                    return;
                }

                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'password_changed_at' => now(),
                        'must_change_password' => false,
                        'failed_login_attempts' => 0,
                        'locked_until' => null,
                    ])->save();
                    DB::table((string) config('session.table'))->where('user_id', $user->getAuthIdentifier())->delete();
                });
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __('identity.reset_invalid')]);
        }

        return to_route('login')->with('status', __('identity.reset_done'));
    }
}
