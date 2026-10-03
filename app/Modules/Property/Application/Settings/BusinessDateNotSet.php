<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Settings;

use App\Shared\Application\Errors\ExpectedFailure;
use RuntimeException;

/** A property that has not gone live has no business date; the pages that need it say so (409) instead of failing. */
final class BusinessDateNotSet extends RuntimeException implements ExpectedFailure
{
    public static function forProperty(): self
    {
        return new self('The property has no business date yet. Set it at go-live.');
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'conflict';
    }

    public function messageKey(): string
    {
        return 'conflict_business_date';
    }

    public function conflict(): array
    {
        return ['reason' => 'business_date_not_set', 'action' => 'review'];
    }

    public function invalidFields(): array
    {
        return [];
    }
}
