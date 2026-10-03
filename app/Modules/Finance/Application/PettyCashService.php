<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
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
use DateTimeImmutable;
use DateTimeZone;

/**
 * Petty cash on the imprest system (FR-FIN-015). A fund is a fixed amount handed to a custodian. The custodian records a voucher for each expense against
 * an expense account, with its receipt as a proof or the reason there is none, and never beyond what the fund holds or the limit of one voucher. To account
 * for them the custodian submits a settlement with the cash counted; until another person decides it the fund takes no new voucher. Approved, the difference
 * found at the count is written to the ledger and the fund is replenished back to its amount (the amount less the cash counted); rejected, the vouchers wait
 * to be submitted again. Vouchers, ledger entries and decisions are never changed: a voucher is voided, with a reason, by someone other than its maker.
 */
final readonly class PettyCashService
{
    public const PROOF_PURPOSE = 'finance.petty-proof';

    public const MAX_PROOFS = 5;

    /** The limit of one voucher a new fund starts with, in minor units (IDR 1,000,000); the owner changes it per fund or clears it. */
    public const DEFAULT_MAX_VOUCHER_MINOR = 100_000_000;

    private const MAX_MINOR = 9_000_000_000_000;

    public function __construct(
        private PettyCashStore $store,
        private FinanceAccess $access,
        private BusinessDateProvider $businessDate,
        private PropertyCurrencyReader $currencies,
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
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->access->requirePettyView($property, $actorId);
        $actor = strtolower($actorId);
        $seesAll = $this->access->may($property, $actorId, FinanceAccess::PETTY_VIEW) || $this->access->may($property, $actorId, FinanceAccess::PETTY_MANAGE);
        $funds = $this->store->funds($property, $seesAll ? null : $actor);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_column($funds, 'custodian_id'))));

        return [
            'funds' => array_map(fn (array $f): array => $this->fundShape($f, $names), $funds), 'default_max_voucher_minor' => self::DEFAULT_MAX_VOUCHER_MINOR,
            'custodians' => $this->access->may($property, $actorId, FinanceAccess::PETTY_MANAGE) ? $this->staff->withPermission($property, FinanceAccess::PETTY_OPERATE) : [],
            'may' => ['manage' => $this->access->may($property, $actorId, FinanceAccess::PETTY_MANAGE)],
        ];
    }

    /** @return array<string, mixed> */
    public function show(PropertyId $property, string $actorId, string $id): array
    {
        $fund = $this->visibleFund($property, $actorId, $id);
        $actor = strtolower($actorId);
        $vouchers = $this->store->vouchers($property, $fund['id'], 200);
        $settlements = $this->store->settlements($property, $fund['id']);
        $entries = $this->store->entries($property, $fund['id'], 100);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([$fund['custodian_id'], ...array_column($vouchers, 'created_by'), ...array_column($settlements, 'submitted_by'), ...array_column($settlements, 'decided_by'), ...array_column($entries, 'created_by')]))));
        $mine = $fund['custodian_id'] === $actor && $this->access->may($property, $actorId, FinanceAccess::PETTY_OPERATE);
        $pending = $fund['pending_settlement_id'] !== null;
        $manage = $this->access->may($property, $actorId, FinanceAccess::PETTY_MANAGE);

        return [
            ...$this->fundShape($fund, $names), 'max_proofs' => self::MAX_PROOFS,
            'vouchers' => array_map(fn (array $v): array => $this->voucherShape($v, $names, $actor, $manage), $vouchers),
            'settlements' => array_map(fn (array $s): array => $this->settlementHead($s, $names), $settlements),
            'entries' => array_map(static fn (array $e): array => ['seq' => (int) $e['seq'], 'kind' => $e['kind'], 'signed_minor' => (int) $e['signed_minor'], 'voucher_number' => $e['voucher_number'], 'settlement_number' => $e['settlement_number'], 'note' => $e['note'], 'business_date' => substr((string) $e['business_date'], 0, 10), 'by' => $names[$e['created_by']] ?? null], $entries),
            'accounts' => $mine ? array_map(static fn (array $a): array => ['id' => $a['id'], 'code' => $a['code'], 'name' => $a['name']], $this->store->activeAccounts($property)) : [],
            'may_record' => $mine && (bool) $fund['is_active'] && ! $pending, 'may_submit' => $mine && (bool) $fund['is_active'] && ! $pending && (int) $fund['unsettled_count'] > 0, 'may_proof' => $mine,
            'may' => ['manage' => $manage],
        ];
    }

    /** @return array<string, mixed> */
    public function showSettlement(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requirePettyView($property, $actorId);
        $s = $this->store->settlement($property, strtolower($id)) ?? throw Refusal::notFound('Settlement not found.');
        $this->visibleFund($property, $actorId, $s['fund_id']);
        $actor = strtolower($actorId);
        $names = $this->staff->namesOf($property, array_values(array_unique(array_filter([$s['custodian_id'], $s['submitted_by'], $s['decided_by'], ...array_column($s['vouchers'], 'created_by')]))));
        $manage = $this->access->may($property, $actorId, FinanceAccess::PETTY_MANAGE);

        return [
            ...$this->settlementHead($s, $names), 'fund_id' => $s['fund_id'], 'fund_code' => $s['fund_code'], 'fund_name' => $s['fund_name'], 'imprest_minor' => (int) $s['imprest_minor'], 'currency' => $s['currency'],
            'book_minor' => (int) $s['book_minor'], 'variance_reason' => $s['variance_reason'], 'decision_note' => $s['decision_note'], 'lock_version' => (int) $s['lock_version'],
            'vouchers' => array_map(fn (array $v): array => $this->voucherShape($v, $names, $actor, false), $s['vouchers']),
            'may_decide' => $manage && $s['status'] === 'submitted' && $s['submitted_by'] !== $actor,
        ];
    }

    /** @return array<string, mixed> */
    public function createFund(PropertyId $property, string $actorId, string $code, string $name, string $custodianId, int $imprestMinor, ?int $maxVoucherMinor): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PETTY_MANAGE, 'This person may not manage petty cash funds.');
        $code = strtoupper(trim($code));
        $name = trim($name);

        if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,11}$/D', $code) !== 1) {
            throw Refusal::invalid('A code is up to 12 letters, digits, dots, dashes or underscores.', ['code']);
        }

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give a name of at most 80 characters.', ['name']);
        }

        if ($imprestMinor < 1 || $imprestMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give the amount of the fund, above zero.', ['imprest_minor']);
        }

        $this->assertLimit($maxVoucherMinor, $imprestMinor);
        $custodianId = strtolower($custodianId);
        $this->assertCustodian($property, $custodianId);
        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $this->transactions->run(function () use ($property, $actor, $id, $code, $name, $custodianId, $imprestMinor, $maxVoucherMinor): void {
            $now = $this->clock->nowUtc();

            if (! $this->store->addFund($property, ['id' => $id, 'code' => $code, 'name' => $name, 'custodian_id' => $custodianId, 'imprest_minor' => $imprestMinor, 'max_voucher_minor' => $maxVoucherMinor, 'currency' => $this->currencies->currencyOf($property)], $now)) {
                throw Refusal::stateConflict('A fund with this code already exists.');
            }

            $this->store->addEntry($property, ['id' => $this->ids->next(), 'fund_id' => $id, 'kind' => 'opening', 'signed_minor' => $imprestMinor, 'voucher_id' => null, 'settlement_id' => null, 'note' => 'Cash handed to the custodian', 'business_date' => $this->businessDate->current($property)->toString(), 'created_by' => $actor], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'petty_fund.created', 'petty_fund', $id, null, ['code' => $code, 'name' => $name, 'custodian_id' => $custodianId, 'imprest_minor' => $imprestMinor, 'max_voucher_minor' => $maxVoucherMinor]));
            $this->outbox->publish(new OutboxEvent($property, 'finance.petty.fund_created', $id, 1, ['fund_id' => $id, 'code' => $code, 'custodian_id' => $custodianId, 'imprest_minor' => $imprestMinor, 'currency' => $this->currencies->currencyOf($property), 'actor_id' => $actor]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> */
    public function updateFund(PropertyId $property, string $actorId, string $id, string $name, string $custodianId, ?int $maxVoucherMinor, bool $active, int $expectedLockVersion): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PETTY_MANAGE, 'This person may not manage petty cash funds.');
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Give a name of at most 80 characters.', ['name']);
        }

        $custodianId = strtolower($custodianId);
        $this->assertCustodian($property, $custodianId);
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $id, $name, $custodianId, $maxVoucherMinor, $active, $expectedLockVersion): void {
            $this->store->lockFund($property, strtolower($id));
            $before = $this->store->fund($property, strtolower($id)) ?? throw Refusal::notFound('Fund not found.');
            $this->assertLimit($maxVoucherMinor, (int) $before['imprest_minor']);

            if (! $active && (bool) $before['is_active'] && ($before['pending_settlement_id'] !== null || (int) $before['unsettled_count'] > 0)) {
                throw Refusal::stateConflict('Settle the vouchers of this fund before closing it.');
            }

            if (! $this->store->updateFund($property, $before['id'], $expectedLockVersion, ['name' => $name, 'custodian_id' => $custodianId, 'max_voucher_minor' => $maxVoucherMinor, 'is_active' => $active], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This fund changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'petty_fund.updated', 'petty_fund', $before['id'], ['name' => $before['name'], 'custodian_id' => $before['custodian_id'], 'max_voucher_minor' => $before['max_voucher_minor'] === null ? null : (int) $before['max_voucher_minor'], 'is_active' => (bool) $before['is_active']], ['name' => $name, 'custodian_id' => $custodianId, 'max_voucher_minor' => $maxVoucherMinor, 'is_active' => $active]));
        });

        return $this->show($property, $actorId, $id);
    }

    /** @return array<string, mixed> the fund with the new voucher in it */
    public function record(PropertyId $property, string $actorId, string $fundId, ?string $voucherDate, string $payee, string $description, string $accountId, int $amountMinor, ?string $receiptRef, ?string $noReceiptReason, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PETTY_OPERATE, 'This person may not record petty cash vouchers.');
        $payee = trim($payee);
        $description = trim($description);
        $receiptRef = $receiptRef === null || trim($receiptRef) === '' ? null : trim($receiptRef);
        $noReceiptReason = $noReceiptReason === null || trim($noReceiptReason) === '' ? null : trim($noReceiptReason);
        $today = $this->businessDate->current($property)->toString();
        $voucherDate = $voucherDate === null || $voucherDate === '' ? $today : $this->date($voucherDate, 'voucher_date');

        if ($payee === '' || mb_strlen($payee) > 120) {
            throw Refusal::invalid('Say who was paid, in at most 120 characters.', ['payee']);
        }

        if ($description === '' || mb_strlen($description) > 200) {
            throw Refusal::invalid('Describe what it was for, in at most 200 characters.', ['description']);
        }

        if ($amountMinor < 1 || $amountMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give an amount above zero.', ['amount_minor']);
        }

        if (($receiptRef !== null && mb_strlen($receiptRef) > 40) || ($noReceiptReason !== null && mb_strlen($noReceiptReason) > 200)) {
            throw Refusal::invalid('The receipt number is at most 40 characters and the reason at most 200.', ['receipt_ref']);
        }

        if ($voucherDate > $today) {
            throw Refusal::invalid('A voucher cannot be dated in the future.', ['voucher_date']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $fundId, $voucherDate, $payee, $description, $accountId, $amountMinor, $receiptRef, $noReceiptReason, $today): void {
            $this->store->lockFund($property, strtolower($fundId));
            $fund = $this->store->fund($property, strtolower($fundId)) ?? throw Refusal::notFound('Fund not found.');

            if ($fund['custodian_id'] !== $actor) {
                throw Refusal::forbidden('Only the custodian of a fund records its vouchers.');
            }

            if (! $fund['is_active']) {
                throw Refusal::stateConflict('This fund is closed.');
            }

            if ($fund['pending_settlement_id'] !== null) {
                throw Refusal::stateConflict('A settlement of this fund waits for a decision; no voucher can be added until then.');
            }

            if ($fund['max_voucher_minor'] !== null && $amountMinor > (int) $fund['max_voucher_minor']) {
                throw Refusal::invalid('One voucher of this fund is at most '.(int) $fund['max_voucher_minor'].'.', ['amount_minor']);
            }

            if ($amountMinor > (int) $fund['balance_minor']) {
                throw Refusal::stateConflict('The fund holds '.(int) $fund['balance_minor'].'; the voucher is more than that.');
            }

            $account = $this->store->account($property, strtolower($accountId));

            if ($account === null || ! $account['is_active']) {
                throw Refusal::invalid('Choose an active expense account.', ['expense_account_id']);
            }

            $number = $this->numbers->next($property, 'PCV');
            $now = $this->clock->nowUtc();

            if (! $this->store->addVoucher($property, ['id' => $id, 'fund_id' => $fund['id'], 'number' => $number, 'voucher_date' => $voucherDate, 'payee' => $payee, 'description' => $description, 'expense_account_id' => $account['id'], 'receipt_ref' => $receiptRef, 'no_receipt_reason' => $noReceiptReason, 'amount_minor' => $amountMinor, 'business_date' => $today, 'created_by' => $actor], $now)) {
                throw Refusal::stateConflict('A voucher with this number already exists. Try again.');
            }

            $this->store->addEntry($property, ['id' => $this->ids->next(), 'fund_id' => $fund['id'], 'kind' => 'expense', 'signed_minor' => -$amountMinor, 'voucher_id' => $id, 'settlement_id' => null, 'note' => mb_substr($payee.' · '.$description, 0, 200), 'business_date' => $today, 'created_by' => $actor], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'petty_voucher.recorded', 'petty_voucher', $id, null, ['number' => $number, 'fund' => $fund['code'], 'account' => $account['code'], 'amount_minor' => $amountMinor, 'payee' => $payee, 'receipt_ref' => $receiptRef, 'no_receipt_reason' => $noReceiptReason]));
            $this->outbox->publish(new OutboxEvent($property, 'finance.petty.voucher_recorded', $id, 1, ['voucher_id' => $id, 'number' => $number, 'fund_id' => $fund['id'], 'expense_account_code' => $account['code'], 'department' => $account['department'], 'category' => $account['category'], 'amount_minor' => $amountMinor, 'currency' => $fund['currency'], 'voucher_date' => $voucherDate, 'business_date' => $today, 'actor_id' => $actor]));
        };

        $this->once($property, $actor, $key, 'finance.petty.record', ['fund' => strtolower($fundId), 'amount' => $amountMinor, 'payee' => $payee, 'description' => $description, 'date' => $voucherDate], $id, $operation);

        return $this->show($property, $actorId, $fundId);
    }

    /** Adds the receipt (a photo or a scan) to a voucher. @return array<string, mixed> the fund */
    public function addProof(PropertyId $property, string $actorId, string $voucherId, string $contents, ?string $name): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PETTY_OPERATE, 'This person may not record petty cash vouchers.');
        $v = $this->store->voucher($property, strtolower($voucherId)) ?? throw Refusal::notFound('Voucher not found.');
        $fund = $this->store->fund($property, $v['fund_id']) ?? throw Refusal::notFound('Fund not found.');
        $actor = strtolower($actorId);

        if ($fund['custodian_id'] !== $actor) {
            throw Refusal::forbidden('Only the custodian of a fund adds proofs to its vouchers.');
        }

        if ($v['voided']) {
            throw Refusal::stateConflict('A voided voucher takes no proof.');
        }

        if (count($v['proofs']) >= self::MAX_PROOFS) {
            throw Refusal::stateConflict('A voucher keeps at most '.self::MAX_PROOFS.' proofs.');
        }

        try {
            $file = $this->storeFile->execute(new FileUpload($property, $actor, self::PROOF_PURPOSE, 'petty-voucher', $v['id'], $contents, new FilePolicy(['application/pdf', 'image/jpeg', 'image/png'], 5_242_880, FileSensitivity::Sensitive, false), $name));
        } catch (FileRejected $e) {
            throw Refusal::invalid($e->getMessage(), ['proof']);
        }

        $this->transactions->run(function () use ($property, $actor, $v, $file, $name): void {
            $this->store->addProof($property, ['id' => $this->ids->next(), 'voucher_id' => $v['id'], 'file_id' => $file->id, 'display_name' => $name === null ? null : mb_substr($name, 0, 120), 'created_by' => $actor], $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'petty_voucher.proof_added', 'petty_voucher', $v['id'], null, ['number' => $v['number']]));
        });

        return $this->show($property, $actorId, $v['fund_id']);
    }

    public function proof(PropertyId $property, string $actorId, string $voucherId, string $proofId): FileContent
    {
        $this->access->requirePettyView($property, $actorId);
        $v = $this->store->voucher($property, strtolower($voucherId)) ?? throw Refusal::notFound('Voucher not found.');
        $this->visibleFund($property, $actorId, $v['fund_id']);
        $fileId = null;

        foreach ($v['proofs'] as $pr) {
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
                return $this->access->mayViewPetty($this->property, $actorId);
            }
        };

        try {
            return $this->downloadFile->execute($property, $fileId, strtolower($actorId), $policy);
        } catch (StoredFileNotFound) {
            throw Refusal::notFound('The proof is no longer kept.');
        } catch (FileAccessDenied) {
            throw Refusal::forbidden('This person may not see petty cash.');
        }
    }

    /** Voids a voucher: the money goes back to the fund. Someone other than the person who recorded it does this, and not once it is in a settlement. @return array<string, mixed> the fund */
    public function void(PropertyId $property, string $actorId, string $voucherId, string $reason): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PETTY_MANAGE, 'This person may not void petty cash vouchers.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        $actor = strtolower($actorId);
        $fundId = '';

        $this->transactions->run(function () use ($property, $actor, $voucherId, $reason, &$fundId): void {
            $first = $this->store->voucher($property, strtolower($voucherId)) ?? throw Refusal::notFound('Voucher not found.');
            $this->store->lockFund($property, $first['fund_id']);
            $v = $this->store->voucher($property, $first['id']) ?? throw Refusal::notFound('Voucher not found.');
            $fundId = $v['fund_id'];

            if ($v['voided']) {
                throw Refusal::stateConflict('This voucher was voided already.');
            }

            if ($v['settlement_id'] !== null) {
                throw Refusal::stateConflict('This voucher is in settlement '.$v['settlement_number'].'; it can no longer be voided.');
            }

            if ($v['created_by'] === $actor) {
                throw Refusal::forbidden('A voucher is voided by someone other than the person who recorded it.');
            }

            $today = $this->businessDate->current($property)->toString();
            $now = $this->clock->nowUtc();
            $this->store->addVoid($property, ['voucher_id' => $v['id'], 'reason' => $reason, 'voided_by' => $actor, 'business_date' => $today], $now);
            $this->store->addEntry($property, ['id' => $this->ids->next(), 'fund_id' => $v['fund_id'], 'kind' => 'void', 'signed_minor' => (int) $v['amount_minor'], 'voucher_id' => $v['id'], 'settlement_id' => null, 'note' => mb_substr('Void '.$v['number'].': '.$reason, 0, 200), 'business_date' => $today, 'created_by' => $actor], $now);
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'petty_voucher.voided', 'petty_voucher', $v['id'], ['number' => $v['number'], 'amount_minor' => (int) $v['amount_minor']], ['voided' => true], $reason));
        });

        return $this->show($property, $actorId, $fundId);
    }

    /**
     * The custodian accounts for the vouchers since the last settlement with the cash counted. Every voucher needs a proof or a reason there is none.
     *
     * @return array<string, mixed> the settlement
     */
    public function submit(PropertyId $property, string $actorId, string $fundId, int $countedMinor, ?string $varianceReason, ?IdempotencyKey $key = null): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PETTY_OPERATE, 'This person may not account for petty cash.');
        $varianceReason = $varianceReason === null || trim($varianceReason) === '' ? null : trim($varianceReason);

        if ($countedMinor < 0 || $countedMinor > self::MAX_MINOR) {
            throw Refusal::invalid('Give the cash counted, zero or more.', ['counted_minor']);
        }

        if ($varianceReason !== null && mb_strlen($varianceReason) > 300) {
            throw Refusal::invalid('The reason is at most 300 characters.', ['variance_reason']);
        }

        $actor = strtolower($actorId);
        $id = $this->ids->next();

        $operation = function () use ($property, $actor, $id, $fundId, $countedMinor, $varianceReason): void {
            $this->store->lockFund($property, strtolower($fundId));
            $fund = $this->store->fund($property, strtolower($fundId)) ?? throw Refusal::notFound('Fund not found.');

            if ($fund['custodian_id'] !== $actor) {
                throw Refusal::forbidden('Only the custodian of a fund accounts for it.');
            }

            if ($fund['pending_settlement_id'] !== null) {
                throw Refusal::stateConflict('A settlement of this fund already waits for a decision.');
            }

            $vouchers = $this->store->unsettledVouchers($property, $fund['id']);

            if ($vouchers === []) {
                throw Refusal::stateConflict('There are no vouchers to account for.');
            }

            $missing = array_column(array_filter($vouchers, static fn (array $v): bool => (int) $v['proof_count'] === 0 && $v['no_receipt_reason'] === null), 'number');

            if ($missing !== []) {
                throw Refusal::stateConflict('Add a proof, or give the reason there is no receipt, to: '.implode(', ', $missing).'.');
            }

            if ($countedMinor > (int) $fund['imprest_minor']) {
                throw Refusal::invalid('The cash counted is more than the fund holds when it is full ('.(int) $fund['imprest_minor'].'). Count it again.', ['counted_minor']);
            }

            $book = (int) $fund['balance_minor'];
            $variance = $countedMinor - $book;

            if ($variance !== 0 && $varianceReason === null) {
                throw Refusal::invalid('The cash counted differs from the fund\'s book balance ('.$book.'): say why.', ['variance_reason']);
            }

            $number = $this->numbers->next($property, 'PCS');
            $now = $this->clock->nowUtc();
            $total = array_sum(array_map(static fn (array $v): int => (int) $v['amount_minor'], $vouchers));
            $replenish = (int) $fund['imprest_minor'] - $countedMinor;

            if (! $this->store->addSettlement($property, [
                'id' => $id, 'fund_id' => $fund['id'], 'number' => $number, 'voucher_total_minor' => $total, 'book_minor' => $book, 'counted_minor' => $countedMinor, 'variance_minor' => $variance, 'variance_reason' => $variance === 0 ? null : $varianceReason,
                'replenish_minor' => $replenish, 'business_date' => $this->businessDate->current($property)->toString(), 'submitted_by' => $actor, 'submitted_at' => $now->format('Y-m-d H:i:s.u'),
            ], array_column($vouchers, 'id'), $now)) {
                throw Refusal::stateConflict('A settlement of this fund already waits for a decision.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'petty_settlement.submitted', 'petty_settlement', $id, null, ['number' => $number, 'fund' => $fund['code'], 'vouchers' => count($vouchers), 'voucher_total_minor' => $total, 'book_minor' => $book, 'counted_minor' => $countedMinor, 'variance_minor' => $variance, 'replenish_minor' => $replenish], $variance === 0 ? null : $varianceReason));
            $this->outbox->publish(new OutboxEvent($property, 'finance.petty.settlement_submitted', $id, 1, ['settlement_id' => $id, 'number' => $number, 'fund_id' => $fund['id'], 'voucher_total_minor' => $total, 'variance_minor' => $variance, 'replenish_minor' => $replenish, 'currency' => $fund['currency'], 'actor_id' => $actor]));
        };

        $this->once($property, $actor, $key, 'finance.petty.submit', ['fund' => strtolower($fundId), 'counted' => $countedMinor, 'reason' => $varianceReason], $id, $operation);

        return $this->showSettlement($property, $actorId, $id);
    }

    /**
     * Another person approves or rejects a settlement. Approved, the difference is written to the ledger and the fund is replenished back to its amount;
     * rejected, the vouchers go back to the custodian.
     *
     * @return array<string, mixed>
     */
    public function decide(PropertyId $property, string $actorId, string $settlementId, bool $approve, ?string $note, int $expectedLockVersion): array
    {
        $this->access->require($property, $actorId, FinanceAccess::PETTY_MANAGE, 'This person may not decide petty cash settlements.');
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! $approve && $note === null) {
            throw Refusal::invalid('Say why the settlement is rejected.', ['note']);
        }

        if ($note !== null && mb_strlen($note) > 300) {
            throw Refusal::invalid('The note is at most 300 characters.', ['note']);
        }

        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $settlementId, $approve, $note, $expectedLockVersion): void {
            $first = $this->store->settlement($property, strtolower($settlementId)) ?? throw Refusal::notFound('Settlement not found.');
            $this->store->lockFund($property, $first['fund_id']);
            $s = $this->store->settlement($property, $first['id']) ?? throw Refusal::notFound('Settlement not found.');

            if ($s['status'] !== 'submitted') {
                throw Refusal::stateConflict('This settlement was decided already.');
            }

            if ($s['submitted_by'] === $actor) {
                throw Refusal::forbidden('A settlement is decided by someone other than the custodian who submitted it.');
            }

            $now = $this->clock->nowUtc();

            if (! $this->store->decideSettlement($property, $s['id'], $expectedLockVersion, $approve ? 'approved' : 'rejected', $actor, $note, $now)) {
                throw Refusal::stateConflict('This settlement changed after you opened it. Reload it.');
            }

            $today = $this->businessDate->current($property)->toString();

            if ($approve) {
                if ((int) $s['variance_minor'] !== 0) {
                    $this->store->addEntry($property, ['id' => $this->ids->next(), 'fund_id' => $s['fund_id'], 'kind' => 'difference', 'signed_minor' => (int) $s['variance_minor'], 'voucher_id' => null, 'settlement_id' => $s['id'], 'note' => mb_substr('Difference found at the count: '.$s['variance_reason'], 0, 200), 'business_date' => $today, 'created_by' => $actor], $now);
                }

                if ((int) $s['replenish_minor'] > 0) {
                    $this->store->addEntry($property, ['id' => $this->ids->next(), 'fund_id' => $s['fund_id'], 'kind' => 'replenishment', 'signed_minor' => (int) $s['replenish_minor'], 'voucher_id' => null, 'settlement_id' => $s['id'], 'note' => 'Replenished to the amount of the fund', 'business_date' => $today, 'created_by' => $actor], $now);
                }
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, $approve ? 'petty_settlement.approved' : 'petty_settlement.rejected', 'petty_settlement', $s['id'], ['status' => 'submitted'], ['status' => $approve ? 'approved' : 'rejected', 'number' => $s['number'], 'variance_minor' => (int) $s['variance_minor'], 'replenish_minor' => $approve ? (int) $s['replenish_minor'] : 0], $note));
            $this->outbox->publish(new OutboxEvent($property, $approve ? 'finance.petty.settlement_approved' : 'finance.petty.settlement_rejected', $s['id'], 1, ['settlement_id' => $s['id'], 'number' => $s['number'], 'fund_id' => $s['fund_id'], 'voucher_total_minor' => (int) $s['voucher_total_minor'], 'variance_minor' => (int) $s['variance_minor'], 'replenish_minor' => $approve ? (int) $s['replenish_minor'] : 0, 'currency' => $s['currency'], 'business_date' => $today, 'actor_id' => $actor]));
        });

        return $this->showSettlement($property, $actorId, $settlementId);
    }

    /** @return array<string, mixed> */
    private function visibleFund(PropertyId $property, string $actorId, string $id): array
    {
        $this->access->requirePettyView($property, $actorId);
        $fund = $this->store->fund($property, strtolower($id)) ?? throw Refusal::notFound('Fund not found.');
        $sees = $this->access->may($property, $actorId, FinanceAccess::PETTY_VIEW) || $this->access->may($property, $actorId, FinanceAccess::PETTY_MANAGE) || $fund['custodian_id'] === strtolower($actorId);

        return $sees ? $fund : throw Refusal::forbidden('This person may not see this fund.');
    }

    /** @param array<string, mixed> $f @param array<string, string> $names @return array<string, mixed> */
    private function fundShape(array $f, array $names): array
    {
        return [
            'id' => $f['id'], 'code' => $f['code'], 'name' => $f['name'], 'custodian_id' => $f['custodian_id'], 'custodian_name' => $names[$f['custodian_id']] ?? null, 'imprest_minor' => (int) $f['imprest_minor'],
            'max_voucher_minor' => $f['max_voucher_minor'] === null ? null : (int) $f['max_voucher_minor'], 'currency' => $f['currency'], 'active' => (bool) $f['is_active'], 'lock_version' => (int) $f['lock_version'],
            'balance_minor' => (int) $f['balance_minor'], 'unsettled_count' => (int) $f['unsettled_count'], 'unsettled_minor' => (int) $f['unsettled_minor'], 'pending_settlement_id' => $f['pending_settlement_id'],
        ];
    }

    /** @param array<string, mixed> $v @param array<string, string> $names @return array<string, mixed> */
    private function voucherShape(array $v, array $names, string $actor, bool $manage): array
    {
        $state = $v['voided'] ? 'voided' : ($v['settlement_status'] === 'approved' ? 'settled' : ($v['settlement_status'] === 'submitted' ? 'in_settlement' : 'open'));

        return [
            'id' => $v['id'], 'number' => $v['number'], 'voucher_date' => substr((string) $v['voucher_date'], 0, 10), 'payee' => $v['payee'], 'description' => $v['description'], 'account_code' => $v['account_code'], 'account_name' => $v['account_name'],
            'amount_minor' => (int) $v['amount_minor'], 'receipt_ref' => $v['receipt_ref'], 'no_receipt_reason' => $v['no_receipt_reason'], 'proof_count' => (int) $v['proof_count'], 'state' => $state, 'settlement_number' => $v['settlement_number'],
            'void_reason' => $v['void_reason'], 'by' => $names[$v['created_by']] ?? null, 'may_void' => $manage && $state === 'open' && $v['created_by'] !== $actor,
        ];
    }

    /** @param array<string, mixed> $s @param array<string, string> $names @return array<string, mixed> */
    private function settlementHead(array $s, array $names): array
    {
        return [
            'id' => $s['id'], 'number' => $s['number'], 'status' => $s['status'], 'voucher_total_minor' => (int) $s['voucher_total_minor'], 'counted_minor' => (int) $s['counted_minor'], 'variance_minor' => (int) $s['variance_minor'], 'replenish_minor' => (int) $s['replenish_minor'],
            'business_date' => substr((string) $s['business_date'], 0, 10), 'submitted_by' => $names[$s['submitted_by']] ?? null, 'decided_by' => ($s['decided_by'] ?? null) === null ? null : ($names[$s['decided_by']] ?? null),
            'decided_at' => ($s['decided_at'] ?? null) === null ? null : (new DateTimeImmutable((string) $s['decided_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function assertLimit(?int $max, int $imprest): void
    {
        if ($max !== null && ($max < 1 || $max > $imprest)) {
            throw Refusal::invalid('The limit of one voucher is above zero and at most the amount of the fund, or empty for none.', ['max_voucher_minor']);
        }
    }

    private function assertCustodian(PropertyId $property, string $custodianId): void
    {
        if (! in_array($custodianId, array_column($this->staff->withPermission($property, FinanceAccess::PETTY_OPERATE), 'id'), true)) {
            throw Refusal::invalid('Choose a person who may record petty cash vouchers.', ['custodian_id']);
        }
    }

    /** @param array<string, mixed> $fingerprint */
    private function once(PropertyId $property, string $actor, ?IdempotencyKey $key, string $operationName, array $fingerprint, string $id, \Closure $operation): string
    {
        if ($key === null) {
            $this->transactions->run($operation);

            return $id;
        }

        $once = $this->executor->execute(new IdempotencyRequest($property, $key, $operationName, $fingerprint, $actor), function () use ($operation, $id): array {
            $operation();

            return ['id' => $id];
        });

        return (string) $once->payload['id'];
    }

    private function date(string $value, string $field): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw Refusal::invalid('Give the date as year-month-day.', [$field]);
        }

        return $value;
    }
}
