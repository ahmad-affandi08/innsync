<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\Commands\ChangePassword;
use App\Modules\IdentityAccess\Application\Ports\UserSessionRepository;
use App\Modules\IdentityAccess\Presentation\Http\Requests\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class PasswordController
{
    public function update(
        UpdatePasswordRequest $request,
        ChangePassword $changePassword,
        UserSessionRepository $sessions,
    ): RedirectResponse {
        $userId = (string) $request->user()->getAuthIdentifier();
        $updated = $changePassword->handle(
            $userId,
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
        );

        if (! $updated) {
            throw ValidationException::withMessages([
                'current_password' => __('identity.current_password_incorrect'),
            ]);
        }

        $sessions->revokeAllExcept($userId, $request->session()->getId());
        Auth::guard('web')->login($request->user()->fresh());
        $request->session()->put('auth.password_confirmed_at', time());
        $request->session()->put('auth.mfa_passed_user_id', $userId);

        return back();
    }
}
