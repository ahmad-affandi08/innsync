<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Presentation\Http\Controllers;

use App\Modules\IdentityAccess\Application\Exceptions\MfaAlreadyEnabled;
use App\Modules\IdentityAccess\Application\Exceptions\MfaEnrollmentMissing;
use App\Modules\IdentityAccess\Application\Exceptions\MfaVerificationFailed;
use App\Modules\IdentityAccess\Application\Mfa\MfaService;
use App\Modules\IdentityAccess\Presentation\Http\Requests\MfaCodeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class MfaController
{
    private const MAX_FAILURES = 5;

    private const LOCK_SECONDS = 900;

    public function setup(Request $request, MfaService $mfa): Response|RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $profile = $mfa->profile($userId);
        $recoveryCodes = $request->session()->pull('mfa.new_recovery_codes');

        if ($profile->confirmed && ! is_array($recoveryCodes)) {
            return redirect()->route('security.sessions');
        }

        $enrollment = $mfa->pendingEnrollment($userId, (string) $request->user()->email);

        return Inertia::render('identity-access/pages/mfa-setup', [
            'required' => $profile->required,
            'secret' => $enrollment?->secret,
            'provisioningUri' => $enrollment?->provisioningUri,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    public function begin(Request $request, MfaService $mfa): RedirectResponse
    {
        try {
            $mfa->beginEnrollment(
                (string) $request->user()->getAuthIdentifier(),
                (string) $request->user()->email,
            );
        } catch (MfaAlreadyEnabled) {
            return redirect()->route('security.sessions');
        }

        return redirect()->route('mfa.setup');
    }

    public function confirm(MfaCodeRequest $request, MfaService $mfa): RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();

        try {
            $recoveryCodes = $mfa->confirmEnrollment($userId, $request->string('code')->toString());
        } catch (MfaEnrollmentMissing) {
            throw ValidationException::withMessages(['code' => __('identity.mfa_enrollment_required')]);
        } catch (MfaVerificationFailed) {
            throw ValidationException::withMessages(['code' => __('identity.mfa_code_invalid')]);
        }

        $request->session()->regenerate();
        $request->session()->put('auth.mfa_passed_user_id', $userId);
        $request->session()->put('mfa.new_recovery_codes', $recoveryCodes);

        return redirect()->route('mfa.setup');
    }

    public function challenge(): Response
    {
        return Inertia::render('identity-access/pages/mfa-challenge');
    }

    public function verify(MfaCodeRequest $request, MfaService $mfa): RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $code = $request->string('code')->toString();
        $failures = 'mfa-failures:'.$userId;
        $used = 'mfa-used:'.$userId.':'.hash('sha256', $code);

        // Failures count against the account, whatever the address; a code that already got someone in is not accepted twice.
        if (RateLimiter::tooManyAttempts($failures, self::MAX_FAILURES)) {
            throw ValidationException::withMessages(['code' => __('identity.mfa_locked')]);
        }

        try {
            if (Cache::has($used)) {
                throw new MfaVerificationFailed('The verification code was already used.');
            }

            $mfa->verifyChallenge($userId, $code);
        } catch (MfaVerificationFailed) {
            RateLimiter::hit($failures, self::LOCK_SECONDS);
            // One generic message: it must not reveal whether a second factor is configured.
            throw ValidationException::withMessages(['code' => __('identity.mfa_code_invalid')]);
        }

        RateLimiter::clear($failures);
        Cache::put($used, true, 120);

        $request->session()->regenerate();
        $request->session()->put('auth.mfa_passed_user_id', $userId);

        return redirect()->route('properties.select');
    }
}
