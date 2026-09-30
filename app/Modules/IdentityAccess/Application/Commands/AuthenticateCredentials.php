<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Commands;

use App\Modules\IdentityAccess\Application\DTOs\AuthenticatedIdentity;
use App\Modules\IdentityAccess\Application\Ports\CredentialAuthenticator;

final readonly class AuthenticateCredentials
{
    public function __construct(private CredentialAuthenticator $authenticator) {}

    public function handle(string $email, string $password): ?AuthenticatedIdentity
    {
        return $this->authenticator->authenticate(strtolower(trim($email)), $password);
    }
}
