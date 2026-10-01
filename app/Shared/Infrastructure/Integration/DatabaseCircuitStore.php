<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Integration;

use App\Shared\Application\Integration\Circuit;
use App\Shared\Application\Integration\CircuitStore;
use App\Shared\Domain\Tenancy\PropertyId;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseCircuitStore implements CircuitStore
{
    public function load(PropertyId $property, string $provider): Circuit
    {
        $row = DB::table('integration_circuits')->where('property_id', $property->toString())->where('provider', $provider)->first();

        if ($row === null) {
            return Circuit::closed();
        }

        return new Circuit(
            (int) $row->consecutive_failures,
            $row->opened_at === null ? null : CarbonImmutable::parse($row->opened_at, 'UTC')->toImmutable(),
            $row->trial_started_at === null ? null : CarbonImmutable::parse($row->trial_started_at, 'UTC')->toImmutable(),
            (int) $row->version,
        );
    }

    public function save(PropertyId $property, string $provider, Circuit $circuit, int $expectedVersion): bool
    {
        $values = [
            'consecutive_failures' => $circuit->consecutiveFailures,
            'opened_at' => $circuit->openedAt,
            'trial_started_at' => $circuit->trialStartedAt,
            'version' => $expectedVersion + 1,
        ];

        if ($expectedVersion === 0) {
            // First write for this provider. If another request inserted meanwhile, the update below decides.
            try {
                DB::table('integration_circuits')->insert([
                    'property_id' => $property->toString(),
                    'provider' => $provider,
                    ...$values,
                ]);

                return true;
            } catch (UniqueConstraintViolationException) {
                // Another request created the row first; fall through to the compare-and-set update.
            }
        }

        return DB::table('integration_circuits')
            ->where('property_id', $property->toString())
            ->where('provider', $provider)
            ->where('version', $expectedVersion)
            ->update($values) === 1;
    }
}
