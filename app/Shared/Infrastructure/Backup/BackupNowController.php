<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * "Back up now" on the status screen. It only asks for a backup to be made by the worker, at most one request every ten minutes, and records who asked. It never shows, downloads or restores a backup:
 * those stay with whoever has the server, because a backup holds everything.
 */
final readonly class BackupNowController
{
    public function __construct(private AuditTrail $audit, private PropertyContext $property) {}

    public function store(Request $request): JsonResponse
    {
        if (! Cache::add('backup.requested', 1, 600)) {
            return response()->json(['queued' => false, 'reason' => 'recent'], 409)->header('Cache-Control', 'no-store');
        }

        RunBackupJob::dispatch();
        $this->audit->record(new AuditEntry($this->property->current()->toString(), strtolower((string) $request->user()->getAuthIdentifier()), 'system.backup.requested', 'backup', 'manual', null, ['requested' => true]));

        return response()->json(['queued' => true])->header('Cache-Control', 'no-store');
    }
}
