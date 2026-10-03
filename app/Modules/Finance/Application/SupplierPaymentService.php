<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Approval\ApprovalGate;
use App\Shared\Application\Approval\ApprovalRequestInput;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Files\DownloadFile;
use App\Shared\Application\Files\FileAccessDenied;
use App\Shared\Application\Files\FileAccessPolicy;
use App\Shared\Application\Files\FileContent;
use App\Shared\Application\Files\FilePolicy;
use App\Shared\Application\Files\FileRejected;
use App\Shared\Application\Files\FileSensitivity;
use App\Shared\Application\Files\FileUpload;
use App\Shared\Application\Files\StoredFile;
use App\Shared\Application\Files\StoredFileNotFound;
use App\Shared\Application\Files\StoreFile;
use App\Shared\Application\Idempotency\IdempotencyKey;
use App\Shared\Application\Idempotency\IdempotencyRequest;
use App\Shared\Application\Idempotency\IdempotentExecutor;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Payments to suppliers (FR-FIN-013, FR-FIN-018). A payment is against one payable, in full or in part, never more than what the payable still owes
 * (counting payments that wait for approval). Above the amount the owner sets in the approval policy `finance.supplier-payment`, a different person
 * approves it first, and the person who recorded it takes the approval to release it; with no policy for the amount it is paid when recorded. A paid
 * payment never changes. Proofs of payment (a transfer slip, a receipt) are added to it afterwards.
 */
