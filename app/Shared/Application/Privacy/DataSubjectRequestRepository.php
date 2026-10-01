<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use App\Shared\Domain\Tenancy\PropertyId;

interface DataSubjectRequestRepository
{
    public function add(PropertyId $property, DataSubjectRequest $request): void;

    public function find(PropertyId $property, string $id): ?DataSubjectRequest;

    /** Compare-and-set on the lock version the caller read. @return bool false when someone else changed it first */
    public function save(PropertyId $property, DataSubjectRequest $request, int $expectedLockVersion): bool;

    /** Open requests, soonest due first. @return list<DataSubjectRequest> */
    public function open(PropertyId $property, int $limit): array;
}
