<?php

declare(strict_types=1);

namespace App\Shared\Application\Setup;

use App\Shared\Domain\Tenancy\PropertyId;

/**
 * What a department has waiting for a person today, for the home page ("Hari Ini"). Each module answers for itself from its own services and only for a person who may see
 * the work (no permission, no entry). Counts that are zero are left out by the caller; a module that fails is skipped, never the page.
 */
interface TodayTasks
{
    /**
     * @return list<array{key: string, count: int, href: string, urgent?: bool}> `key` is `<module>.<what>` (it picks the wording and the icon on the page), `href` the page that lists
     *                                                                               the items, `urgent` marks work that is late or blocking
     */
    public function tasks(PropertyId $property, string $actorId): array;
}
