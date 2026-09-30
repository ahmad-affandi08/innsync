<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Exceptions;

use RuntimeException;

final class MfaEnrollmentMissing extends RuntimeException {}
