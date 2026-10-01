<?php

declare(strict_types=1);

namespace App\Shared\Application\Files;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Transactions\TransactionRunner;
use Throwable;

/** Caller must already have authorized the actor to attach this file to the owner. */
final readonly class StoreFile
{
    public function __construct(
        private PrivateFileStorage $storage,
        private StoredFileRepository $files,
        private ContentInspector $inspector,
        private Clock $clock,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
    ) {}

    public function execute(FileUpload $upload): StoredFile
    {
        $policy = $upload->policy;
        $size = strlen($upload->contents);
        $now = $this->clock->nowUtc();

        if ($size === 0) {
            throw FileRejected::empty();
        }

        if ($size > $policy->maxBytes) {
            throw FileRejected::tooLarge($policy->maxBytes);
        }

        if ($upload->expiresAt !== null && $upload->expiresAt <= $now) {
            throw FileRejected::alreadyExpired();
        }

        $mime = $this->inspector->detectMimeType($upload->contents);

        if (! in_array($mime, $policy->allowedMimeTypes, true)) {
            throw FileRejected::unsupportedType();
        }

        $file = new StoredFile(
            $this->files->nextIdentity(),
            $upload->propertyId,
            $upload->purpose,
            $upload->ownerType,
            strtolower($upload->ownerId),
            $policy->sensitivity,
            bin2hex(random_bytes(32)),
            $mime,
            $size,
            hash('sha256', $upload->contents),
            $upload->displayName === null ? null : self::sanitizeName($upload->displayName),
            $upload->expiresAt,
            strtolower($upload->actorId),
            $now,
        );

        $this->storage->put($file->storageKey, $upload->contents);

        try {
            $this->transactions->run(function () use ($file): void {
                $this->files->add($file);
                $this->audit->record(new AuditEntry(
                    $file->propertyId->toString(),
                    $file->uploadedBy,
                    'file.stored',
                    'stored_file',
                    $file->id,
                    null,
                    [
                        'purpose' => $file->purpose,
                        'sensitivity' => $file->sensitivity->value,
                        'owner_type' => $file->ownerType,
                        'owner_id' => $file->ownerId,
                        'mime_type' => $file->mimeType,
                        'size_bytes' => $file->sizeBytes,
                        'checksum_sha256' => $file->checksumSha256,
                    ],
                ));
            });
        } catch (Throwable $exception) {
            $this->storage->discardUnrecorded($file->storageKey);

            throw $exception;
        }

        return $file;
    }

    private static function sanitizeName(string $name): ?string
    {
        $clean = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename(str_replace('\\', '/', $name))) ?? '';
        $clean = trim(mb_substr($clean, 0, 120), ". \t");

        return $clean === '' ? null : $clean;
    }
}
