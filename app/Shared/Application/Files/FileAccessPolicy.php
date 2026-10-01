<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

/**
 * Object-level authorization supplied by the module that owns the file. Shared code cannot
 * know which role may see a guest identity photo or an HR document.
 */
interface FileAccessPolicy
{
    public function allows(string $actorId, StoredFile $file): bool;
}
