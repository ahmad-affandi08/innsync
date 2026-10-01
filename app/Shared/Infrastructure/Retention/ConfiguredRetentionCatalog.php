<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Retention;

use App\Shared\Application\Retention\RetentionCatalog;
use App\Shared\Application\Retention\RetentionCategory;
use App\Shared\Application\Retention\UnknownRetentionCategory;

final readonly class ConfiguredRetentionCatalog implements RetentionCatalog
{
    public function get(string $key): RetentionCategory
    {
        foreach ($this->all() as $category) {
            if ($category->key === $key) {
                return $category;
            }
        }

        throw UnknownRetentionCategory::for($key);
    }

    public function all(): array
    {
        $categories = [];

        foreach ((array) config('retention.categories') as $key => $definition) {
            $categories[] = new RetentionCategory(
                (string) $key,
                (int) $definition['default_days'],
                (int) $definition['minimum_days'],
                $definition['maximum_days'] === null ? null : (int) $definition['maximum_days'],
                (bool) $definition['statutory'],
                (string) $definition['anchor'],
                (bool) $definition['purgeable'],
            );
        }

        return $categories;
    }
}
