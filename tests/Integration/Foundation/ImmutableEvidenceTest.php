<?php

declare(strict_types=1);

namespace Tests\Integration\Foundation;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventOutcome;
use App\Shared\Application\Security\SecurityLog;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class ImmutableEvidenceTest extends TestCase
{
    use DatabaseMigrations;

    private const CORRELATION_ID = '01arz3ndektsv4rrffq69g5fax';

    private const PROPERTY_A = '01arz3ndektsv4rrffq69g5fav';

    private const PROPERTY_B = '01arz3ndektsv4rrffq69g5faw';

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
        Context::add('correlation_id', self::CORRELATION_ID);
        Context::addHidden('security_source_ip_hash', str_repeat('a', 64));
    }

    protected function tearDown(): void
    {
        app(PropertyContext::class)->clear();
        Context::flush();

        parent::tearDown();
    }

    public function test_audit_entry_records_complete_scoped_immutable_evidence(): void
    {
        config(['evidence.audit_minimum_retention_days' => 30]);
        $user = UserRecord::factory()->create();
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);

        app(AuditTrail::class)->record(new AuditEntry(
            self::PROPERTY_A,
            (string) $user->getKey(),
            'reservation.status.changed',
            'reservation',
            '01arz3ndektsv4rrffq69g5fay',
            ['status' => 'pending'],
            ['status' => 'confirmed'],
            'Guest confirmed arrival',
            '01arz3ndektsv4rrffq69g5faz',
        ));

        $entry = DB::table('audit_entries')->firstOrFail();

        self::assertSame(self::PROPERTY_A, $entry->property_id);
        self::assertSame((string) $user->getKey(), $entry->actor_id);
        self::assertSame(self::CORRELATION_ID, $entry->correlation_id);
        self::assertSame(['status' => 'pending'], json_decode($entry->before_state, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame(['status' => 'confirmed'], json_decode($entry->after_state, true, flags: JSON_THROW_ON_ERROR));
        self::assertNotNull($entry->minimum_retention_until);
        self::assertSame(64, strlen($entry->payload_checksum));
    }

    public function test_audit_entry_participates_in_callers_database_transaction(): void
    {
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);

        try {
            DB::transaction(function (): never {
                app(AuditTrail::class)->record(new AuditEntry(
                    self::PROPERTY_A,
                    null,
                    'entity.created',
                    'entity',
                    '01arz3ndektsv4rrffq69g5fay',
                    null,
                    ['status' => 'created'],
                ));

                throw new RuntimeException('Force rollback.');
            });
        } catch (RuntimeException) {
            // Expected rollback proves the audit write shares the transaction.
        }

        self::assertSame(0, DB::table('audit_entries')->count());
    }

    public function test_cross_property_audit_write_is_rejected(): void
    {
        app(PropertyContext::class)->activate(PropertyId::fromString(self::PROPERTY_A));

        $this->expectException(PropertyScopeViolation::class);

        app(AuditTrail::class)->record(new AuditEntry(
            self::PROPERTY_B,
            null,
            'entity.changed',
            'entity',
            '01arz3ndektsv4rrffq69g5fay',
            ['status' => 'old'],
            ['status' => 'new'],
        ));
    }

    public function test_security_event_is_separate_hashed_and_contains_no_raw_ip(): void
    {
        app(SecurityLog::class)->record(new SecurityEvent(
            'identity.authentication',
            SecurityEventOutcome::Failure,
            metadata: ['reason_code' => 'invalid_credentials'],
        ));

        $event = DB::table('security_events')->firstOrFail();

        self::assertSame(str_repeat('a', 64), $event->source_ip_hash);
        self::assertSame(self::CORRELATION_ID, $event->correlation_id);
        self::assertSame(
            ['reason_code' => 'invalid_credentials'],
            json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertStringNotContainsString('127.0.0.1', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_database_triggers_reject_updates_and_deletes_for_both_evidence_tables(): void
    {
        app(PropertyContext::class)->activateFromString(self::PROPERTY_A);
        app(AuditTrail::class)->record(new AuditEntry(
            self::PROPERTY_A,
            null,
            'entity.created',
            'entity',
            '01arz3ndektsv4rrffq69g5fay',
            null,
            ['status' => 'created'],
        ));
        app(SecurityLog::class)->record(new SecurityEvent(
            'identity.authorization',
            SecurityEventOutcome::Denied,
        ));

        $this->assertImmutableMutationFails(
            static fn (): int => DB::table('audit_entries')->update(['reason' => 'tampered']),
        );
        $this->assertImmutableMutationFails(
            static fn (): int => DB::table('audit_entries')->delete(),
        );
        $this->assertImmutableMutationFails(
            static fn (): int => DB::table('security_events')->update(['outcome' => 'success']),
        );
        $this->assertImmutableMutationFails(
            static fn (): int => DB::table('security_events')->delete(),
        );

        self::assertSame(1, DB::table('audit_entries')->count());
        self::assertSame(1, DB::table('security_events')->count());
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

    /** @param callable(): int $mutation */
    private function assertImmutableMutationFails(callable $mutation): void
    {
        try {
            $mutation();
            self::fail('Immutable evidence mutation unexpectedly succeeded.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('immutable evidence', $exception->getMessage());
        }
    }
}
