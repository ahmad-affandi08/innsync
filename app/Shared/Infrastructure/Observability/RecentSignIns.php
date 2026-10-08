<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The last attempts to sign in, for the owner's status screen: when, whether it worked, why not, whose account it was (when the email was known) and a short code of the network it came
 * from (the first characters of a hash, so the same code means the same network without showing an address). It reads what the sign-in already records; it holds no password and no email.
 */
final class RecentSignIns
{
    /** @return list<array{at: string, outcome: string, reason: string|null, who: string|null, network: string|null}> */
    public function latest(int $limit = 10): array
    {
        return DB::table('security_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_id')->where('e.event_type', 'identity.authentication')
            ->orderByDesc('e.occurred_at')->limit($limit)->get(['e.occurred_at', 'e.outcome', 'e.metadata', 'e.source_ip_hash', 'u.name'])
            ->map(static function (object $r): array {
                $meta = json_decode((string) $r->metadata, true);

                return [
                    'at' => CarbonImmutable::parse((string) $r->occurred_at, 'UTC')->toIso8601String(),
                    'outcome' => (string) $r->outcome,
                    'reason' => is_array($meta) && isset($meta['reason_code']) ? (string) $meta['reason_code'] : null,
                    'who' => $r->name === null ? null : (string) $r->name,
                    'network' => $r->source_ip_hash === null ? null : substr((string) $r->source_ip_hash, 0, 8),
                ];
            })->all();
    }
}
