<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

interface RetentionCatalog
{
    /** @throws UnknownRetentionCategory */
    public function get(string $key): RetentionCategory;

    /** @return list<RetentionCategory> */
    public function all(): array;
}
