<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use RuntimeException;

/** Thrown by an adapter when the request could not be sent at all (DNS, connect, TLS): nothing was applied. */
final class ProviderUnreachable extends RuntimeException {}