final readonly class SupplierPaymentService
{
    public const SUBJECT = 'finance.supplier-payment';

    public const METHODS = ['transfer', 'cash', 'giro', 'other'];

    public const PROOF_PURPOSE = 'finance.payment-proof';

    public const MAX_PROOFS = 5;

    public function __construct(
        private PayableStore $store,
        private FinanceAccess $access,
        private ApprovalGate $approvals,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private IdempotentExecutor $executor,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private StoreFile $storeFile,
        private DownloadFile $downloadFile,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $this->access->requireView($property, $actorId);

        if ($status !== null && $status !== '' && ! in_array($status, ['pending_approval', 'paid', 'rejected', 'cancelled', 'reversal'], true)) {
            throw Refusal::invalid('Choose a status from the list.', ['status']);
        }

        $rows = $this->store->payments($property, $status === '' ? null : $status, 200);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'created_by'))));

        return [
            'payments' => array_map(fn (array $p): array => $this->head($p, $names, strtolower($actorId)), $rows), 'methods' => self::METHODS,
            'may' => ['record' => $this->access->may($property, $actorId, FinanceAccess::PAYMENT_RECORD)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requireView($property, $actorId);
        $p = $this->store->payment($property, strtolower($id)) ?? throw Refusal::notFound('Payment not found.');
        $names = $this->staff->namesOf($property, [$p['created_by']]);
        $approval = $p['approval_id'] === null ? null : $this->approvals->find($property, $p['approval_id']);
        $mine = $p['created_by'] === strtolower($actorId);

        return [
            ...$this->head($p, $names, strtolower($actorId)), 'note' => $p['note'], 'decision_note' => $p['decision_note'], 'business_date' => substr((string) $p['business_date'], 0, 10), 'payable_id' => $p['payable_id'], 'max_proofs' => self::MAX_PROOFS,
            'approval' => $approval === null ? null : ['id' => $approval->id, 'status' => $approval->status, 'consumed' => $approval->consumed, 'steps' => $approval->steps, 'decisions' => $approval->decisions],
            'proofs' => array_map(static fn (array $pr): array => ['id' => $pr['id'], 'name' => $pr['display_name']], $p['proofs']),
            'may_proof' => $this->access->may($property, $actorId, FinanceAccess::PAYMENT_RECORD) && in_array($p['status'], ['pending_approval', 'paid'], true),
            'may_release' => $mine && $p['status'] === 'pending_approval', 'may_cancel' => $mine && $p['status'] === 'pending_approval',
            'may_reverse' => $p['status'] === 'paid' && ($p['reversal_number'] ?? null) === null && ! $mine && $this->access->may($property, $actorId, FinanceAccess::PAYMENT_REVERSE), 'reason' => $p['status'] === 'reversal' ? $p['note'] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function pay(PropertyId $property, string $actorId, string $payableId, int $amountMinor, string $method, ?string $paidOn, ?string $reference, ?string $note, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PAYMENT_RECORD, 'This person may not record payments to suppliers.');

        if (! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Choose how it was paid.', ['method']);
        }

        if ($amountMinor < 1 || $amountMinor > 9_000_000_000_000) {
            throw Refusal::invalid('Give an amount above zero.', ['amount_minor']);
        }

        $today = $this->businessDate->current($property)->toString();
        $paidOn = $paidOn === null || $paidOn === '' ? $today : $paidOn;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) !== 1 || ! checkdate((int) substr($paidOn, 5, 2), (int) substr($paidOn, 8, 2), (int) substr($paidOn, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', ['paid_on']);
        }

        if ($paidOn > $today) {
            throw Refusal::invalid('A payment cannot be dated in the future.', ['paid_on']);
        }

        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (($reference !== null && mb_strlen($reference) > 60) || ($note !== null && mb_strlen($note) > 200)) {
            throw Refusal::invalid('The reference is at most 60 characters and the note at most 200.', ['reference']);
        }

        if (in_array($method, ['transfer', 'giro'], true) && $reference === null) {
            throw Refusal::invalid('A transfer or a giro needs its reference.', ['reference']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $payableId, $amountMinor, $method, $paidOn, $reference, $note, $today): void {
            $this->store->lockPayable($property, strtolower($payableId));
            $payable = $this->store->payable($property, strtolower($payableId)) ?? throw Refusal::notFound('Payable not found.');
            $left = (int) $payable['amount_minor'] - (int) $payable['paid_minor'] - (int) $payable['pending_minor'] - (int) $payable['credit_minor'];

            if ($amountMinor > $left) {
                throw Refusal::stateConflict('The payment is more than the payable still owes ('.$left.', counting payments that wait for approval).');
            }

            $number = $this->numbers->next($property, 'PAY');
            $required = $this->approvals->requirementFor($property, self::SUBJECT, $amountMinor)->required;
            $row = ['id' => $id, 'number' => $number, 'payable_id' => $payable['id'], 'supplier_id' => $payable['supplier_id'], 'amount_minor' => $amountMinor, 'method' => $method, 'paid_on' => $paidOn, 'reference' => $reference, 'note' => $note,
                'status' => $required ? 'pending_approval' : 'paid', 'approval_id' => null, 'created_by' => $actor, 'business_date' => $today];

            if ($required) {
                $row['approval_id'] = $this->approvals->request(new ApprovalRequestInput(
                    $property, self::SUBJECT, $id, $actor, 'Payment '.$number.' to '.$payable['supplier_name'], $this->payload($row, $payable), ['payable' => $payable['source_number'], 'supplier' => $payable['supplier_name']], $amountMinor, $payable['currency'],
                ), IdempotencyKey::fromString('ap-payment-'.$id))->id;
            }

            if (! $this->store->addPayment($property, $row, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('A payment with this number already exists. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_payment.recorded', 'supplier_payment', $id, null, ['number' => $number, 'payable' => $payable['source_number'], 'amount_minor' => $amountMinor, 'method' => $method, 'status' => $row['status']], null, $row['approval_id']));

            if (! $required) {
                $this->published($property, $actor, $row, $payable);
            }
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $once = $this->executor->execute(
                new IdempotencyRequest($property, $key, 'finance.supplier-payment.record', ['payable' => strtolower($payableId), 'amount' => $amountMinor, 'method' => $method, 'paid_on' => $paidOn, 'reference' => $reference], $actor),
                function () use ($operation, $id): array {
                    $operation();

                    return ['id' => $id];
                },
            );
            $id = (string) $once->payload['id'];
        }

        return $this->show($property, $actorId, $id);
    }

    /** The person who recorded a payment takes the decision of the approvers: approved, it is paid; rejected, it is closed with the reason. @return array<string, mixed> */
    public function release(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PAYMENT_RECORD, 'This person may not record payments to suppliers.');
        $p = $this->store->payment($property, strtolower($id)) ?? throw Refusal::notFound('Payment not found.');
        $actor = strtolower($actorId);

        if ($p['created_by'] !== $actor) {
            throw Refusal::forbidden('Only the person who recorded a payment may release it.');
        }

        if ($p['status'] !== 'pending_approval') {
            throw Refusal::stateConflict('This payment is not waiting for approval.');
        }

        $view = $this->approvals->find($property, (string) $p['approval_id']) ?? throw Refusal::notFound('Approval not found.');
        $payable = $this->store->payable($property, $p['payable_id']) ?? throw Refusal::notFound('Payable not found.');

        if (in_array($view->status, ['rejected', 'cancelled'], true)) {
            $note = null;

            foreach ($view->decisions as $d) {
                $note = $d['reason'] ?? $note;
            }

            $this->transactions->run(function () use ($property, $actor, $p, $note): void {
                if (! $this->store->updatePayment($property, $p['id'], (int) $p['lock_version'], ['status' => 'rejected', 'decision_note' => $note === null ? null : mb_substr($note, 0, 200)], $this->clock->nowUtc())) {
                    throw Refusal::stateConflict('This payment changed meanwhile.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_payment.rejected', 'supplier_payment', $p['id'], ['status' => 'pending_approval'], ['status' => 'rejected', 'number' => $p['number']], $note, $p['approval_id']));
            });
        } elseif ($view->isApproved() && ! $view->consumed) {
            $this->transactions->run(function () use ($property, $actor, $p, $payable): void {
                $this->approvals->consume($property, (string) $p['approval_id'], self::SUBJECT, $p['id'], $this->payload($p, $payable), $actor);

                if (! $this->store->updatePayment($property, $p['id'], (int) $p['lock_version'], ['status' => 'paid'], $this->clock->nowUtc())) {
                    throw Refusal::stateConflict('This payment changed meanwhile.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_payment.released', 'supplier_payment', $p['id'], ['status' => 'pending_approval'], ['status' => 'paid', 'number' => $p['number']], null, $p['approval_id']));
                $this->published($property, $actor, $p, $payable);
            });
        }

        return $this->show($property, $actorId, $p['id']);
    }

    /** @return array<string, mixed> */
    public function cancel(PropertyId $property, string $actorId, string $id, string $reason): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PAYMENT_RECORD, 'This person may not record payments to suppliers.');
        $p = $this->store->payment($property, strtolower($id)) ?? throw Refusal::notFound('Payment not found.');
        $actor = strtolower($actorId);

        if ($p['created_by'] !== $actor) {
            throw Refusal::forbidden('Only the person who recorded a payment may cancel it.');
        }

        if ($p['status'] !== 'pending_approval') {
            throw Refusal::stateConflict('Only a payment that waits for approval can be cancelled.');
        }

        if (trim($reason) === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('A reason of at most 200 characters is required.', ['reason']);
        }

        $this->transactions->run(function () use ($property, $actor, $p, $reason): void {
            if (! $this->store->updatePayment($property, $p['id'], (int) $p['lock_version'], ['status' => 'cancelled', 'decision_note' => trim($reason)], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This payment changed meanwhile.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_payment.cancelled', 'supplier_payment', $p['id'], ['status' => 'pending_approval'], ['status' => 'cancelled', 'number' => $p['number']], trim($reason), $p['approval_id']));
        });

        return $this->show($property, $actorId, $p['id']);
    }

    /**
     * Takes a paid payment back, in full, when it was recorded in error or the money came back. The payment is never changed: a reversal is a new payment row of
     * status `reversal` for the same amount that points at it, dated today, and what the payable has paid is the paid payments less their reversals. Someone other
     * than the person who recorded the payment reverses it, with the reason; a payment is reversed once.
     *
     * @return array<string, mixed> the reversal
     */
    public function reverse(PropertyId $property, string $actorId, string $paymentId, string $reason): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PAYMENT_REVERSE, 'This person may not reverse payments.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why the payment is reversed, in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $paymentId, $reason): void {
            $first = $this->store->payment($property, strtolower($paymentId)) ?? throw Refusal::notFound('Payment not found.');
            $this->store->lockPayable($property, $first['payable_id']);
            $p = $this->store->payment($property, $first['id']) ?? throw Refusal::notFound('Payment not found.');

            if ($p['status'] !== 'paid') {
                throw Refusal::stateConflict('Only a payment that was paid can be reversed.');
            }

            if (($p['reversal_number'] ?? null) !== null) {
                throw Refusal::stateConflict('This payment was reversed already.');
            }

            if ($p['created_by'] === $actor) {
                throw Refusal::forbidden('A payment is reversed by someone other than the person who recorded it.');
            }

            $today = $this->businessDate->current($property)->toString();
            $number = $this->numbers->next($property, 'PAY');
            $row = ['id' => $id, 'number' => $number, 'payable_id' => $p['payable_id'], 'reverses_id' => $p['id'], 'supplier_id' => $p['supplier_id'], 'amount_minor' => (int) $p['amount_minor'], 'method' => $p['method'], 'paid_on' => $today, 'reference' => null, 'note' => $reason,
                'status' => 'reversal', 'approval_id' => null, 'created_by' => $actor, 'business_date' => $today];

            if (! $this->store->addPayment($property, $row, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This payment was reversed already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_payment.reversed', 'supplier_payment', $p['id'], ['status' => 'paid', 'amount_minor' => (int) $p['amount_minor']], ['reversal' => $number, 'payable' => $p['source_number'], 'amount_minor' => (int) $p['amount_minor'], 'method' => $p['method']], $reason));
            $this->outbox->publish(new OutboxEvent($property, 'finance.supplier.payment_reversed', $id, 1, ['reversal_id' => $id, 'number' => $number, 'payment_id' => $p['id'], 'payment_number' => $p['number'], 'payable_id' => $p['payable_id'], 'supplier_id' => $p['supplier_id'], 'amount_minor' => (int) $p['amount_minor'], 'currency' => $p['currency'], 'business_date' => $today, 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** Adds the proof of payment (a transfer slip, a receipt) to a payment. @return array<string, mixed> */
    public function addProof(PropertyId $property, string $actorId, string $paymentId, string $contents, ?string $name): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PAYMENT_RECORD, 'This person may not add proofs to payments.');
        $p = $this->store->payment($property, strtolower($paymentId)) ?? throw Refusal::notFound('Payment not found.');

        if (! in_array($p['status'], ['pending_approval', 'paid'], true)) {
            throw Refusal::stateConflict('A proof can be added to a payment that is paid or waits for approval.');
        }

        if (count($p['proofs']) >= self::MAX_PROOFS) {
            throw Refusal::stateConflict('A payment keeps at most '.self::MAX_PROOFS.' proofs.');
        }

        $actor = strtolower($actorId);

        try {
            $file = $this->storeFile->execute(new FileUpload($property, $actor, self::PROOF_PURPOSE, 'supplier-payment', $p['id'], $contents, new FilePolicy(['application/pdf', 'image/jpeg', 'image/png'], 5_242_880, FileSensitivity::Sensitive, false), $name));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), ['proof']);
        }

        $this->transactions->run(function () use ($property, $actor, $p, $file, $name): void {
            $this->store->addProof($property, ['id' => $this->ids->next(), 'payment_id' => $p['id'], 'file_id' => $file->id, 'display_name' => $name === null ? null : mb_substr($name, 0, 120), 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'supplier_payment.proof_added', 'supplier_payment', $p['id'], null, ['number' => $p['number']]));
        });

        return $this->show($property, $actorId, $p['id']);
    }

    public function proof(PropertyId $property, string $actorId, string $paymentId, string $proofId): FileContent
    {
        $this->access->requireView($property, $actorId);
        $p = $this->store->payment($property, strtolower($paymentId)) ?? throw Refusal::notFound('Payment not found.');
        $fileId = null;

        foreach ($p['proofs'] as $pr) {
            if ($pr['id'] === strtolower($proofId)) {
                $fileId = $pr['file_id'];
            }
        }

        $fileId ?? throw Refusal::notFound('Proof not found.');
        $policy = new class($this->access, $property) implements FileAccessPolicy
        {
            public function __construct(private FinanceAccess $access, private PropertyId $property) {}

            public function allows(string $actorId, StoredFile $file): bool
            {
                return $this->access->mayView($this->property, $actorId);
            }
        };

        try {
            return $this->downloadFile->execute($property, $fileId, strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The proof is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not see accounts payable.');
        }
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $payable
     */
    private function published(PropertyId $property, string $actor, array $payment, array $payable): void
    {
        $this->outbox->publish(new OutboxEvent($property, 'finance.supplier.paid', $payment['id'], 1, [
            'payment_id' => $payment['id'], 'number' => $payment['number'], 'payable_id' => $payable['id'], 'supplier_id' => $payable['supplier_id'], 'amount_minor' => (int) $payment['amount_minor'], 'currency' => $payable['currency'],
            'paid_on' => substr((string) $payment['paid_on'], 0, 10), 'business_date' => substr((string) $payment['business_date'], 0, 10), 'actor_id' => $actor,
        ]));
    }

    /**
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $payable
     * @return array<string, mixed> what the approvers see and the release must match
     */
    private function payload(array $payment, array $payable): array
    {
        return [
            'number' => $payment['number'], 'payable' => $payable['source_number'], 'supplier' => $payable['supplier_name'], 'document' => $payable['document_number'], 'amount_minor' => (int) $payment['amount_minor'],
            'method' => $payment['method'], 'paid_on' => substr((string) $payment['paid_on'], 0, 10), 'reference' => $payment['reference'],
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function head(array $p, array $names, string $actor): array
    {
        return [
            'id' => $p['id'], 'number' => $p['number'], 'status' => $p['status'], 'supplier_name' => $p['supplier_name'], 'supplier_code' => $p['supplier_code'], 'payable_number' => $p['source_number'], 'document_number' => $p['document_number'],
            'amount_minor' => (int) $p['amount_minor'], 'currency' => $p['currency'], 'method' => $p['method'], 'paid_on' => substr((string) $p['paid_on'], 0, 10), 'reference' => $p['reference'], 'created_by_name' => $names[$p['created_by']] ?? null,
            'lock_version' => (int) $p['lock_version'], 'mine' => $p['created_by'] === $actor, 'reversal_number' => $p['reversal_number'] ?? null, 'reverses_number' => $p['reverses_number'] ?? null,
        ];
    }
}
