<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use App\Shared\Application\Idempotency\IdempotencyConflict;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotencyResultUnreadable;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Tenancy\MissingPropertyContext;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class IdempotencyExecutionTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '01arz3ndektsv4rrffq69g5fat';

    private const REPLAY_CORRELATION_ID = '01arz3ndektsv4rrffq69g5fas';

    private const KEY = '01arz3ndektsv4rrffq69g5fax';

    private const PROPERTY_A = '01arz3ndektsv4rrffq69g5fav';

    private const PROPERTY_B = '01arz3ndektsv4rrffq69g5faw';

    private UserRecord $user;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::PROPERTY_A, 'Property A');
        $this->createProperty(self::PROPERTY_B, 'Property B');
        $this->user = UserRecord::factory()->create();
        Context::add('correlation_id', self::CORRELATION_ID);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    public function test_duplicate_retry_replays_same_encrypted_result_without_repeating_effect(): void
    {
        $request = $this->request(self::PROPERTY_A, ['folio_id' => 'folio-1']);
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);
        $executions = 0;

        $first = app(IdempotentExecutor::class)->execute(
            $request,
            function () use (&$executions): array {
                $executions++;
                DB::table('cache')->insert([
                    'key' => 'idempotent-effect',
                    'value' => 'created-once',
                    'expiration' => time() + 3600,
                ]);

                return ['reference' => 'checkout-123', 'status' => 'accepted'];
            },
        );
        Context::add('correlation_id', self::REPLAY_CORRELATION_ID);
        $replay = app(IdempotentExecutor::class)->execute(
            $request,
            function () use (&$executions): array {
                $executions++;

                return ['reference' => 'must-not-run'];
            },
        );

        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($first->payload, $replay->payload);
        self::assertSame(1, $executions);
        self::assertSame(1, DB::table('cache')->where('key', 'idempotent-effect')->count());

        $record = DB::table('idempotency_operations')->firstOrFail();
        self::assertSame(1, $record->replay_count);
        self::assertSame(self::CORRELATION_ID, $record->correlation_id);
        self::assertSame(self::REPLAY_CORRELATION_ID, $record->last_replay_correlation_id);
        self::assertNotSame(self::KEY, $record->key_hash);
        self::assertStringNotContainsString('checkout-123', $record->result_payload);
    }

    public function test_equivalent_payload_key_order_replays_but_changed_payload_conflicts(): void
    {
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);
        $executor = app(IdempotentExecutor::class);

        $executor->execute(
            $this->request(self::PROPERTY_A, ['amount' => 1000, 'folio' => ['id' => 'A', 'version' => 1]]),
            static fn (): array => ['status' => 'accepted'],
        );

        $equivalent = $executor->execute(
            $this->request(self::PROPERTY_A, ['folio' => ['version' => 1, 'id' => 'A'], 'amount' => 1000]),
            static fn (): array => ['status' => 'must-not-run'],
        );
        self::assertTrue($equivalent->replayed);

        $this->expectException(IdempotencyConflict::class);
        $executor->execute(
            $this->request(self::PROPERTY_A, ['amount' => 2000, 'folio' => ['id' => 'A', 'version' => 1]]),
            static fn (): array => ['status' => 'must-not-run'],
        );
    }

    public function test_failed_operation_rolls_back_claim_and_business_effect_then_can_retry(): void
    {
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);
        $request = $this->request(self::PROPERTY_A, ['folio_id' => 'folio-rollback']);
        $executor = app(IdempotentExecutor::class);

        try {
            $executor->execute($request, static function (): array {
                DB::table('cache')->insert([
                    'key' => 'rolled-back-effect',
                    'value' => 'unsafe',
                    'expiration' => time() + 3600,
                ]);

                throw new RuntimeException('Force rollback.');
            });
        } catch (RuntimeException) {
            // Expected: claim and business write must roll back together.
        }

        self::assertSame(0, DB::table('idempotency_operations')->count());
        self::assertSame(0, DB::table('cache')->where('key', 'rolled-back-effect')->count());

        $retry = $executor->execute(
            $request,
            static fn (): array => ['status' => 'accepted-after-retry'],
        );

        self::assertFalse($retry->replayed);
        self::assertSame('accepted-after-retry', $retry->payload['status']);
    }

    public function test_scope_fails_closed_without_context_and_rejects_cross_property(): void
    {
        $executor = app(IdempotentExecutor::class);
        $request = $this->request(self::PROPERTY_A, []);

        try {
            $executor->execute($request, static fn (): array => ['status' => 'unsafe']);
            self::fail('Idempotent execution ran without property context.');
        } catch (MissingPropertyContext) {
            self::assertSame(0, DB::table('idempotency_operations')->count());
        }

        app(PropertyContext::class)->activate(PropertyId::fromString(self::PROPERTY_B));

        $this->expectException(PropertyScopeViolation::class);
        $executor->execute($request, static fn (): array => ['status' => 'unsafe']);
    }

    public function test_same_key_can_be_scoped_to_different_operation_or_property(): void
    {
        $executor = app(IdempotentExecutor::class);
        $context = app(PropertyContext::class);

        $context->activateFromString(self::PROPERTY_A);
        $executor->execute(
            $this->request(self::PROPERTY_A, [], 'front-office.checkout'),
            static fn (): array => ['reference' => 'A-checkout'],
        );
        $executor->execute(
            $this->request(self::PROPERTY_A, [], 'front-office.refund'),
            static fn (): array => ['reference' => 'A-refund'],
        );

        $context->activateFromString(self::PROPERTY_B);
        $executor->execute(
            $this->request(self::PROPERTY_B, [], 'front-office.checkout'),
            static fn (): array => ['reference' => 'B-checkout'],
        );

        self::assertSame(3, DB::table('idempotency_operations')->count());
    }

    public function test_same_key_cannot_be_replayed_by_a_different_actor(): void
    {
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);
        $request = $this->request(self::PROPERTY_A, ['folio_id' => 'folio-actor']);
        $executor = app(IdempotentExecutor::class);
        $executor->execute($request, static fn (): array => ['status' => 'accepted']);
        $otherUser = UserRecord::factory()->create();
        $otherActorRequest = new IdempotencyRequest(
            PropertyId::fromString(self::PROPERTY_A),
            IdempotencyKey::fromString(self::KEY),
            'front-office.checkout',
            ['folio_id' => 'folio-actor'],
            (string) $otherUser->getKey(),
        );

        try {
            $executor->execute(
                $otherActorRequest,
                static fn (): array => ['status' => 'must-not-run'],
            );
            self::fail('A different actor replayed an existing idempotency key.');
        } catch (IdempotencyConflict) {
            $this->assertDatabaseHas('security_events', [
                'property_id' => self::PROPERTY_A,
                'actor_id' => $otherUser->getKey(),
                'event_type' => 'idempotency.conflict',
                'outcome' => 'denied',
            ]);
        }
    }

    public function test_unique_constraint_and_tampered_result_fail_closed(): void
    {
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);
        $request = $this->request(self::PROPERTY_A, []);
        $executor = app(IdempotentExecutor::class);
        $executor->execute($request, static fn (): array => ['status' => 'accepted']);
        $record = DB::table('idempotency_operations')->firstOrFail();

        try {
            DB::table('idempotency_operations')->insert((array) $record);
            self::fail('The database accepted a duplicate idempotency operation.');
        } catch (QueryException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        DB::table('idempotency_operations')
            ->where('id', $record->id)
            ->update(['result_payload' => 'tampered']);

        try {
            $executor->execute($request, static fn (): array => ['status' => 'must-not-run']);
            self::fail('A tampered encrypted result was replayed.');
        } catch (IdempotencyResultUnreadable) {
            $this->assertDatabaseHas('security_events', [
                'property_id' => self::PROPERTY_A,
                'actor_id' => $this->user->getKey(),
                'event_type' => 'idempotency.stored-result.invalid',
                'outcome' => 'denied',
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function request(
        string $propertyId,
        array $payload,
        string $operation = 'front-office.checkout',
    ): IdempotencyRequest {
        return new IdempotencyRequest(
            PropertyId::fromString($propertyId),
            IdempotencyKey::fromString(self::KEY),
            $operation,
            $payload,
            (string) $this->user->getKey(),
        );
    }

    private function createProperty(string $id, string $name): void
    {
        $property = new PropertyRecord([
            'name' => $name,
            'timezone' => 'Asia/Jakarta',
            'currency_code' => 'IDR',
        ]);
        $property->id = $id;
        $property->save();
    }
}
