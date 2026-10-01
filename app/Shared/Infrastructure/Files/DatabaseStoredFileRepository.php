<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Files;

use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Files\StoredFileRepository;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DatabaseStoredFileRepository implements StoredFileRepository
{
    public function __construct(
        private PropertyContext $propertyContext,
        private CorrelationId $correlationId,
    ) {}

    public function nextIdentity(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function add(StoredFile $file): void
    {
        $this->assertActive($file->propertyId);

        DB::table('stored_files')->insert([
            'id' => $file->id,
            'property_id' => $file->propertyId->toString(),
            'purpose' => $file->purpose,
            'owner_type' => $file->ownerType,
            'owner_id' => $file->ownerId,
            'sensitivity' => $file->sensitivity->value,
            'storage_key' => $file->storageKey,
            'mime_type' => $file->mimeType,
            'size_bytes' => $file->sizeBytes,
            'checksum_sha256' => $file->checksumSha256,
            'display_name' => $file->displayName,
            'expires_at' => $file->expiresAt,
            'uploaded_by' => $file->uploadedBy,
            'correlation_id' => $this->correlationId->current(),
            'created_at' => $file->createdAt,
        ]);
    }

    public function find(PropertyId $propertyId, string $fileId): StoredFile
    {
        $this->assertActive($propertyId);

        $row = DB::table('stored_files')
            ->where('property_id', $propertyId->toString())
            ->where('id', $fileId)
            ->first();

        if ($row === null) {
            throw StoredFileNotFound::forId($fileId);
        }

        return new StoredFile(
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
            $row->expires_at === null ? null : self::utc($row->expires_at),
            $row->uploaded_by,
            self::utc($row->created_at),
            $row->erased_at === null ? null : self::utc($row->erased_at),
        );
    }

    private function assertActive(PropertyId $propertyId): void
    {
        $active = $this->propertyContext->current();

        if (! $active->equals($propertyId)) {
            throw PropertyScopeViolation::mismatched($active->toString(), $propertyId->toString());
        }
    }

    private static function utc(string $value): DateTimeImmutable
    {
        return CarbonImmutable::parse($value, new DateTimeZone('UTC'))->toImmutable();
    }
}
