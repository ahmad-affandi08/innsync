<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\FnbSales\Application\FnbTime;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Documents\DocumentNumbers;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\StaffDirectory;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;

/**
 * QRIS and card receipts against what the provider settled to the bank (FR-FIN-004, FR-FIN-037). Finance records each settlement the provider or the acquirer made: the stretch of
 * business days it covers, the gross it says it took, the fee it kept and the net that reached the bank, with the bank reference. The books hold what was received by that method in
 * those days (every day must be booked, so the figure is final); the settlement is compared in two ways. The gross against the books: a difference means a payment one side does not have.
 * The net against the gross less the fee: a difference means the bank did not receive what the provider reported. A difference of either kind is raised as an exception of the day,
 * to be reconciled by someone else. The fee is shown as a share of the gross and flagged when it is above what is usual for the method. A day is covered by one settlement of a provider
 * only, so nothing is counted twice. Nothing is edited; a wrong figure is answered by a correction.
 */
final readonly class SettlementService
{
    public const METHODS = ['qris', 'card'];

    /** Fee shares above these (basis points of the gross) are shown as high: QRIS is usually 0.7%, a card 1.5% to 2.5%. */
    public const HIGH_FEE_BP = ['qris' => 100, 'card' => 300];

    public const MAX_DAYS = 31;

    private const MAX_MINOR = 9_000_000_000_000;

    private const PENDING_DAYS = 45;

    public function __construct(
        private SettlementStore $store,
        private ExceptionStore $exceptions,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currencies,
        private DocumentNumbers $numbers,
        private StaffDirectory $staff,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->requireRevenueView($property, $actorId);
        $today = $this->businessDate->current($property)->toString();
        $since = (new DateTimeImmutable($today))->modify('-'.self::PENDING_DAYS.' days')->format('Y-m-d');
        $rows = $this->store->settlements($property, 100);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($rows, 'recorded_by'))));

        return [
            'currency' => $this->currencies->currencyOf($property), 'today' => $today, 'methods' => self::METHODS, 'high_fee_bp' => self::HIGH_FEE_BP,
            'settlements' => array_map(static fn (array $s): array => [
                'id' => $s['id'], 'number' => $s['number'], 'method' => $s['method'], 'provider' => $s['provider'], 'covers_from' => substr((string) $s['covers_from'], 0, 10), 'covers_to' => substr((string) $s['covers_to'], 0, 10),
                'settled_on' => substr((string) $s['settled_on'], 0, 10), 'gross_minor' => (int) $s['gross_minor'], 'fee_minor' => (int) $s['fee_minor'], 'net_minor' => (int) $s['net_minor'], 'bank_reference' => $s['bank_reference'],
                'system_minor' => (int) $s['system_minor'], 'gross_diff_minor' => (int) $s['gross_diff_minor'], 'net_diff_minor' => (int) $s['net_diff_minor'], 'fee_bp' => (int) $s['fee_bp'], 'fee_high' => (int) $s['fee_bp'] > (self::HIGH_FEE_BP[$s['method']] ?? PHP_INT_MAX),
                'matched' => (int) $s['gross_diff_minor'] === 0 && (int) $s['net_diff_minor'] === 0, 'exception_id' => $s['exception_id'], 'note' => $s['note'], 'by' => $names[$s['recorded_by']] ?? null, 'at' => FnbTime::utc($s['created_at']),
            ], $rows),
            'pending' => $this->pending($property, $since),
            'may' => ['record' => $this->access->may($property, $actorId, FinanceAccess::RECONCILE)],
        ];
    }

    /** @return array<string, mixed> the overview after the settlement */
    public function record(PropertyId $property, string $actorId, string $method, string $provider, string $from, string $to, string $settledOn, int $grossMinor, int $feeMinor, int $netMinor, string $bankReference, ?string $note): array
    {
        $this->access->require($property, $actorId, FinanceAccess::RECONCILE, 'This person may not record settlements.');
        $provider = trim($provider);
        $bankReference = trim($bankReference);
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! in_array($method, self::METHODS, true)) {
            throw Refusal::invalid('Choose QRIS or card.', ['method']);
        }

        if ($provider === '' || mb_strlen($provider) > 80) {
            throw Refusal::invalid('Name the provider or the acquirer, in at most 80 characters.', ['provider']);
        }

        if (preg_match('/^[A-Za-z0-9 ._\/#:-]{3,60}$/D', $bankReference) !== 1) {
            throw Refusal::invalid('Give the reference of the bank credit: 3 to 60 letters, digits and simple punctuation.', ['bank_reference']);
        }

        if ($note !== null && mb_strlen($note) > 200) {
            throw Refusal::invalid('The note is at most 200 characters.', ['note']);
        }

        $a = $this->date($from, 'covers_from');
        $b = $this->date($to, 'covers_to');
        $settled = $this->date($settledOn, 'settled_on');
        $today = $this->businessDate->current($property)->toString();

        if ($b < $a || (new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->days >= self::MAX_DAYS) {
            throw Refusal::invalid('A settlement covers from 1 to '.self::MAX_DAYS.' days, the last not before the first.', ['covers_to']);
        }

        if ($b > $today || $settled < $b || $settled > $today) {
            throw Refusal::invalid('The settlement is dated from the last day it covers up to today, and covers no day that has not happened.', ['settled_on']);
        }

        foreach ([$grossMinor, $feeMinor, $netMinor] as $amount) {
            if ($amount < 0 || $amount > self::MAX_MINOR) {
                throw Refusal::invalid('Give the amounts as whole amounts, none below zero.', ['gross_minor']);
            }
        }

        if ($grossMinor < 1 || $feeMinor > $grossMinor) {
            throw Refusal::invalid('Give a gross above zero, and a fee that is not more than the gross.', ['fee_minor']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $method, $provider, $a, $b, $settled, $grossMinor, $feeMinor, $netMinor, $bankReference, $note): void {
            $missing = $this->store->unbookedDays($property, $a, $b);

            if ($missing !== []) {
                throw Refusal::stateConflict('The day '.$missing[0].' is not booked yet, so what it received is not final. Record the settlement after the night audit of its last day.');
            }

            if ($this->store->overlapping($property, $method, $provider, $a, $b) !== []) {
                throw Refusal::stateConflict('A settlement of this provider already covers one of these days.');
            }

            $system = $this->store->receivedNet($property, $method, $a, $b);
            $grossDiff = $grossMinor - $system;
            $netDiff = $netMinor - ($grossMinor - $feeMinor);
            $feeBp = intdiv($feeMinor * 10_000 + intdiv($grossMinor, 2), $grossMinor);
            $number = $this->numbers->next($property, 'STL');
            $exceptionId = null;

            if ($grossDiff !== 0 || $netDiff !== 0) {
                $exceptionId = $this->ids->next();
                $amount = $grossDiff !== 0 ? abs($grossDiff) : abs($netDiff);
                $exceptionNumber = $this->numbers->next($property, 'EXC');
                $description = mb_substr("Settlement {$number} ({$provider}, {$method}) {$a} to {$b}: provider gross ".$grossMinor.', books '.$system.', difference '.$grossDiff.'; net '.$netMinor.' against gross less fee '.($grossMinor - $feeMinor).', difference '.$netDiff.'.', 0, 300);

                if (! $this->exceptions->add($property, ['id' => $exceptionId, 'number' => $exceptionNumber, 'kind' => 'settlement_discrepancy', 'business_date' => $b, 'amount_minor' => $amount, 'method' => $method, 'reference' => $bankReference, 'folio_ref' => null, 'description' => $description, 'created_by' => $actor], $this->clock->nowUtc())) {
                    throw Refusal::stateConflict('An exception with this number already exists. Try again.');
                }

                $this->audit->record(new AuditEntry($property->toString(), $actor, 'fin_exception.raised', 'fin_exception', $exceptionId, null, ['number' => $exceptionNumber, 'kind' => 'settlement_discrepancy', 'business_date' => $b, 'amount_minor' => $amount, 'method' => $method, 'reference' => $bankReference], $description));
                $this->outbox->publish(new OutboxEvent($property, 'finance.exception.raised', $exceptionId, 1, ['exception_id' => $exceptionId, 'number' => $exceptionNumber, 'kind' => 'settlement_discrepancy', 'business_date' => $b, 'amount_minor' => $amount, 'actor_id' => $actor]));
            }

            $row = [
                'id' => $id, 'number' => $number, 'method' => $method, 'provider' => $provider, 'covers_from' => $a, 'covers_to' => $b, 'settled_on' => $settled, 'gross_minor' => $grossMinor, 'fee_minor' => $feeMinor, 'net_minor' => $netMinor,
                'bank_reference' => $bankReference, 'system_minor' => $system, 'gross_diff_minor' => $grossDiff, 'net_diff_minor' => $netDiff, 'fee_bp' => min(65_535, $feeBp), 'exception_id' => $exceptionId, 'note' => $note, 'recorded_by' => $actor,
            ];

            if (! $this->store->add($property, $row, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This bank reference is recorded already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fin_settlement.recorded', 'fin_settlement', $id, null, [
                'number' => $number, 'method' => $method, 'provider' => $provider, 'covers' => "{$a}..{$b}", 'gross_minor' => $grossMinor, 'fee_minor' => $feeMinor, 'net_minor' => $netMinor, 'system_minor' => $system, 'gross_diff_minor' => $grossDiff, 'net_diff_minor' => $netDiff, 'bank_reference' => $bankReference,
            ], $note));
            $this->outbox->publish(new OutboxEvent($property, 'finance.settlement.recorded', $id, 1, ['settlement_id' => $id, 'number' => $number, 'method' => $method, 'gross_minor' => $grossMinor, 'fee_minor' => $feeMinor, 'net_minor' => $netMinor, 'matched' => $exceptionId === null, 'actor_id' => $actor]));
        });

        return $this->overview($property, $actorId);
    }

    /**
     * The days of the last weeks that received by QRIS or card and that no settlement covers yet.
     *
     * @return list<array{business_date: string, method: string, net_minor: int}>
     */
    private function pending(PropertyId $property, string $since): array
    {
        $covered = $this->store->coveredSince($property, $since);
        $out = [];

        foreach ($this->store->receiptsByDay($property, $since) as $r) {
            $done = false;

            foreach ($covered as $c) {
                if ($c['method'] === $r['method'] && $r['business_date'] >= $c['covers_from'] && $r['business_date'] <= $c['covers_to']) {
                    $done = true;

                    break;
                }
            }

            if (! $done && $r['net_minor'] !== 0) {
                $out[] = $r;
            }
        }

        return $out;
    }

    private function date(string $value, string $field): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw Refusal::invalid('Give a date as year-month-day.', [$field]);
        }

        return $value;
    }
}
