<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use App\Shared\Application\Observability\Health\HealthCheck;
use App\Shared\Application\Observability\Health\HealthCheckRegistry;
use App\Shared\Application\Observability\Health\HealthResult;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_public_probe_exposes_only_overall_status_without_session(): void
    {
        $this->useChecks(HealthResult::ok('fine'));

        $response = $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);

        $response->assertHeader('Cache-Control', 'no-store, private');
        self::assertEmpty($response->headers->getCookies());
    }

    public function test_degraded_status_stays_200(): void
    {
        $this->useChecks(HealthResult::degraded('slow'));
        $this->getJson('/health')->assertOk()->assertJson(['status' => 'degraded']);
    }

    public function test_down_status_returns_503_without_detail(): void
    {
        $this->useChecks(HealthResult::down('secret internal detail'));
        $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'down']);
    }

    public function test_details_are_hidden_without_a_configured_secret(): void
    {
        config(['observability.health_token' => null]);

        $this->getJson('/health/details')->assertNotFound();
        $this->withToken('anything')->getJson('/health/details')->assertNotFound();
    }

    public function test_details_require_the_exact_bearer_secret(): void
    {
        config(['observability.health_token' => 'monitor-secret-value']);
        $this->useChecks(HealthResult::degraded('queue delayed', ['age_seconds' => 400]));

        $this->getJson('/health/details')->assertNotFound();
        $this->withToken('wrong')->getJson('/health/details')->assertNotFound();

        $this->withToken('monitor-secret-value')->getJson('/health/details')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.probe.summary', 'queue delayed')
            ->assertJsonPath('checks.probe.context.age_seconds', 400);
    }

    public function test_failed_detail_attempts_are_recorded_as_security_events(): void
    {
        config(['observability.health_token' => 'monitor-secret-value']);

        $this->withToken('wrong')->getJson('/health/details')->assertNotFound();

        $this->assertDatabaseHas('security_events', ['event_type' => 'health.details-denied']);
    }

    public function test_probe_is_rate_limited(): void
    {
        $this->useChecks(HealthResult::ok('fine'));

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/health')->assertOk();
        }

        $this->getJson('/health')->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
    }

    private function useChecks(HealthResult $result): void
    {
        $this->app->instance(HealthCheckRegistry::class, new class($result) implements HealthCheckRegistry
        {
            public function __construct(private HealthResult $result) {}

            public function all(): array
            {
                $result = $this->result;

                return [new class($result) implements HealthCheck
                {
                    public function __construct(private HealthResult $result) {}

                    public function name(): string
                    {
                        return 'probe';
                    }

                    public function check(): HealthResult
                    {
                        return $this->result;
                    }
                }];
            }
        });
    }
}
