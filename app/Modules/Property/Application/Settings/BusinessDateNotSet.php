<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Settings;

use RuntimeException;

final class BusinessDateNotSet extends RuntimeException
{
    public static function forProperty(): self
    {
        return new self('The property has no business date yet. Set it at go-live.');
    }
}
