<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Security;

enum IdentityAccessSecurityEvent: string
{
    case Authentication = 'identity.authentication';
    case Authorization = 'identity.authorization';
    case Logout = 'identity.logout';
    case MfaChallenge = 'identity.mfa.challenge';
    case MfaEnrollment = 'identity.mfa.enrollment';
    case PasswordChange = 'identity.password.change';
    case PasswordConfirmation = 'identity.password.confirmation';
    case PropertySelection = 'identity.property.selection';
    case SessionRevocation = 'identity.session.revocation';
}
