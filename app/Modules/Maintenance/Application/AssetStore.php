<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/** Storage of the assets, their meter readings and their preventive plans. Rows are plain arrays; every query is scoped to the property. */
interface AssetStore
{
    /** @param array<string, mixed> $row */
    public function add(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function find(PropertyId $property, string $id): ?array;

    public function lock(PropertyId $property, string $id): void;

    /** @param array<string, mixed> $fields @return bool false when the asset changed meanwhile */
    public function update(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> every asset with its latest reading (`reading`) and its next due plan date (`next_due_on`) */
    public function all(PropertyId $property): array;

    /** @param array<string, mixed> $row */
    public function addReading(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** The highest reading of an asset, or null when it never had one. */
    public function currentReading(PropertyId $property, string $assetId): ?int;

    /** @return list<array<string, mixed>> the latest readings, newest first */
    public function readings(PropertyId $property, string $assetId, int $limit): array;

    /** @param array<string, mixed> $row */
    public function addPlan(PropertyId $property, array $row, DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function plan(PropertyId $property, string $id): ?array;

    /** @return list<array<string, mixed>> the plans of an asset, or of every asset (`asset_name`, `asset_number`, `asset_status` come with them) */
    public function plans(PropertyId $property, ?string $assetId): array;

    /** @param array<string, mixed> $fields @return bool false when the plan changed meanwhile */
    public function updatePlan(PropertyId $property, string $id, int $lock, array $fields, DateTimeImmutable $at): bool;

    /** @return list<array<string, mixed>> the work orders of an asset, newest first: its history of repairs */
    public function workOrdersOf(PropertyId $property, string $assetId, int $limit): array;
}
