<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Retention;

use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Retention\ErasableFileRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class DatabaseErasableFileRepository implements ErasableFileRepository
{
    public function due(PropertyId $property, DateTimeImmutable $now, int $limit): array
    {
        return DB::table('stored_files')
            ->where('property_id', $property->toString())
            ->whereNull('erased_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->map(static fn (stdClass $row): StoredFile => new StoredFile(
                $row->id,
                PropertyId::fromString($row->property_id),
                $row->purpose,
                $row->owner_type,
                $row->owner_id,
                FileSensitivity::from($row->sensitivity),
                $row->storage_key,
                $row->mime_type,
                (int) $row->size_bytes,
                $row->checksum_sha256,
                $row->display_name,
                CarbonImmutable::parse($row->expires_at, 'UTC')->toImmutable(),
                $row->uploaded_by,
                CarbonImmutable::parse($row->created_at, 'UTC')->toImmutable(),
            ))
            ->all();
    }

    public function tombstone(PropertyId $property, string $fileId, string $reason, DateTimeImmutable $at): bool
    {
        // The table's trigger allows exactly this change: erased_at, the reason, and the display name cleared.
        return DB::table('stored_files')
            ->where('property_id', $property->toString())
            ->where('id', $fileId)
            ->whereNull('erased_at')
            ->update(['erased_at' => $at, 'erasure_reason' => $reason, 'display_name' => null]) === 1;
    }
}
