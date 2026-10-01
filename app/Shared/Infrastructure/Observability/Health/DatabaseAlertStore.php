<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability\Health;

use App\Shared\Application\Observability\Health\AlertStore;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Observability\Health\HealthStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseAlertStore implements AlertStore
{
    public function openStatus(string $key): ?HealthStatus
    {
        $status = DB::table('operational_alerts')->where('open_key', $key)->value('severity');

        return is_string($status) ? HealthStatus::from($status) : null;
    }

    public function open(string $key, HealthResult $result, DateTimeImmutable $now): void
    {
        DB::table('operational_alerts')->insert([
            'id' => strtolower((string) Str::ulid()),
            'alert_key' => $key,
            'open_key' => $key,
            'severity' => $result->status->value,
            'summary' => $result->summary,
            'context' => json_encode($result->context, JSON_THROW_ON_ERROR),
            'occurrences' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'resolved_at' => null,
        ]);
    }

    public function touch(string $key, HealthResult $result, DateTimeImmutable $now): void
    {
        DB::table('operational_alerts')->where('open_key', $key)->update([
            'severity' => $result->status->value,
            'summary' => $result->summary,
            'context' => json_encode($result->context, JSON_THROW_ON_ERROR),
            'occurrences' => DB::raw('occurrences + 1'),
            'last_seen_at' => $now,
        ]);
    }

    public function resolve(string $key, DateTimeImmutable $now): void
    {
        DB::table('operational_alerts')->where('open_key', $key)->update([
            'open_key' => null,
            'resolved_at' => $now,
            'last_seen_at' => $now,
        ]);
    }
}
