<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Ports;

use App\Modules\IdentityAccess\Application\DTOs\AuthenticatedIdentity;

interface CredentialAuthenticator
{
    public function authenticate(string $email, string $password): ?AuthenticatedIdentity;
}
