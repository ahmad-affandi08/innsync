<?php

declare(strict_types=1);

namespace App\Shared\Application\Integration;

use RuntimeException;

/** Thrown by an adapter when the request was sent but no answer arrived in time: the outcome is unknown. */
final class ProviderTimedOut extends RuntimeException {}
