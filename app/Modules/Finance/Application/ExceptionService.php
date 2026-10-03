<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
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
use DateTimeImmutable;
use DateTimeZone;

/**
 * Exceptions of the daily reconciliation (FR-FIN-019, FR-FIN-037): a guest refund, a chargeback, a difference between what a provider settled and what the folios
 * say, a payment whose status is not known. Each is kept as an exception, dated the business day it belongs to, until someone other than the person who raised it
 * reconciles it: matched to the provider or bank record (with the reference), adjusted by a correction (named by its number), or waived (with the reason). The
 * transaction the exception belongs to is never edited. A day cannot be verified while an exception of that day is open.
 */
final readonly class ExceptionService
{
    public const KINDS = ['refund', 'chargeback', 'settlement_discrepancy', 'unknown_payment', 'late_sale'];

    /** The kinds a person raises; a late sale is raised by finance itself when a bill arrives after its day was booked. */
    public const MANUAL_KINDS = ['refund', 'chargeback', 'settlement_discrepancy', 'unknown_payment'];

    public const RESOLUTIONS = ['matched', 'adjusted', 'waived'];

    public const METHODS = ['cash', 'qris', 'card', 'bank_transfer', 'online'];

    private const MAX_MINOR = 9_000_000_000_000;

    public function __construct(
        private ExceptionStore $store,
        private CorrectionStore $corrections,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private IdempotentExecutor $executor,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $this->access->requireCorrectionView($property, $actorId);

        if ($status !== null && $status !== '' && ! in_array($status, ['open', ...self::RESOLUTIONS], true)) {
            throw Refusal::invalid('Choose open, matched, adjusted or waived.', ['status']);
        }

        $rows = $this->store->exceptions($property, $status === '' ? null : $status);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([...array_column($rows, 'created_by'), ...array_column($rows, 'resolved_by')]))));
        $actor = strtolower($actorId);
        $may = $this->access->may($property, $actorId, FinanceAccess::RECONCILE);

        return [
            'exceptions' => array_map(fn (array $e): array => $this->shape($e, $names, $actor, $may), $rows), 'open_count' => $this->store->openCount($property),
            'kinds' => self::MANUAL_KINDS, 'resolutions' => self::RESOLUTIONS, 'methods' => self::METHODS, 'today' => $this->businessDate->current($property)->toString(), 'may' => ['reconcile' => $may],
        ];
    }

    /** @return array<string, mixed> */
    public function raise(PropertyId $property, string $actorId, string $kind, ?string $businessDate, int $amountMinor, ?string $method, ?string $reference, ?string $folioRef, string $description, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECONCILE, 'This person may not raise reconciliation exceptions.');
        $description = trim($description);
        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);
        $folioRef = $folioRef === null || trim($folioRef) === '' ? null : trim($folioRef);
        $today = $this->businessDate->current($property)->toString();
        $businessDate = $businessDate === null || $businessDate === '' ? $today : $this->date($businessDate, 'business_date');

        if (! in_array($kind, self::MANUAL_KINDS, true)) {
            throw Refusal::invalid('Choose the kind of exception from the list.', ['kind']);
        }

        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give an amount above zero.', ['amount_minor']);
        }

        if ($method !== null && $method !== '' && ! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Choose a payment method from the list.', ['method']);
        }

        if ($reference !== null && preg_match('/^[A-Za-z0-9 ._\/#:-]{1,80}$/D', $reference) !== 1) {
            throw Refusal::invalid('The reference is up to 80 letters, digits and simple punctuation.', ['reference']);
        }

        if (($folioRef !== null && mb_strlen($folioRef) > 40) || $description === '' || mb_strlen($description) > 300) {
            throw Refusal::invalid('Describe the exception in at most 300 characters; the folio or reservation in at most 40.', ['description']);
        }

        if ($businessDate > $today) {
            throw Refusal::invalid('An exception cannot be dated in the future.', ['business_date']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $kind, $businessDate, $amountMinor, $method, $reference, $folioRef, $description): void {
            $number = $this->numbers->next($property, 'EXC');

            if (! $this->store->add($property, ['id' => $id, 'number' => $number, 'kind' => $kind, 'business_date' => $businessDate, 'amount_minor' => $amountMinor, 'method' => $method === '' ? null : $method, 'reference' => $reference, 'folio_ref' => $folioRef, 'description' => $description, 'created_by' => $actor], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('An exception with this number already exists. Try again.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fin_exception.raised', 'fin_exception', $id, null, ['number' => $number, 'kind' => $kind, 'business_date' => $businessDate, 'amount_minor' => $amountMinor, 'method' => $method, 'reference' => $reference], $description));
            $this->outbox->publish(new OutboxEvent($property, 'finance.exception.raised', $id, 1, ['exception_id' => $id, 'number' => $number, 'kind' => $kind, 'business_date' => $businessDate, 'amount_minor' => $amountMinor, 'actor_id' => $actor]));
        };

        if ($key === null) {
            $this->transactions->run($operation);
        } else {
            $this->executor->execute(new IdempotencyRequest($property, $key, 'finance.exception.raise', ['kind' => $kind, 'date' => $businessDate, 'amount' => $amountMinor, 'reference' => $reference, 'description' => $description], $actor), function () use ($operation, $id): array {
                $operation();

                return ['id' => $id];
            });
        }

        $names = $this->staff->namesOf($property, [$actor]);

        return $this->shape($this->store->exception($property, $id) ?? throw Refusal::notFound('Exception not found.'), $names, $actor, true);
    }

    /** @return array<string, mixed> */
    public function reconcile(PropertyId $property, string $actorId, string $id, string $status, string $resolution, ?string $correctionNumber, int $expectedLockVersion): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECONCILE, 'This person may not reconcile exceptions.');
        $resolution = trim($resolution);

        if (! in_array($status, self::RESOLUTIONS, true)) {
            throw Refusal::invalid('Choose matched, adjusted or waived.', ['status']);
        }

        if ($resolution === '' || mb_strlen($resolution) > 300) {
            throw Refusal::invalid('Say how it was reconciled, in at most 300 characters.', ['resolution']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $status, $resolution, $correctionNumber, $expectedLockVersion): void {
            $this->store->lock($property, strtolower($id));
            $e = $this->store->exception($property, strtolower($id)) ?? throw Refusal::notFound('Exception not found.');

            if ($e['status'] !== 'open') {
                throw Refusal::stateConflict('This exception was reconciled already.');
            }

            if ($e['created_by'] === $actor) {
                throw Refusal::forbidden('An exception is reconciled by someone other than the person who raised it.');
            }

            $correctionId = null;

            if ($status === 'adjusted') {
                $correction = $correctionNumber === null || trim($correctionNumber) === '' ? null : $this->corrections->byNumber($property, strtoupper(trim($correctionNumber)));

                if ($correction === null || $correction['status'] === 'rejected') {
                    throw Refusal::invalid('Give the number of the correction that adjusted it; a rejected correction does not count.', ['correction_number']);
                }

                $correctionId = $correction['id'];
            }

            if (! $this->store->resolve($property, $e['id'], $expectedLockVersion, $status, $resolution, $correctionId, $actor, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This exception changed after you opened it. Reload it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fin_exception.reconciled', 'fin_exception', $e['id'], ['status' => 'open'], ['status' => $status, 'number' => $e['number'], 'kind' => $e['kind'], 'amount_minor' => (int) $e['amount_minor'], 'correction' => $correctionNumber], $resolution));
            $this->outbox->publish(new OutboxEvent($property, 'finance.exception.reconciled', $e['id'], 1, ['exception_id' => $e['id'], 'number' => $e['number'], 'status' => $status, 'business_date' => substr((string) $e['business_date'], 0, 10), 'actor_id' => $actor]));
        });

        $names = $this->staff->namesOf($property, [$actor]);

        return $this->shape($this->store->exception($property, strtolower($id)) ?? throw Refusal::notFound('Exception not found.'), $names, $actor, true);
    }

    /** @param array<string, mixed> $e @param array<string, string> $names @return array<string, mixed> */
    private function shape(array $e, array $names, string $actor, bool $may): array
    {
        return [
            'id' => $e['id'], 'number' => $e['number'], 'kind' => $e['kind'], 'status' => $e['status'], 'business_date' => substr((string) $e['business_date'], 0, 10), 'amount_minor' => (int) $e['amount_minor'], 'method' => $e['method'], 'reference' => $e['reference'],
            'folio_ref' => $e['folio_ref'], 'description' => $e['description'], 'resolution' => $e['resolution'], 'correction_number' => $e['correction_number'] ?? null, 'created_by' => $names[$e['created_by']] ?? null,
            'resolved_by' => $e['resolved_by'] === null ? null : ($names[$e['resolved_by']] ?? null), 'resolved_at' => $e['resolved_at'] === null ? null : (new DateTimeImmutable((string) $e['resolved_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
            'lock_version' => (int) $e['lock_version'], 'may_reconcile' => $may && $e['status'] === 'open' && $e['created_by'] !== $actor,
        ];
    }

    private function date(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $value;
    }
}
