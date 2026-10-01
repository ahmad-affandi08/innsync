<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Shared\Application\Integration\CallContext;
use App\Shared\Application\Integration\CallOutcome;
use App\Shared\Application\Integration\ExternalCallExecutor;
use App\Shared\Application\Integration\ProviderCallResult;
use App\Shared\Application\Integration\ProviderTimedOut;
use App\Shared\Application\Integration\ProviderUnreachable;
use App\Shared\Application\Integration\UnknownOutcomes;
use App\Shared\Application\Integration\UnknownProvider;
use App\Shared\Application\Observability\Health\HealthStatus;
use App\Shared\Application\Offline\UnexpectedFailureReporter;
use App\Shared\Application\Privacy\PrivacyRefused;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Infrastructure\Integration\IntegrationHealthCheck;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Support\AdjustableClock;
use Tests\Support\SignsInToProperty;
use Tests\TestCase;
use Throwable;

final class ExternalIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use SignsInToProperty;

    private const A = '01arz3ndektsv4rrffq69g5fav';

    private const B = '01arz3ndektsv4rrffq69g5faw';

    private const KEY = 'bill-77-attempt-1-000001';

    private AdjustableClock $clock;

    /** @var list<Throwable> */
    private array $reported = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new AdjustableClock;
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(UnexpectedFailureReporter::class, new class($this->reported) implements UnexpectedFailureReporter
        {
            /** @param list<Throwable> $sink */
            public function __construct(private array &$sink) {}

            public function report(Throwable $failure): void
            {
                $this->sink[] = $failure;
            }
        });
        Context::add('correlation_id', '01arz3ndektsv4rrffq69g5fat');
        config(['integrations.providers.testpay' => [
            'property_id' => self::A,
            'webhook_secrets' => ['whsec_test_secret_value_1234567890'],
            'failure_threshold' => 3,
            'open_seconds' => 60,
            'connect_timeout_seconds' => 2,
            'read_timeout_seconds' => 7,
        ]]);
        $this->createProperty(self::A, 'A');
        $this->createProperty(self::B, 'B');
        app(PropertyContext::class)->activateFromString(self::A);
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    private function invoke(\Closure $adapter, string $key = self::KEY, string $operation = 'charge'): ProviderCallResult
    {
        return app(ExternalCallExecutor::class)->execute('testpay', $operation, $key, $adapter);
    }

    private function unreachable(string $key): ProviderCallResult
    {
        return $this->invoke(static fn (): never => throw new ProviderUnreachable('down'), $key);
    }

    public function test_an_adapter_gets_bounded_timeouts_the_idempotency_key_and_the_correlation_id(): void
    {
        $seen = null;

        $result = $this->invoke(function (CallContext $context) use (&$seen): ProviderCallResult {
            $seen = $context;

            return ProviderCallResult::succeeded(['reference' => 'ref-1']);
        });

        self::assertTrue($result->isSuccess());
        self::assertSame(['reference' => 'ref-1'], $result->data);
        self::assertSame([2, 7, self::KEY, '01arz3ndektsv4rrffq69g5fat', 'charge'], [$seen->connectTimeoutSeconds, $seen->readTimeoutSeconds, $seen->idempotencyKey, $seen->correlationId, $seen->operation]);
    }

    public function test_transport_failures_are_translated_into_honest_outcomes(): void
    {
        $unreachable = $this->invoke(static fn (): never => throw new ProviderUnreachable('no route'));
        self::assertSame([CallOutcome::Retryable, 'unreachable'], [$unreachable->outcome, $unreachable->code]);
        self::assertSame(0, DB::table('integration_unknowns')->count());

        $timeout = $this->invoke(static fn (): never => throw new ProviderTimedOut('slow'), 'bill-77-attempt-2-000002');
        self::assertSame([CallOutcome::Unknown, 'timeout'], [$timeout->outcome, $timeout->code]);
        self::assertSame(1, DB::table('integration_unknowns')->where('status', 'open')->count());

        $rejected = $this->invoke(static fn (): ProviderCallResult => ProviderCallResult::rejected('insufficient_funds'), 'bill-77-attempt-3-000003');
        self::assertSame([CallOutcome::Rejected, 'insufficient_funds'], [$rejected->outcome, $rejected->code]);
    }

    public function test_an_unexpected_adapter_error_is_reported_and_treated_as_unknown_never_as_a_clean_failure(): void
    {
        $result = $this->invoke(static fn (): never => throw new RuntimeException('boom'));

        self::assertSame([CallOutcome::Unknown, 'adapter_error'], [$result->outcome, $result->code]);
        self::assertCount(1, $this->reported);
        self::assertSame(1, DB::table('integration_unknowns')->count());
    }

    public function test_the_same_unknown_call_is_recorded_once_even_when_repeated(): void
    {
        foreach ([1, 2, 3] as $ignored) {
            $this->invoke(static fn (): never => throw new ProviderTimedOut('slow'));
        }

        self::assertSame(1, DB::table('integration_unknowns')->count());
    }

    public function test_the_circuit_opens_after_the_threshold_and_then_refuses_without_calling_the_provider(): void
    {
        foreach (['k-0000000000000001', 'k-0000000000000002', 'k-0000000000000003'] as $key) {
            $this->unreachable($key);
        }

        $called = false;
        $result = $this->invoke(function () use (&$called): ProviderCallResult {
            $called = true;

            return ProviderCallResult::succeeded();
        }, 'k-0000000000000004');

        self::assertFalse($called);
        self::assertSame([CallOutcome::Retryable, 'circuit_open'], [$result->outcome, $result->code]);
        self::assertNotNull(DB::table('integration_circuits')->where('provider', 'testpay')->value('opened_at'));
    }

    public function test_a_refusal_from_the_provider_does_not_count_against_the_circuit(): void
    {
        foreach (range(1, 6) as $i) {
            $this->invoke(static fn (): ProviderCallResult => ProviderCallResult::rejected('declined'), sprintf('rej-000000000000%02d', $i));
        }

        $result = $this->invoke(static fn (): ProviderCallResult => ProviderCallResult::succeeded(), 'rej-0000000000000099');
        self::assertTrue($result->isSuccess());
    }

    public function test_after_the_open_period_one_trial_goes_out_and_success_closes_the_circuit(): void
    {
        foreach (['k-0000000000000001', 'k-0000000000000002', 'k-0000000000000003'] as $key) {
            $this->unreachable($key);
        }

        $this->clock->advance('+61 seconds');
        $calls = 0;
        $trial = $this->invoke(function () use (&$calls): ProviderCallResult {
            $calls++;
            // While the trial is running a second caller must be refused.
            $second = $this->invoke(static fn (): ProviderCallResult => ProviderCallResult::succeeded(), 'k-0000000000000010');
            self::assertSame('circuit_open', $second->code);

            return ProviderCallResult::succeeded();
        }, 'k-0000000000000009');

        self::assertTrue($trial->isSuccess());
        self::assertSame(1, $calls);
        self::assertNull(DB::table('integration_circuits')->where('provider', 'testpay')->value('opened_at'));
        self::assertTrue($this->invoke(static fn (): ProviderCallResult => ProviderCallResult::succeeded(), 'k-0000000000000011')->isSuccess());
    }

    public function test_a_failed_trial_reopens_the_circuit_immediately(): void
    {
        foreach (['k-0000000000000001', 'k-0000000000000002', 'k-0000000000000003'] as $key) {
            $this->unreachable($key);
        }

        $this->clock->advance('+61 seconds');
        $this->unreachable('k-0000000000000009');

        self::assertSame('circuit_open', $this->unreachable('k-0000000000000012')->code);
    }

    public function test_calls_with_a_bad_operation_key_property_or_provider_are_refused_before_any_call(): void
    {
        $never = static fn (): never => throw new LogicException('must not be called');
        $executor = app(ExternalCallExecutor::class);

        foreach ([['testpay', 'Charge!', self::KEY], ['testpay', 'charge', 'short']] as [$provider, $operation, $key]) {
            try {
                $executor->execute($provider, $operation, $key, $never);
                self::fail('A malformed call was made.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $executor->execute('nobody', 'charge', self::KEY, $never);
            self::fail('An unknown provider was called.');
        } catch (UnknownProvider) {
            $this->addToAssertionCount(1);
        }

        app(PropertyContext::class)->activateFromString(self::B);
        $this->expectException(InvalidArgumentException::class);
        $executor->execute('testpay', 'charge', self::KEY, $never);
    }

    public function test_an_unknown_outcome_is_reconciled_by_an_authorized_person_once_with_evidence_and_audit(): void
    {
        $officer = UserRecord::factory()->create();
        $plain = UserRecord::factory()->create();
        $this->grant($officer, self::A, [UnknownOutcomes::RECONCILE_PERMISSION]);
        $this->grant($plain, self::A, ['front-office.reservation.view']);
        $this->invoke(static fn (): never => throw new ProviderTimedOut('slow'));
        $id = (string) DB::table('integration_unknowns')->value('id');
        $outcomes = app(UnknownOutcomes::class);
        $property = PropertyId::fromString(self::A);

        self::assertCount(1, $outcomes->open($property, strtolower((string) $officer->getKey())));

        foreach ([[strtolower((string) $plain->getKey()), 'Seen in the gateway dashboard', 403], [strtolower((string) $officer->getKey()), '  ', 422]] as [$actor, $reason, $status]) {
            try {
                $outcomes->resolve($property, $actor, $id, true, $reason);
                self::fail('An invalid reconciliation was accepted.');
            } catch (PrivacyRefused $e) {
                self::assertSame($status, $e->status());
            }
        }

        $outcomes->resolve($property, strtolower((string) $officer->getKey()), $id, true, 'Settlement report line 14 shows the charge');
        self::assertSame('resolved_succeeded', DB::table('integration_unknowns')->where('id', $id)->value('status'));
        self::assertNotNull(DB::table('audit_entries')->where('action', 'integration.unknown.resolved')->where('aggregate_id', $id)->first());

        try {
            $outcomes->resolve($property, strtolower((string) $officer->getKey()), $id, false, 'Again');
            self::fail('An outcome was resolved twice.');
        } catch (PrivacyRefused $e) {
            self::assertSame(409, $e->status());
        }

        $this->expectException(QueryException::class);
        DB::table('integration_unknowns')->where('id', $id)->delete();
    }

    public function test_the_health_check_reports_degraded_then_down_for_unreconciled_outcomes_and_degraded_for_open_circuits(): void
    {
        $check = app(IntegrationHealthCheck::class);
        self::assertSame(HealthStatus::Ok, $check->check()->status);

        $this->invoke(static fn (): never => throw new ProviderTimedOut('slow'));
        self::assertSame(HealthStatus::Degraded, $check->check()->status);

        $this->clock->advance('+5 hours');
        self::assertSame(HealthStatus::Down, $check->check()->status);

        DB::table('integration_unknowns')->update(['status' => 'resolved_failed', 'resolved_by' => UserRecord::factory()->create()->getKey(), 'resolved_at' => now(), 'resolution_reason' => 'x']);
        self::assertSame(HealthStatus::Ok, $check->check()->status);

        foreach (['k-0000000000000001', 'k-0000000000000002', 'k-0000000000000003'] as $key) {
            $this->unreachable($key);
        }

        self::assertSame(HealthStatus::Degraded, $check->check()->status);
    }
}
