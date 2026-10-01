<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Deployment;

use App\Shared\Application\Deployment\Findings;
use App\Shared\Application\Deployment\PreflightEvaluator;
use App\Shared\Application\Deployment\PreflightInputs;
use App\Shared\Application\Deployment\Severity;
use PHPUnit\Framework\TestCase;

final class PreflightEvaluatorTest extends TestCase
{
    /** @param array<string, mixed> $override */
    private function inputs(array $override = []): PreflightInputs
    {
        $base = [
            'phpVersion' => '8.3.12',
            'extensions' => PreflightEvaluator::REQUIRED_EXTENSIONS,
            'appEnv' => 'production',
            'appDebug' => false,
            'appKeySet' => true,
            'appUrl' => 'https://hotel.example',
            'sessionSecureCookie' => true,
            'sessionEncrypted' => true,
            'queueConnection' => 'database',
            'outboxQueueConnection' => 'database',
            'inertiaSsrEnabled' => false,
            'writable' => ['storage' => true, 'storage/logs' => true, 'storage/framework' => true, 'bootstrap/cache' => true],
            'publicSensitiveFiles' => [],
            'databaseVersion' => '8.0.36',
            'databaseError' => null,
            'pendingMigrations' => 0,
            'healthTokenSet' => true,
            'idempotencyKeySet' => true,
            'backupConfigured' => true,
        ];

        return new PreflightInputs(...array_merge($base, $override));
    }

    private function evaluate(array $override = []): Findings
    {
        return (new PreflightEvaluator)->evaluate($this->inputs($override));
    }

    private function severity(Findings $findings, string $check): ?Severity
    {
        foreach ($findings->items as $finding) {
            if ($finding->check === $check) {
                return $finding->severity;
            }
        }

        return null;
    }

    public function test_a_correctly_configured_production_host_passes_cleanly(): void
    {
        $findings = $this->evaluate();

        self::assertTrue($findings->passes(strict: true));
        self::assertSame(0, $findings->count(Severity::Failure));
        self::assertSame(0, $findings->count(Severity::Warning));
    }

    public function test_runtime_requirements_block_the_release(): void
    {
        self::assertSame(Severity::Failure, $this->severity($this->evaluate(['phpVersion' => '8.2.20']), 'php.version'));
        self::assertSame(Severity::Ok, $this->severity($this->evaluate(['phpVersion' => '8.3.0']), 'php.version'));
        self::assertSame(Severity::Ok, $this->severity($this->evaluate(['phpVersion' => '8.4.1']), 'php.version'));

        $missing = $this->evaluate(['extensions' => ['PDO', 'mbstring']]);
        self::assertSame(Severity::Failure, $this->severity($missing, 'php.extensions'));
        self::assertFalse($missing->passes());

        // Extension names are matched case-insensitively.
        $upper = $this->evaluate(['extensions' => array_map('strtoupper', PreflightEvaluator::REQUIRED_EXTENSIONS)]);
        self::assertSame(Severity::Ok, $this->severity($upper, 'php.extensions'));
    }

    public function test_production_safety_settings_are_failures_in_production(): void
    {
        foreach ([
            'app.debug' => ['appDebug' => true],
            'app.key' => ['appKeySet' => false],
            'app.url' => ['appUrl' => 'http://hotel.example'],
            'session.secure' => ['sessionSecureCookie' => false],
            'backup.configured' => ['backupConfigured' => false],
        ] as $check => $override) {
            $findings = $this->evaluate($override);

            self::assertSame(Severity::Failure, $this->severity($findings, $check), $check);
            self::assertFalse($findings->passes(), $check);
        }
    }

    public function test_the_same_settings_only_warn_outside_production(): void
    {
        $findings = $this->evaluate(['appEnv' => 'staging', 'appDebug' => true, 'appUrl' => 'http://localhost', 'backupConfigured' => false]);

        self::assertSame(Severity::Warning, $this->severity($findings, 'app.debug'));
        self::assertSame(Severity::Warning, $this->severity($findings, 'app.url'));
        self::assertSame(Severity::Warning, $this->severity($findings, 'backup.configured'));
        self::assertSame(Severity::Warning, $this->severity($findings, 'app.env'));
        self::assertTrue($findings->passes());
        self::assertFalse($findings->passes(strict: true));
    }

