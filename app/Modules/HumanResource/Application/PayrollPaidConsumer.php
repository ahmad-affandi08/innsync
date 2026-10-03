<?php

declare(strict_types=1);

namespace App\Modules\HumanResource\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Outbox\OutboxConsumer;
use App\Shared\Application\Outbox\OutboxMessage;

/** Finance paid an approved payroll run (FR-HR-037): the run is marked paid, once however often the event is delivered. */
final readonly class PayrollPaidConsumer implements OutboxConsumer
{
    public function __construct(private PayrollRunStore $store, private AuditTrail $audit) {}

    public function name(): string
    {
        return 'hr.payroll-paid';
    }

    public function supports(string $eventType): bool
    {
        return $eventType === 'finance.payroll.paid';
    }

    public function consume(OutboxMessage $message): void
    {
        $property = $message->event->propertyId;
        $d = $message->event->data;
        $run = $this->store->run($property, strtolower((string) $d['run_id']));

        if ($run === null || $run['status'] !== 'approved') {
            return;
        }

        $at = $message->occurredAt;

        if ($this->store->updateRun($property, $run['id'], (int) $run['lock_version'], ['status' => 'paid', 'paid_at' => $at->format('Y-m-d H:i:s.u'), 'paid_reference' => mb_substr((string) $d['reference'], 0, 80)], $at)) {
            $this->audit->record(new AuditEntry($property->toString(), null, 'payroll_run.paid', 'payroll_run', $run['id'], ['status' => 'approved'], ['status' => 'paid', 'period' => $run['period'], 'reference' => (string) $d['reference']]));
        }
    }
}
