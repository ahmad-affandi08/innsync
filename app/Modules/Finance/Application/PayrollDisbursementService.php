<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Approved payroll runs in Finance (FR-HR-031, -037): taken in from Human Resource (the contract it uses), checked by one person who confirms the net amount, and paid by another with the method and a reference. Paying
 * tells Human Resource (the event `finance.payroll.paid`), which marks the run paid; the tax and the social security that are owed on top of the net pay are shown beside it.
 */
final readonly class PayrollDisbursementService implements PayrollDisbursements
{
    public function __construct(private PayrollDisbursementStore $store, private FinanceAccess $access, private TransactionRunner $transactions, private AuditTrail $audit, private OutboxPublisher $outbox, private IdentifierGenerator $ids, private Clock $clock) {}

    public function receive(PropertyId $property, string $runId, string $number, string $period, int $employees, int $netMinor, int $taxMinor, int $employeeSocialMinor, int $employerSocialMinor, string $byUserId): void
    {
        $this->access->assertProperty($property);
        $runId = strtolower($runId);
        $by = strtolower($byUserId);
        $now = $this->clock->nowUtc();
        $existing = $this->store->byRun($property, $runId);
        $amounts = ['employees_count' => $employees, 'net_minor' => $netMinor, 'tax_minor' => $taxMinor, 'employee_social_minor' => $employeeSocialMinor, 'employer_social_minor' => $employerSocialMinor];

        if ($existing === null) {
            $id = $this->ids->next();
            $this->store->add($property, ['id' => $id, 'run_id' => $runId, 'number' => $number, 'period' => $period, ...$amounts, 'status' => 'awaiting', 'received_by' => $by], $now);
            $this->audit->record(new AuditEntry($property->toString(), $by, 'payroll_disbursement.received', 'payroll_disbursement', $id, null, ['run' => $number, ...$amounts]));

            return;
        }

        if ($existing['status'] !== 'withdrawn') {
            throw Refusal::stateConflict('Finance has this payroll run already.');
        }

        if (! $this->store->update($property, $existing['id'], (int) $existing['lock_version'], [...$amounts, 'status' => 'awaiting', 'verified_by' => null, 'verified_at' => null, 'received_by' => $by], $now)) {
            throw Refusal::stateConflict('This payroll run changed meanwhile.');
        }

        $this->audit->record(new AuditEntry($property->toString(), $by, 'payroll_disbursement.received_again', 'payroll_disbursement', $existing['id'], ['status' => 'withdrawn'], ['run' => $number, ...$amounts]));
    }

    public function withdraw(PropertyId $property, string $runId, string $byUserId): void
    {
        $this->access->assertProperty($property);
        $by = strtolower($byUserId);
        $row = $this->store->byRun($property, strtolower($runId));

        if ($row === null || $row['status'] === 'withdrawn') {
            return;
        }

        if ($row['status'] === 'paid') {
            throw Refusal::stateConflict('Finance paid this payroll run already; correct it with an adjustment in a later period.');
        }

        if (! $this->store->update($property, $row['id'], (int) $row['lock_version'], ['status' => 'withdrawn', 'verified_by' => null, 'verified_at' => null], $this->clock->nowUtc())) {
            throw Refusal::stateConflict('This payroll run changed meanwhile.');
        }

        $this->audit->record(new AuditEntry($property->toString(), $by, 'payroll_disbursement.withdrawn', 'payroll_disbursement', $row['id'], ['status' => $row['status']], ['status' => 'withdrawn', 'run' => $row['number']]));
    }

    public function statusOf(PropertyId $property, string $runId): ?string
    {
        $this->access->assertProperty($property);

        return $this->store->byRun($property, strtolower($runId))['status'] ?? null;
    }

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->requirePayrollView($property, $actorId);
        $actor = strtolower($actorId);

        return ['rows' => array_map(fn (array $r): array => self::shape($r, $actor, $this->access->may($property, $actorId, FinanceAccess::PAYROLL_VERIFY), $this->access->may($property, $actorId, FinanceAccess::PAYROLL_PAY)), $this->store->all($property))];
    }

    /** The person confirms the net amount of the run as they read it. @return array<string, mixed> */
    public function verify(PropertyId $property, string $actorId, string $id, int $confirmedNetMinor, int $lock): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PAYROLL_VERIFY, 'This person may not verify the payroll.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $confirmedNetMinor, $lock): void {
            $row = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Payroll run not found.');

            if ($row['status'] !== 'awaiting') {
                throw Refusal::stateConflict('This payroll run is not waiting to be verified.');
            }

            if ($confirmedNetMinor !== (int) $row['net_minor']) {
                throw Refusal::invalid('The amount you confirm is not the net pay of the run. Read it again.', ['confirmed_net_minor']);
            }

            $now = $this->clock->nowUtc();

            if (! $this->store->update($property, $row['id'], $lock, ['status' => 'verified', 'verified_by' => $actor, 'verified_at' => $now->format('Y-m-d H:i:s.u')], $now)) {
                throw Refusal::stateConflict('This payroll run changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_disbursement.verified', 'payroll_disbursement', $row['id'], ['status' => 'awaiting'], ['status' => 'verified', 'run' => $row['number'], 'net_minor' => (int) $row['net_minor']]));
        });

        return $this->overview($property, $actorId);
    }

    /** @return array<string, mixed> */
    public function pay(PropertyId $property, string $actorId, string $id, string $method, string $reference, int $lock): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PAYROLL_PAY, 'This person may not pay the payroll.');
        $reference = trim($reference);

        if (! in_array($method, ['transfer', 'cash'], true)) {
            throw Refusal::invalid('Choose transfer or cash.', ['method']);
        }

        if ($reference === '' || mb_strlen($reference) > 80) {
            throw Refusal::invalid('Give the reference of the payment, at most 80 characters.', ['reference']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $method, $reference, $lock): void {
            $row = $this->store->find($property, strtolower($id)) ?? throw Refusal::notFound('Payroll run not found.');

            if ($row['status'] !== 'verified') {
                throw Refusal::stateConflict('Only a verified payroll run is paid.');
            }

            if ($row['verified_by'] === $actor) {
                throw Refusal::forbidden('Another person than the one who verified the run pays it.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->store->update($property, $row['id'], $lock, ['status' => 'paid', 'paid_by' => $actor, 'paid_at' => $now->format('Y-m-d H:i:s.u'), 'method' => $method, 'reference' => $reference], $now)) {
                throw Refusal::stateConflict('This payroll run changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'payroll_disbursement.paid', 'payroll_disbursement', $row['id'], ['status' => 'verified'], ['status' => 'paid', 'run' => $row['number'], 'net_minor' => (int) $row['net_minor'], 'method' => $method, 'reference' => $reference]));
            $this->outbox->publish(new OutboxEvent($property, 'finance.payroll.paid', $row['run_id'], 1, ['run_id' => $row['run_id'], 'number' => $row['number'], 'period' => $row['period'], 'net_minor' => (int) $row['net_minor'], 'method' => $method, 'reference' => $reference]));
        });

        return $this->overview($property, $actorId);
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function shape(array $r, string $actor, bool $mayVerify, bool $mayPay): array
    {
        return [
            'id' => $r['id'], 'number' => $r['number'], 'period' => $r['period'], 'employees' => (int) $r['employees_count'], 'net_minor' => (int) $r['net_minor'], 'tax_minor' => (int) $r['tax_minor'],
            'employee_social_minor' => (int) $r['employee_social_minor'], 'employer_social_minor' => (int) $r['employer_social_minor'], 'status' => $r['status'], 'method' => $r['method'], 'reference' => $r['reference'],
            'paid_at' => $r['paid_at'], 'lock_version' => (int) $r['lock_version'],
            'may' => ['verify' => $mayVerify && $r['status'] === 'awaiting', 'pay' => $mayPay && $r['status'] === 'verified' && $r['verified_by'] !== $actor],
        ];
    }
}
