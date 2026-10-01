<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class BackupRunLog
{
    public function start(string $kind): string
    {
        $id = strtolower((string) Str::ulid());

        DB::table('backup_runs')->insert([
            'id' => $id,
            'kind' => $kind,
            'status' => 'running',
            'started_at' => CarbonImmutable::now('UTC'),
        ]);

        return $id;
    }

    /** @param array<string, mixed> $details */
    public function succeed(string $id, ?string $set, int $sizeBytes, array $details): void
    {
        $this->finish($id, 'succeeded', $set, $sizeBytes, $details, null);
    }

    /** @param array<string, mixed> $details */
    public function fail(string $id, ?string $set, Throwable $e, array $details = []): void
    {
        $this->finish($id, 'failed', $set, null, $details, $e);
    }

    public function lastSuccess(string $kind): ?CarbonImmutable
    {
        $at = DB::table('backup_runs')->where('kind', $kind)->where('status', 'succeeded')->max('finished_at');

        return is_string($at) ? CarbonImmutable::parse($at, 'UTC') : null;
    }

    /** @return array{status: string, finished_at: ?string}|null */
    public function lastFinished(string $kind): ?array
    {
        $row = DB::table('backup_runs')->where('kind', $kind)->whereIn('status', ['succeeded', 'failed'])
            ->orderByDesc('finished_at')->first(['status', 'finished_at']);

        return $row === null ? null : ['status' => $row->status, 'finished_at' => $row->finished_at];
    }

    /** @param array<string, mixed> $details */
    private function finish(string $id, string $status, ?string $set, ?int $size, array $details, ?Throwable $e): void
    {
        $now = CarbonImmutable::now('UTC');
        $started = CarbonImmutable::parse((string) DB::table('backup_runs')->where('id', $id)->value('started_at'), 'UTC');

        DB::table('backup_runs')->where('id', $id)->update([
            'status' => $status,
            'backup_set' => $set,
            'finished_at' => $now,
            'duration_ms' => max(0, $started->diffInMilliseconds($now, false)),
            'size_bytes' => $size,
            'error_type' => $e === null ? null : $e::class,
            'error_fingerprint' => $e === null ? null : hash('sha256', $e::class.'|'.$e->getMessage()),
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