    public function test_an_unset_session_secure_flag_follows_the_framework_default(): void
    {
        self::assertSame(Severity::Ok, $this->severity($this->evaluate(['sessionSecureCookie' => null]), 'session.secure'));
    }

    public function test_permanent_process_dependencies_are_refused(): void
    {
        // ADR-0008: server-side rendering needs a permanent Node process.
        self::assertSame(Severity::Failure, $this->severity($this->evaluate(['inertiaSsrEnabled' => true]), 'inertia.ssr'));

        $redis = $this->evaluate(['queueConnection' => 'redis', 'outboxQueueConnection' => 'redis']);
        self::assertSame(Severity::Warning, $this->severity($redis, 'queue.queue'));
        self::assertSame(Severity::Warning, $this->severity($redis, 'queue.outbox_queue'));
        self::assertTrue($redis->passes());
    }

    public function test_unwritable_runtime_directories_fail(): void
    {
        $findings = $this->evaluate(['writable' => ['storage' => true, 'storage/logs' => false, 'storage/framework' => true, 'bootstrap/cache' => false]]);

        self::assertSame(Severity::Failure, $this->severity($findings, 'writable.storage/logs'));
        self::assertSame(Severity::Failure, $this->severity($findings, 'writable.bootstrap/cache'));
        self::assertSame(Severity::Ok, $this->severity($findings, 'writable.storage'));
    }

    public function test_sensitive_files_in_the_public_root_fail_and_are_named(): void
    {
        $findings = $this->evaluate(['publicSensitiveFiles' => ['.env', 'backup.sql']]);

        self::assertSame(Severity::Failure, $this->severity($findings, 'public.exposure'));
        $message = '';
        foreach ($findings->items as $finding) {
            if ($finding->check === 'public.exposure') {
                $message = $finding->message;
            }
        }
        self::assertStringContainsString('.env, backup.sql', $message);
    }

    public function test_database_requirements(): void
    {
        $down = $this->evaluate(['databaseVersion' => null, 'databaseError' => 'PDOException', 'pendingMigrations' => null]);
        self::assertSame(Severity::Failure, $this->severity($down, 'database.connection'));

        self::assertSame(Severity::Failure, $this->severity($this->evaluate(['databaseVersion' => '5.7.44']), 'database.version'));
        self::assertSame(Severity::Failure, $this->severity($this->evaluate(['databaseVersion' => '10.11.6-MariaDB']), 'database.version'));
        self::assertSame(Severity::Ok, $this->severity($this->evaluate(['databaseVersion' => '8.4.0']), 'database.version'));
        self::assertSame(Severity::Failure, $this->severity($this->evaluate(['databaseVersion' => null]), 'database.version'));
    }

    public function test_pending_migrations_are_a_visible_warning_not_a_block(): void
    {
        $findings = $this->evaluate(['pendingMigrations' => 3]);

        self::assertSame(Severity::Warning, $this->severity($findings, 'database.migrations'));
        self::assertTrue($findings->passes());
        self::assertFalse($findings->passes(strict: true));
        self::assertSame(Severity::Warning, $this->severity($this->evaluate(['pendingMigrations' => null]), 'database.migrations'));
    }

    public function test_operational_secrets_missing_only_warn(): void
    {
        $findings = $this->evaluate(['healthTokenSet' => false, 'idempotencyKeySet' => false]);

        self::assertSame(Severity::Warning, $this->severity($findings, 'health.token'));
        self::assertSame(Severity::Warning, $this->severity($findings, 'idempotency.key'));
        self::assertTrue($findings->passes());
    }

    public function test_no_message_contains_a_secret_shaped_value(): void
    {
        $findings = $this->evaluate(['appDebug' => true, 'databaseError' => 'PDOException', 'databaseVersion' => null]);

        foreach ($findings->items as $finding) {
            self::assertDoesNotMatchRegularExpression('/base64:|password=|secret=/i', $finding->message);
        }
    }
}
