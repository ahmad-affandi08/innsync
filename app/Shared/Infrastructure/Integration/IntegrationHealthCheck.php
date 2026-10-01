<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Integration;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthResult;
use App\Shared\Application\Time\Clock;
use Illuminate\Support\Facades\DB;

/** NFR-20 "payment unknown" and provider degradation: unresolved unknown outcomes and open circuits. */
final readonly class IntegrationHealthCheck implements HealthCheck
{
    public function __construct(private Clock $clock) {}

    public function name(): string
    {
        return 'integrations';
    }

    public function check(): HealthResult
    {
        $unknown = DB::table('integration_unknowns')->where('status', 'open');
        $count = (clone $unknown)->count();
        $stale = (clone $unknown)
            ->where('created_at', '<=', $this->clock->nowUtc()->modify('-'.(int) config('integrations.unknown_down_hours').' hours'))
            ->count();
        $openCircuits = DB::table('integration_circuits')->whereNotNull('opened_at')->count();
        $context = ['open_unknown_outcomes' => $count, 'stale_unknown_outcomes' => $stale, 'open_circuits' => $openCircuits];

        return match (true) {
            $stale > 0 => HealthResult::down('An external call has an unknown outcome that nobody has reconciled.', $context),
            $count > 0, $openCircuits > 0 => HealthResult::degraded('An external provider is failing or an outcome needs reconciliation.', $context),
            default => HealthResult::ok('External integrations are healthy.', $context),
        };
    }
}
