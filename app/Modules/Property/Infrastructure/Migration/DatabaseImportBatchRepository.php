<?php

declare(strict_types=1);

namespace App\Modules\Property\Infrastructure\Migration;

use App\Modules\Property\Application\Migration\ImportBatchRepository;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseImportBatchRepository implements ImportBatchRepository
{
    public function add(PropertyId $property, string $id, string $kind, string $checksum, string $status, int $errorCount, array $report, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('import_batches')->insert([
            'id' => $id, 'property_id' => $property->toString(), 'kind' => $kind, 'checksum' => $checksum, 'status' => $status, 'error_count' => $errorCount,
            'report' => json_encode($report, JSON_THROW_ON_ERROR), 'actor_id' => $actorId, 'created_at' => $at,
        ]);
    }

    public function wasApplied(PropertyId $property, string $kind, string $checksum): bool
    {
        return DB::table('import_batches')->where('property_id', $property->toString())->where('kind', $kind)->where('checksum', $checksum)->where('status', 'applied')->exists();
    }
}
