<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

/** The baseline chart of accounts of the accounting export and how a key finds its code: the property's own row, else the baseline (a revenue outlet or a payment method with no row of its own falls back to a general key). */
final class JournalAccounts
{
    /** @var array<string, array{0: string, 1: string}> key => [code, name] */
    public const BASELINE = [
        'cash' => ['1-1110', 'Kas'],
        'bank' => ['1-1120', 'Bank dan penerimaan non tunai'],
        'petty_cash' => ['1-1130', 'Kas kecil'],
        'accounts_receivable' => ['1-1200', 'Piutang usaha'],
        'input_tax' => ['1-1500', 'PPN masukan'],
        'accounts_payable' => ['2-1100', 'Hutang usaha'],
        'guest_ledger' => ['2-1300', 'Guest ledger dan uang muka tamu'],
        'fixed_assets' => ['1-3000', 'Aset tetap'],
        'accumulated_depreciation' => ['1-3900', 'Akumulasi penyusutan'],
        'depreciation_expense' => ['5-6000', 'Beban penyusutan'],
        'disposal_loss' => ['5-6100', 'Rugi pelepasan aset tetap'],
        'disposal_gain' => ['4-2000', 'Laba pelepasan aset tetap'],
        'commission_payable' => ['2-1700', 'Hutang komisi agen dan OTA'],
        'commission_expense' => ['5-3000', 'Beban komisi agen dan OTA'],
        'gift_card_liability' => ['2-1600', 'Kewajiban gift card dan voucher'],
        'promotion_expense' => ['5-8000', 'Beban promosi'],
        'service_charge' => ['2-1400', 'Hutang service charge'],
        'tax_payable' => ['2-1500', 'Hutang pajak daerah dan PPN keluaran'],
        'revenue.rooms' => ['4-1100', 'Pendapatan kamar'],
        'revenue.laundry' => ['4-1300', 'Pendapatan laundry'],
        'revenue.other' => ['4-1900', 'Pendapatan lain-lain'],
        'expense.other' => ['5-9000', 'Beban lain-lain'],
        'purchase_returns' => ['5-1900', 'Retur pembelian'],
    ];

    public static function validKey(string $key): bool
    {
        return isset(self::BASELINE[$key]) || preg_match('/^(revenue|payment)\.[a-z0-9_-]{1,20}$/', $key) === 1;
    }

    /**
     * @param  array<string, array{code: string, name: string}>  $overrides
     * @return array{code: string, name: string}
     */
    public static function resolve(array $overrides, string $key, string $fallback): array
    {
        foreach ([$key, $fallback] as $candidate) {
            if (isset($overrides[$candidate])) {
                return ['code' => $overrides[$candidate]['code'], 'name' => $overrides[$candidate]['name']];
            }

            if (isset(self::BASELINE[$candidate])) {
                return ['code' => self::BASELINE[$candidate][0], 'name' => self::BASELINE[$candidate][1]];
            }
        }

        return ['code' => self::BASELINE['expense.other'][0], 'name' => self::BASELINE['expense.other'][1]];
    }

    /**
     * The account of a payment method: its own row, else cash for cash and the bank for everything else.
     *
     * @return array{key: string, fallback: string}
     */
    public static function ofMethod(string $method): array
    {
        $method = strtolower($method);
        $method = preg_match('/^[a-z0-9_-]{1,20}$/', $method) === 1 ? $method : 'other';

        return ['key' => 'payment.'.$method, 'fallback' => match ($method) {
            'cash' => 'cash',
            'gift_card' => 'gift_card_liability',
            default => 'bank',
        }];
    }

    /**
     * @param  array<string, array{id: string, code: string, name: string, lock_version: int}>  $overrides
     * @return list<array{key: string, code: string, name: string, baseline_code: string|null, baseline_name: string|null, changed: bool, lock_version: int|null}>
     */
    public static function resolveAll(array $overrides): array
    {
        $out = [];

        foreach (array_unique([...array_keys(self::BASELINE), ...array_keys($overrides)]) as $key) {
            $own = $overrides[$key] ?? null;
            $base = self::BASELINE[$key] ?? null;
            $out[] = [
                'key' => $key, 'code' => $own['code'] ?? $base[0] ?? '', 'name' => $own['name'] ?? $base[1] ?? '', 'baseline_code' => $base[0] ?? null, 'baseline_name' => $base[1] ?? null,
                'changed' => $own !== null, 'lock_version' => $own['lock_version'] ?? null,
            ];
        }

        return $out;
    }
}
