<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use App\Shared\Domain\Time\DateMath;

/**
 * The bank statement against the books (docs/OPERATIONS/BANK-RECONCILIATION.md). The CSV the bank gives is read by `BankStatementParser`, checked first (nothing is kept), then brought in; a line sent twice is
 * recognised and left out. Each line is open, matched to one record of the books of the same amount and direction (a supplier payment, a payroll payment, a commission paid, a receipt on a receivable, a settlement
 * from a card or QRIS provider), or set aside with a note. The screen proposes the records of the same amount within a week of the line; a person confirms, nothing is matched by itself.
 */
final readonly class BankStatementService
{
    public const WINDOW_DAYS = 7;

    public const MAX_BYTES = 2_000_000;

    public function __construct(
        private BankStatementStore $store,
        private FinanceAccess $access,
        private PropertyCurrencyReader $currency,
        private BusinessDateProvider $businessDate,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $status): array
    {
        $this->view($property, $actorId);
        $status = in_array($status, ['open', 'matched', 'ignored'], true) ? $status : 'open';
        $lines = $this->store->lines($property, $status, 400);
        $suggest = $status === 'open' ? $this->suggestions($property, $lines) : [];
        $counts = $this->store->counts($property);

        return [
            'currency' => $this->currency->currencyOf($property), 'status' => $status, 'counts' => ['open' => $counts['open'] ?? 0, 'matched' => $counts['matched'] ?? 0, 'ignored' => $counts['ignored'] ?? 0],
            'lines' => array_map(fn (array $l): array => [
                'id' => $l['id'], 'account_label' => $l['account_label'], 'date' => substr((string) $l['line_date'], 0, 10), 'description' => $l['description'], 'reference' => $l['reference'], 'direction' => $l['direction'], 'amount_minor' => (int) $l['amount_minor'],
                'status' => $l['status'], 'match_type' => $l['match_type'], 'note' => $l['note'], 'suggestions' => $suggest[$l['id']] ?? [],
            ], $lines),
            'imports' => array_map(static fn (array $i): array => ['id' => $i['id'], 'account_label' => $i['account_label'], 'filename' => $i['filename'], 'rows_new' => (int) $i['rows_new'], 'rows_duplicate' => (int) $i['rows_duplicate'], 'created_at' => (string) $i['created_at']], $this->store->imports($property, 8)),
            'may_manage' => $this->access->may($property, $actorId, FinanceAccess::BANK_MANAGE),
        ];
    }

    /** @return array{status: string, dry_run: bool, count: int, duplicates: int, errors: list<array{line: int, code: string, message: string}>} */
    public function import(PropertyId $property, string $actorId, string $accountLabel, string $filename, string $contents, bool $dryRun): array
    {
        $this->manage($property, $actorId);
        $accountLabel = trim($accountLabel);

        if ($accountLabel === '' || mb_strlen($accountLabel) > 40) {
            throw Refusal::invalid('Name the bank account, for example "BCA 1234". At most 40 characters.', ['account_label']);
        }

        if (strlen($contents) > self::MAX_BYTES) {
            throw Refusal::invalid('The file is too large. Bring in a shorter period.', ['file']);
        }

        $read = BankStatementParser::parse($contents);

        if ($read['errors'] !== []) {
            return ['status' => 'rejected', 'dry_run' => $dryRun, 'count' => 0, 'duplicates' => 0, 'errors' => $read['errors']];
        }

        $seen = [];
        $rows = [];

        foreach ($read['lines'] as $l) {
            $key = implode('|', [$l['date'], $l['direction'], $l['amount_minor'], strtolower($l['description']), strtolower((string) $l['reference'])]);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $rows[] = [...$l, 'fingerprint' => BankStatementParser::fingerprint($accountLabel, $l['date'], $l['direction'], $l['amount_minor'], $l['description'], $l['reference'], $seen[$key])];
        }

        $known = $this->store->known($property, array_column($rows, 'fingerprint'));
        $fresh = array_values(array_filter($rows, static fn (array $r): bool => ! isset($known[$r['fingerprint']])));
        $duplicates = count($rows) - count($fresh);

        if ($dryRun) {
            return ['status' => 'validated', 'dry_run' => true, 'count' => count($fresh), 'duplicates' => $duplicates, 'errors' => []];
        }

        $this->transactions->run(function () use ($property, $actorId, $accountLabel, $filename, $fresh, $duplicates): void {
            $importId = $this->ids->next();
            $now = $this->clock->nowUtc();
            $this->store->addImport($property, ['id' => $importId, 'account_label' => $accountLabel, 'filename' => mb_substr($filename, 0, 120), 'rows_new' => count($fresh), 'rows_duplicate' => $duplicates, 'imported_by' => strtolower($actorId)], $now);
            $this->store->addLines($property, array_map(fn (array $r): array => [
                'id' => $this->ids->next(), 'import_id' => $importId, 'account_label' => $accountLabel, 'line_date' => $r['date'], 'description' => $r['description'], 'reference' => $r['reference'], 'direction' => $r['direction'], 'amount_minor' => $r['amount_minor'], 'balance_minor' => $r['balance_minor'], 'fingerprint' => $r['fingerprint'],
            ], $fresh), $now);
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'bank_statement.imported', 'bank_import', $importId, null, ['account' => $accountLabel, 'new' => count($fresh), 'duplicates' => $duplicates]));
        });

        return ['status' => 'applied', 'dry_run' => false, 'count' => count($fresh), 'duplicates' => $duplicates, 'errors' => []];
    }

    public function match(PropertyId $property, string $actorId, string $lineId, string $type, string $targetId): void
    {
        $this->manage($property, $actorId);
        $line = $this->store->line($property, strtolower($lineId)) ?? throw Refusal::notFound('Line not found.');
        $target = $this->store->target($property, $type, strtolower($targetId)) ?? throw Refusal::invalid('That record of the books cannot be matched to a bank line.', ['target']);

        if ($line['status'] !== 'open') {
            throw Refusal::stateConflict('This line was settled already.');
        }

        if ($target['direction'] !== $line['direction'] || $target['amount_minor'] !== (int) $line['amount_minor']) {
            throw Refusal::invalid('The record is not the same amount and direction as the line.', ['target']);
        }

        $this->transactions->run(function () use ($property, $actorId, $line, $target): void {
            if (! $this->store->match($property, $line['id'], $target['type'], $target['id'], strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('The line was settled meanwhile, or the record is matched to another line.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'bank_line.matched', 'bank_line', $line['id'], ['status' => 'open'], ['status' => 'matched', 'type' => $target['type'], 'record' => $target['label'], 'amount_minor' => $target['amount_minor']]));
        });
    }

    public function ignore(PropertyId $property, string $actorId, string $lineId, string $note): void
    {
        $this->manage($property, $actorId);
        $note = trim($note);

        if (mb_strlen($note) < 3 || mb_strlen($note) > 200) {
            throw Refusal::invalid('Say what this line is, in 3 to 200 characters (for example: bank fee, interest, not ours).', ['note']);
        }

        $line = $this->store->line($property, strtolower($lineId)) ?? throw Refusal::notFound('Line not found.');

        $this->transactions->run(function () use ($property, $actorId, $line, $note): void {
            if (! $this->store->ignore($property, $line['id'], $note, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This line was settled already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'bank_line.set_aside', 'bank_line', $line['id'], ['status' => 'open'], ['status' => 'ignored', 'amount_minor' => (int) $line['amount_minor']], $note));
        });
    }

    public function reopen(PropertyId $property, string $actorId, string $lineId, string $reason): void
    {
        $this->manage($property, $actorId);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Give the reason in at most 200 characters.', ['reason']);
        }

        $line = $this->store->line($property, strtolower($lineId)) ?? throw Refusal::notFound('Line not found.');

        $this->transactions->run(function () use ($property, $actorId, $line, $reason): void {
            if (! $this->store->reopen($property, $line['id'], $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This line is open already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'bank_line.reopened', 'bank_line', $line['id'], ['status' => $line['status'], 'match_type' => $line['match_type']], ['status' => 'open'], $reason));
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, list<array{type: string, id: string, label: string, days: int}>>
     */
    private function suggestions(PropertyId $property, array $lines): array
    {
        $out = [];

        foreach (['in', 'out'] as $direction) {
            $mine = array_values(array_filter($lines, static fn (array $l): bool => $l['direction'] === $direction));

            if ($mine === []) {
                continue;
            }

            $dates = array_map(static fn (array $l): string => substr((string) $l['line_date'], 0, 10), $mine);
            $candidates = $this->store->candidates($property, $direction, array_values(array_unique(array_map(static fn (array $l): int => (int) $l['amount_minor'], $mine))), DateMath::addDays(min($dates), -self::WINDOW_DAYS), DateMath::addDays(max($dates), self::WINDOW_DAYS));

            foreach ($mine as $l) {
                $date = substr((string) $l['line_date'], 0, 10);
                $text = strtolower($l['description'].' '.$l['reference']);
                $found = [];

                foreach ($candidates as $c) {
                    $days = BankStatementParser::days($date, $c['date']);

                    if ($c['amount_minor'] === (int) $l['amount_minor'] && $days <= self::WINDOW_DAYS) {
                        $found[] = ['type' => $c['type'], 'id' => $c['id'], 'label' => $c['label'], 'days' => $days, 'named' => str_contains($text, strtolower((string) strstr($c['label'], ' ', true)))];
                    }
                }

                usort($found, static fn (array $a, array $b): int => [$b['named'], $a['days']] <=> [$a['named'], $b['days']]);
                $out[$l['id']] = array_map(static fn (array $f): array => ['type' => $f['type'], 'id' => $f['id'], 'label' => $f['label'], 'days' => $f['days']], array_slice($found, 0, 4));
            }
        }

        return $out;
    }

    private function view(PropertyId $property, string $actorId): void
    {
        $this->access->assertProperty($property);

        if (! $this->access->may($property, $actorId, FinanceAccess::BANK_VIEW) && ! $this->access->may($property, $actorId, FinanceAccess::BANK_MANAGE)) {
            throw Refusal::forbidden('This person may not see bank statements.');
        }
    }

    private function manage(PropertyId $property, string $actorId): void
    {
        $this->access->require($property, $actorId, FinanceAccess::BANK_MANAGE, 'This person may not work on bank statements.');
    }
}
