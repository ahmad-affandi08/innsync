<?php

declare(strict_types=1);

namespace Tests\Unit\IdentityAccess;

use App\Modules\IdentityAccess\Infrastructure\Mfa\TotpOneTimePassword;
use Tests\TestCase;

final class TotpOneTimePasswordTest extends TestCase
{
    public function test_totp_matches_the_rfc_6238_sha1_test_vector(): void
    {
        config([
            'identity_access.totp.digits' => 8,
            'identity_access.totp.period_seconds' => 30,
        ]);

        $totp = new TotpOneTimePassword;

        self::assertSame(
            '94287082',
            $totp->codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59),
        );
    }

    public function test_generated_secret_and_uri_are_authenticator_compatible(): void
    {
        $totp = new TotpOneTimePassword;
        $secret = $totp->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertStringStartsWith('otpauth://totp/', $totp->provisioningUri($secret, 'user@example.test'));
    }
}
