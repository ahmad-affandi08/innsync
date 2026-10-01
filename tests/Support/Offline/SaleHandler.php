<?php

declare(strict_types=1);

namespace Tests\Support\Offline;

use App\Shared\Application\Offline\ConflictAction;
use App\Shared\Application\Offline\OfflineContext;
use App\Shared\Application\Offline\OfflineEnvelope;
use App\Shared\Application\Offline\OfflineOperationHandler;
use App\Shared\Application\Offline\OfflineOutcome;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Test double for a module handler: one real row per applied operation, so "exactly once" is observable.
 * It writes to the existing `cache` table because DDL inside a test transaction would commit it.
 */
final class SaleHandler implements OfflineOperationHandler
{
    public const PREFIX = 'test-sale:';

    public static string $mode = 'ok';

    public static function count(): int
    {
        return DB::table('cache')->where('key', 'like', self::PREFIX.'%')->count();
    }

    public function type(): string
    {
        return 'test.sale';
    }

    public function payloadVersions(): array
    {
        return [1];
    }

    public function permission(): ?string
    {
        return 'fnb.pos.sell';
    }

    public function handle(OfflineEnvelope $envelope, OfflineContext $context): OfflineOutcome
    {
        if (self::$mode === 'conflict') {
            return OfflineOutcome::conflict('stale_room_state', ConflictAction::Review, 7);
        }

        if (self::$mode === 'reject') {
            return OfflineOutcome::rejected('closed_shift');
        }

        DB::table('cache')->insert([
            'key' => self::PREFIX.$envelope->operationId,
            'value' => (string) json_encode(['seq' => $envelope->clientSequence, 'amount' => (int) ($envelope->payload['amount'] ?? 0), 'actor' => $context->actorId]),
            'expiration' => self::count() + 1,
        ]);

        // Only device 1 fails, so the tests can show that another device's items are unaffected.
        if (self::$mode === 'explode' && str_ends_with($envelope->deviceId, 'd1')) {
            throw new RuntimeException('database exploded: secret internal detail');
        }

        return OfflineOutcome::accepted(['sales' => self::count()], 1);
    }
}
