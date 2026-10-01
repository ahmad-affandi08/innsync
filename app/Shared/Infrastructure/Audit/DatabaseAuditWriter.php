<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditWriter;
use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Infrastructure\Observability\ImmutableEvidencePayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DatabaseAuditWriter implements AuditWriter
{
    public function __construct(
        private CorrelationId $correlationId,
        private PropertyContext $propertyContext,
    ) {}

    public function write(AuditEntry $entry): void
    {
        $this->assertPropertyScope($entry->propertyId);

        $occurredAt = CarbonImmutable::now('UTC');
        $payload = [
            'id' => strtolower((string) Str::ulid()),
            'property_id' => $entry->propertyId,
            'actor_id' => $entry->actorId,
            'action' => $entry->action,
            'aggregate_type' => $entry->aggregateType,
            'aggregate_id' => $entry->aggregateId,
            'before_state' => ImmutableEvidencePayload::encode($entry->before),
            'after_state' => ImmutableEvidencePayload::encode($entry->after),
            'reason' => $entry->reason,
            'approval_reference' => $entry->approvalReference,
            'correlation_id' => $this->correlationId->current(),
            'occurred_at' => $occurredAt,
            'minimum_retention_until' => ImmutableEvidencePayload::minimumRetentionUntil(
                $occurredAt,
                config('evidence.audit_minimum_retention_days'),
            ),
        ];

        DB::table('audit_entries')->insert([
            ...$payload,
            'payload_checksum' => ImmutableEvidencePayload::checksum($payload),
        ]);
    }

    private function assertPropertyScope(?string $propertyId): void
    {
        if ($propertyId === null) {
            return;
        }

        $activePropertyId = $this->propertyContext->current()->toString();

        if ($activePropertyId !== $propertyId) {
            throw PropertyScopeViolation::mismatched($activePropertyId, $propertyId);
        }
    }
}
