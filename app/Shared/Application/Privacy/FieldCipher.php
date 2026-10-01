<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

/**
 * Protects individual personal-data fields at rest (NFR-07). `seal` and `open` are reversible encryption; `blindIndex`
 * is a keyed one-way hash of a normalized value, so a record can be looked up by exact value without the value being
 * readable from the database. The scope keeps indexes of different fields from being compared with each other.
 */
interface FieldCipher
{
    public function seal(string $plain): string;

    /** @throws \RuntimeException when the value was tampered with or sealed with another key */
    public function open(string $sealed): string;

    public function blindIndex(string $scope, string $normalized): string;
}
