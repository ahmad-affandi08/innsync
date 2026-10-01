<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

/**
 * A module's handler for one offline operation type (for example a POS sale or a
 * room status change). The foundation owns everything around it: validation of
 * the envelope, property scope, authorization, idempotency, atomicity, the
 * recorded exception and the response. The handler owns only the business rule.
 *
 * `handle` runs inside the idempotency transaction, together with the module's
 * own writes, so a failure leaves no partial effect and a retry runs again.
 * External I/O belongs after commit (outbox). It must be deterministic for the
 * same envelope and must not trust anything the client claims about identity,
 * permissions or time.
 */
interface OfflineOperationHandler
{
    /** Stable type name, e.g. `fnb.pos.sale`. Unique across handlers. */
    public function type(): string;

    /** @return list<int> payload versions this server still understands */
    public function payloadVersions(): array;

    /**
     * Permission code the actor must hold in the active property, checked on the
     * server for every item. null means any signed-in user of the property.
     */
    public function permission(): ?string;

    public function handle(OfflineEnvelope $envelope, OfflineContext $context): OfflineOutcome;
}
