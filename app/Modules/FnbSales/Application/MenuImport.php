<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Import\BatchImport;
use App\Shared\Application\Import\CsvRows;
use App\Shared\Domain\Tenancy\PropertyId;
use NumberFormatter;

/**
 * Opens the menu from a CSV file: each row names its outlet and category by code and goes through the same `MenuService::addItem` a person uses on screen,
 * all or nothing. The outlets and categories must already exist. The price is typed in whole currency units ("25000" or "25000.50"), never in minor units.
 */
final readonly class MenuImport
{
    public const TEMPLATE = "outlet,category,code,name,price,station,description,order\nRESTO,MAIN,NASI-GRG,Nasi goreng,35000,kitchen,Telur dan ayam,10\nRESTO,DRINK,ES-TEH,Es teh manis,12000,bar,,20\n";

    public function __construct(private MenuService $menu, private SetupStore $store, private PropertyCurrencyReader $currencies, private BatchImport $batch) {}

    /** @return array{status: string, dry_run: bool, count: int, errors: list<array{line: int, code: string, message: string}>} */
    public function run(PropertyId $property, string $actorId, string $csv, bool $dryRun): array
    {
        $parsed = CsvRows::parse($csv, ['outlet', 'category', 'code', 'name', 'price'], ['station', 'description', 'order']);
        $currency = $this->currencies->currencyOf($property);
        $outlets = [];

        foreach ($this->store->outlets($property) as $outlet) {
            $outlets[strtoupper((string) $outlet['code'])] = $outlet['id'];
        }

        return $this->batch->run($parsed, function (array $row) use ($property, $actorId, $currency, $outlets): void {
            $outletId = $outlets[strtoupper($row['outlet'])] ?? throw Refusal::invalid('This outlet does not exist: '.$row['outlet'], ['outlet']);
            $category = null;

            foreach ($this->store->categories($property, (string) $outletId) as $candidate) {
                if (strtoupper((string) $candidate['code']) === strtoupper($row['category'])) {
                    $category = $candidate;
                }
            }

            $category ?? throw Refusal::invalid('This category does not exist in the outlet: '.$row['category'], ['category']);
            $order = ($row['order'] ?? '') === '' ? 0 : (preg_match('/^\d{1,4}$/', $row['order']) === 1 ? (int) $row['order'] : throw Refusal::invalid('The order is a number from 0 to 9999.', ['order']));
            $station = ($row['station'] ?? '') === '' ? null : strtolower($row['station']);
            $description = ($row['description'] ?? '') === '' ? null : $row['description'];

            $this->menu->addItem($property, $actorId, (string) $category['id'], $row['code'], $row['name'], $description, self::minor($row['price'], $currency), $station, [], [], $order);
        }, $dryRun);
    }

    /** The price as typed, in whole currency units, to integer minor units; a grouped number such as "1.500" is refused rather than guessed. */
    private static function minor(string $text, string $currency): int
    {
        $exponent = self::exponent($currency);

        if (preg_match('/^(\d{1,13})(?:[.,](\d{1,3}))?$/', $text, $m) !== 1 || strlen($m[2] ?? '') > $exponent) {
            throw Refusal::invalid('Give the price as digits, with a decimal point only if the currency has cents.', ['price']);
        }

        return (int) $m[1] * 10 ** $exponent + (int) substr(($m[2] ?? '').str_repeat('0', $exponent), 0, $exponent);
    }

    private static function exponent(string $currency): int
    {
        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
            $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

            return (int) $formatter->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS);
        }

        return match (true) {
            in_array($currency, ['JPY', 'KRW', 'VND', 'CLP', 'ISK'], true) => 0,
            in_array($currency, ['BHD', 'KWD', 'OMR', 'JOD', 'TND'], true) => 3,
            default => 2,
        };
    }
}
