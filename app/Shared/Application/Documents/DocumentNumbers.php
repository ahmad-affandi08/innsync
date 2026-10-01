<?php

declare(strict_types=1);

namespace App\Shared\Application\Documents;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Next number of a document type, unique per property and type, never reused, not even after a void (BR-006). The
 * sequence moves inside the caller's transaction: if the caller rolls back, the number is not consumed.
 */
interface DocumentNumbers
{
    /** Formats like `RSV-000123`. `$prefix` is 2 to 6 uppercase letters. */
    public function next(PropertyId $property, string $prefix): string;
}
