<?php

declare(strict_types=1);

namespace App\Modules\Finance\Infrastructure;

use App\Modules\Finance\Application\FinanceAuditQueries;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseFinanceAuditQueries implements FinanceAuditQueries
{
    public function trail(PropertyId $property, array $prefixes, DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc, array $filters, int $limit, int $offset): array
    {
        $escape = static fn (string $p): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $p).'%';
        $query = DB::table('audit_entries as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->where('a.property_id', $property->toString())->where('a.occurred_at', '>=', $fromUtc)->where('a.occurred_at', '<', $toUtc)
            ->where(static function ($q) use ($prefixes, $escape): void {
                foreach ($prefixes as $prefix) {
                    $q->orWhere('a.action', 'like', $escape($prefix));
                }
            });

        if (($filters['actor_id'] ?? null) !== null && $filters['actor_id'] !== '') {
            $query->where('a.actor_id', strtolower((string) $filters['actor_id']));
        }

        if (($filters['action'] ?? null) !== null && $filters['action'] !== '') {
            $query->where('a.action', $filters['action']);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('a.occurred_at')->orderByDesc('a.id')->limit($limit)->offset($offset)
            ->get(['a.id', 'a.occurred_at', 'a.action', 'a.aggregate_type', 'a.aggregate_id', 'a.before_state', 'a.after_state', 'a.reason', 'a.approval_reference', 'a.actor_id', 'u.name as actor_name'])
            ->map(static fn ($r): array => [
                'id' => (string) $r->id, 'occurred_at' => (string) $r->occurred_at, 'action' => (string) $r->action, 'aggregate_type' => (string) $r->aggregate_type, 'aggregate_id' => (string) $r->aggregate_id,
                'before' => $r->before_state === null ? null : json_decode((string) $r->before_state, true), 'after' => $r->after_state === null ? null : json_decode((string) $r->after_state, true),
                'reason' => $r->reason, 'approval' => $r->approval_reference, 'actor_id' => $r->actor_id, 'actor_name' => $r->actor_name,
            ])->all();

        return ['rows' => $rows, 'total' => $total];
    }
}
