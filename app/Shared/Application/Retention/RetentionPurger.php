<?php

declare(strict_types=1);

namespace App\Shared\Application\Retention;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Files\PrivateFileStorage;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Erases stored files whose retention ended (NFR-07, NFR-08). Run from the scheduler, never as a daemon.
 * Per file, in one transaction: check holds, tombstone, audit, and only then erase the blob as the last step.
 * If the erasure fails the transaction rolls back and the next run retries; if the commit fails after the blob
 * is gone, the file is still due next run and erasing an absent blob is a no-op. A file is never reported
 * erased while its blob remains.
 */
final readonly class RetentionPurger
{
    public const REASON = 'retention';

    public function __construct(
        private ErasableFileRepository $files,
        private LegalHoldRepository $holds,
        private PrivateFileStorage $storage,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array{erased: int, held: int} */
    public function purge(PropertyId $propertyId, int $limit): array
    {
        return $this->property->run($propertyId, function () use ($propertyId, $limit): array {
            $now = $this->clock->nowUtc();
            $erased = 0;
            $held = 0;

            foreach ($this->files->due($propertyId, $now, $limit) as $file) {
                $outcome = $this->transactions->run(function () use ($propertyId, $file, $now): string {
                    foreach ($this->holds->active($propertyId) as $hold) {
                        if ($hold->covers($file)) {
                            return 'held';
                        }
                    }

                    if (! $this->files->tombstone($propertyId, $file->id, self::REASON, $now)) {
                        return 'skipped';
                    }

                    $this->audit->record(new AuditEntry(
                        $propertyId->toString(),
                        null,
                        'file.erased',
                        'stored_file',
                        $file->id,
                        ['purpose' => $file->purpose, 'owner_type' => $file->ownerType, 'owner_id' => $file->ownerId, 'sensitivity' => $file->sensitivity->value],
                        ['erased' => true, 'basis' => self::REASON],
                        'Retention period ended.',
                    ));
                    $this->storage->erase($file->storageKey);

                    return 'erased';
                });

                if ($outcome === 'held') {
                    $held++;

                    continue;
                }

                if ($outcome === 'erased') {
                    $erased++;
                }
            }

            return ['erased' => $erased, 'held' => $held];
        });
    }
}
