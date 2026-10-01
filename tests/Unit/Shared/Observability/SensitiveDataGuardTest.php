<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Observability;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Observability\SensitiveDataDetected;
use App\Shared\Infrastructure\Observability\ConfigureStructuredLogging;
use App\Shared\Infrastructure\Observability\RedactSensitiveLogContext;
use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SensitiveDataGuardTest extends TestCase
{
    public function test_audit_and_security_metadata_reject_sensitive_fields(): void
    {
        $this->expectException(SensitiveDataDetected::class);

        new AuditEntry(
            null,
            null,
            'identity.password.changed',
            'user',
            '01arz3ndektsv4rrffq69g5fav',
            ['status' => 'active'],
            ['nested' => ['password' => 'must-not-be-recorded']],
        );
    }

    public function test_application_log_processor_redacts_nested_sensitive_context(): void
    {
        $record = new LogRecord(
            new \DateTimeImmutable,
            'test',
            Level::Info,
            'security event',
            [
                'password' => 'secret-value',
                'safe' => 'visible',
                'nested' => ['token' => 'token-value'],
            ],
        );

        $redacted = (new RedactSensitiveLogContext)($record);

        self::assertSame('[REDACTED]', $redacted->context['password']);
        self::assertSame('visible', $redacted->context['safe']);
        self::assertSame('[REDACTED]', $redacted->context['nested']['token']);
    }

    public function test_structured_logging_configures_json_and_redaction_on_handlers(): void
    {
        $handler = new TestHandler;
        $logger = new Logger(new MonologLogger('test', [$handler]));

        (new ConfigureStructuredLogging)($logger);
        $logger->info('structured', ['password' => 'secret-value', 'safe' => 'visible']);

        self::assertInstanceOf(JsonFormatter::class, $handler->getFormatter());
        self::assertSame('[REDACTED]', $handler->getRecords()[0]->context['password']);
        self::assertSame('visible', $handler->getRecords()[0]->context['safe']);
    }

    public function test_audit_entry_requires_at_least_one_state_snapshot(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AuditEntry(
            null,
            null,
            'entity.changed',
            'entity',
            '01arz3ndektsv4rrffq69g5fav',
            null,
            null,
        );
    }
}
