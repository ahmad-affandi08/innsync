<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Application\Observability\CorrelationId;
use App\Shared\Application\Security\SecurityEvent;
use App\Shared\Application\Security\SecurityEventWriter;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Infrastructure\Observability\ImmutableEvidencePayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DatabaseSecurityEventWriter implements SecurityEventWriter
{
    public function __construct(
        private CorrelationId $correlationId,
        private PropertyContext $propertyContext,
    ) {}

    public function write(SecurityEvent $event): void
    {
        $this->assertPropertyScope($event->propertyId);

        $occurredAt = CarbonImmutable::now('UTC');
        $sourceIpHash = Context::getHidden('security_source_ip_hash');
        $payload = [
            'id' => strtolower((string) Str::ulid()),
            'property_id' => $event->propertyId,
            'actor_id' => $event->actorId,
            'event_type' => $event->eventType,
            'outcome' => $event->outcome->value,
            'source_ip_hash' => is_string($sourceIpHash) ? $sourceIpHash : null,
            'metadata' => ImmutableEvidencePayload::encode($event->metadata),
            'correlation_id' => $this->correlationId->current(),
            'occurred_at' => $occurredAt,
            'minimum_retention_until' => ImmutableEvidencePayload::minimumRetentionUntil(
                $occurredAt,
                config('evidence.security_event_minimum_retention_days'),
            ),
        ];

        DB::table('security_events')->insert([
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
