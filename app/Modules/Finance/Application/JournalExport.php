<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Shared\Domain\Tenancy\PropertyId;
use LogicException;

/**
 * The books as a general journal for the accounting software (FR-FIN-034): one balanced entry for each revenue day, approved correction, supplier invoice, supplier credit, supplier payment, petty cash voucher,
 * receivable, receipt, gift card sold and gift card voided in the range. Every line carries the account code and name of the property's mapping (`JournalAccounts`). The day's sales are credited to the revenue accounts of their outlets, with the
 * service charge and the tax apart; the day's payments are debited to cash or bank by method; what is left is the guest ledger, which the receivables and the receipts then move on to the receivable account.
 *
 * Not in the journal: petty cash top-ups and settlements, payroll, depreciation, and anything the property books outside the system.
 */
final readonly class JournalExport
{
    public const HEADER = ['Date', 'Journal', 'Line', 'Account code', 'Account name', 'Debit', 'Credit', 'Description', 'Reference'];

    /** The debit and credit columns, which hold money. */
    public const MONEY = [5, 6];

    public function __construct(private JournalSources $sources, private JournalAccountStore $accounts) {}

    /** @return array{header: list<string>, money: list<int>, rows: list<list<scalar|null>>} */
    public function dataset(PropertyId $property, string $from, string $to, int $limit): array
    {
        $map = array_map(static fn (array $o): array => ['code' => $o['code'], 'name' => $o['name']], $this->accounts->overrides($property));
        $entries = [];
        $acc = static fn (string $key, string $fallback = 'expense.other'): array => JournalAccounts::resolve($map, $key, $fallback);
        $method = static function (string $name) use ($acc): array {
            $m = JournalAccounts::ofMethod($name);

            return $acc($m['key'], $m['fallback']);
        };

        foreach ([['REV', $this->sources->revenueDays($property, $from, $to, $limit), 'Sales of the day'], ['COR', $this->sources->corrections($property, $from, $to, $limit), 'Approved correction']] as [$prefix, $days, $what]) {
            foreach ($days as $day) {
                $nets = [];
                $sum = 0;

                foreach ($day['revenue'] as $line) {
                    $nets[] = [$acc('revenue.'.$line['key'], 'revenue.other'), -$line['base']];
                    $nets[] = [$acc('service_charge'), -$line['service']];
                    $nets[] = [$acc('tax_payable'), -$line['tax']];
                    $sum += $line['base'] + $line['service'] + $line['tax'];
                }

                foreach ($day['payments'] as $payment) {
                    $nets[] = [$method($payment['method']), $payment['net']];
                    $sum -= $payment['net'];
                }

                $nets[] = [$acc('guest_ledger'), $sum];
                $entries[] = $this->entry($day['date'], $prefix.'-'.$day['number'], $what.' '.$day['number'], '', $nets);
            }
        }

        foreach ($this->sources->payables($property, $from, $to, $limit) as $p) {
            $expense = $p['account_code'] === null ? $acc('expense.other') : ['code' => $p['account_code'], 'name' => (string) $p['account_name']];
            $entries[] = $this->entry($p['date'], 'INV-'.$p['number'], 'Supplier invoice '.$p['supplier'], $p['document'], [[$expense, $p['amount'] - $p['tax']], [$acc('input_tax'), $p['tax']], [$acc('accounts_payable'), -$p['amount']]]);
        }

        foreach ($this->sources->supplierCredits($property, $from, $to, $limit) as $c) {
            $entries[] = $this->entry($c['date'], 'CRN-'.$c['number'], 'Supplier credit '.$c['supplier'], $c['document'], [[$acc('accounts_payable'), $c['amount']], [$acc('purchase_returns'), -$c['amount']]]);
        }

        foreach ($this->sources->supplierPayments($property, $from, $to, $limit) as $p) {
            $entries[] = $this->entry($p['date'], 'PAY-'.$p['number'], ($p['amount'] < 0 ? 'Reversal of payment to ' : 'Payment to ').$p['supplier'], '', [[$acc('accounts_payable'), $p['amount']], [$method($p['method']), -$p['amount']]]);
        }

        foreach ($this->sources->pettyVouchers($property, $from, $to, $limit) as $v) {
            $entries[] = $this->entry($v['date'], 'PTY-'.$v['number'], 'Petty cash to '.$v['payee'], '', [[['code' => $v['account_code'], 'name' => $v['account_name']], $v['amount']], [$acc('petty_cash'), -$v['amount']]]);
        }

        foreach ($this->sources->receivables($property, $from, $to, $limit) as $r) {
            $entries[] = $this->entry($r['date'], 'AR-'.$r['number'], 'Receivable '.$r['customer'], '', [[$acc('accounts_receivable'), $r['amount']], [$acc('guest_ledger'), -$r['amount']]]);
        }

        foreach ($this->sources->receipts($property, $from, $to, $limit) as $r) {
            $entries[] = $this->entry($r['date'], 'RCT-'.$r['number'], ($r['amount'] < 0 ? 'Reversal of receipt from ' : 'Receipt from ').$r['customer'], '', [[$method($r['method']), $r['amount']], [$acc('accounts_receivable'), -$r['amount']]]);
        }

        foreach ($this->sources->giftCardIssues($property, $from, $to, $limit) as $g) {
            $what = $g['kind'] === 'voucher' ? 'Voucher' : 'Gift card';
            // A voucher the hotel gives away brings in no money: it is a cost of promotion until it is spent.
            $debit = $g['method'] === 'complimentary' ? $acc('promotion_expense') : $method($g['method']);
            $entries[] = $this->entry($g['date'], 'GCS-'.$g['number'], $what.' sold, ending '.$g['number'], '', [[$debit, $g['amount']], [$acc('gift_card_liability'), -$g['amount']]]);
        }

        foreach ($this->sources->giftCardVoids($property, $from, $to, $limit) as $g) {
            // What is left is paid back by the way it was bought, or written off as other revenue.
            $credit = $g['disposition'] === 'refunded' ? ($g['method'] === 'complimentary' ? $acc('promotion_expense') : $method($g['method'])) : $acc('revenue.other', 'revenue.other');
            $entries[] = $this->entry($g['date'], 'GCV-'.$g['number'], 'Gift card voided, ending '.$g['number'].($g['disposition'] === 'refunded' ? ', paid back' : ', written off'), '', [[$acc('gift_card_liability'), $g['amount']], [$credit, -$g['amount']]]);
        }

        usort($entries, static fn (array $a, array $b): int => [$a['date'], $a['journal']] <=> [$b['date'], $b['journal']]);

        $rows = [];

        foreach ($entries as $entry) {
            foreach ($entry['lines'] as $i => $line) {
                $rows[] = [$entry['date'], $entry['journal'], $i + 1, $line['code'], $line['name'], $line['debit'], $line['credit'], $entry['description'], $entry['reference']];

                if (count($rows) > $limit) {
                    break 2;
                }
            }
        }

        return ['header' => self::HEADER, 'money' => self::MONEY, 'rows' => $rows];
    }

    /**
     * One balanced entry: the signed amounts (debit positive) are added up by account, zero lines are dropped, and a debit and a credit side that do not match are a fault of this code, never of the data.
     *
     * @param  list<array{0: array{code: string, name: string}, 1: int}>  $nets
     * @return array{date: string, journal: string, description: string, reference: string, lines: list<array{code: string, name: string, debit: int, credit: int}>}
     */
    private function entry(string $date, string $journal, string $description, string $reference, array $nets): array
    {
        $byAccount = [];

        foreach ($nets as [$account, $net]) {
            $byAccount[$account['code']] ??= ['code' => $account['code'], 'name' => $account['name'], 'net' => 0];
            $byAccount[$account['code']]['net'] += $net;
        }

        $lines = [];
        $balance = 0;

        foreach ($byAccount as $a) {
            $balance += $a['net'];

            if ($a['net'] !== 0) {
                $lines[] = ['code' => $a['code'], 'name' => $a['name'], 'debit' => max($a['net'], 0), 'credit' => max(-$a['net'], 0)];
            }
        }

        if ($balance !== 0) {
            throw new LogicException('The journal entry '.$journal.' does not balance.');
        }

        usort($lines, static fn (array $a, array $b): int => [$a['credit'] > 0, $a['code']] <=> [$b['credit'] > 0, $b['code']]);

        return ['date' => $date, 'journal' => $journal, 'description' => mb_substr($description, 0, 120), 'reference' => $reference, 'lines' => $lines];
    }
}
