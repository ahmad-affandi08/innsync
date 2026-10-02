<?php

declare(strict_types=1);

namespace App\Modules\FrontOffice\Application\ForeignPayments;

use App\Modules\FrontOffice\Application\Folios\FolioLedger;
use App\Modules\FrontOffice\Application\Folios\FolioRepository;
use App\Modules\FrontOffice\Application\Folios\FolioService;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Modules\Property\Application\Settings\BusinessDateProvider;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Outbox\OutboxEvent;
use App\Shared\Application\Outbox\OutboxPublisher;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * Payment in a foreign currency (FR-FO-026), only when the hotel has switched the feature on. The folio always stays in the property's
 * currency: the guest hands over, say, 100 US dollars, the clerk books them at the hotel's own rate and the folio is paid by the
 * rupiah equivalent, whole rupiah, rounded half up. The payment keeps the foreign amount, the rate and its version. The hotel types
 * the rates in; nothing is fetched. Whether a hotel may take foreign money at all is a question for counsel, which is why it is off.
 */
final readonly class ForeignPaymentService
{
    public const SETTINGS_PERMISSION = 'front-office.foreign-payment.settings';

    /** Currencies with two decimals, like the rupiah's minor unit, so one conversion fits them all. */
    public const CURRENCIES = ['USD', 'EUR', 'SGD', 'AUD', 'MYR', 'GBP', 'CNY', 'SAR'];

    /** One unit of foreign money may be worth up to this many units of the property's currency (1,000,000.0000). */
    public const MAX_RATE_E4 = 10_000_000_000;

    /** About a million units of foreign money in one payment, so the conversion cannot overflow. */
    public const MAX_FOREIGN_MINOR = 100_000_000;

    public function __construct(
        private ForeignPaymentRepository $repository,
        private FolioService $folios,
        private FolioRepository $folioStore,
        private FolioLedger $ledger,
        private PropertyCurrencyReader $currencies,
        private BusinessDateProvider $businessDate,
        private PermissionChecker $permissions,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private OutboxPublisher $outbox,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /**
     * What the folio screen needs: whether foreign payment is on and the rates in force. No permission beyond reaching the folio.
     *
     * @return array{enabled: bool, home_currency: string, rates: list<array{currency: string, version: int, rate_e4: int}>}
     */
    public function offer(PropertyId $property): array
    {
        $this->assertProperty($property);
        $enabled = $this->repository->settings($property)['enabled'];

        return [
            'enabled' => $enabled, 'home_currency' => $this->currencies->currencyOf($property),
            'rates' => $enabled ? array_map(static fn (array $r): array => ['currency' => $r['currency'], 'version' => $r['version'], 'rate_e4' => $r['rate_e4']], $this->repository->currentRates($property)) : [],
        ];
    }

    /**
     * @return array{enabled: bool, lock_version: int, home_currency: string, currencies: list<string>, rates: list<array<string, mixed>>, recent: list<array<string, mixed>>, today: list<array<string, mixed>>, business_date: string}
     */
    public function overview(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId);
        $settings = $this->repository->settings($property);
        $today = $this->businessDate->current($property)->toString();

        return [
            'enabled' => $settings['enabled'], 'lock_version' => $settings['lock_version'], 'home_currency' => $this->currencies->currencyOf($property), 'currencies' => self::CURRENCIES,
            'rates' => $this->repository->currentRates($property), 'recent' => $this->repository->recent($property, 20), 'today' => $this->repository->takenOn($property, $today), 'business_date' => $today,
        ];
    }

    /** @return array{enabled: bool, lock_version: int} */
    public function setEnabled(PropertyId $property, string $actorId, bool $enabled, int $lock, string $reason): array
    {
        $this->authorize($property, $actorId);

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $before = $this->repository->settings($property);

        $this->transactions->run(function () use ($property, $actorId, $enabled, $lock, $reason, $before): void {
            if (! $this->repository->setEnabled($property, $enabled, $lock, strtolower($actorId), $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This setting changed after you opened it.');
            }

            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'foreign_payment.switched', 'foreign_payment_settings', $property->toString(), ['enabled' => $before['enabled']], ['enabled' => $enabled], trim($reason)));
        });

        return $this->repository->settings($property);
    }

    /** @return array{currency: string, version: int, rate_e4: int, reason: string, created_at: string} */
    public function setRate(PropertyId $property, string $actorId, string $currency, int $rateE4, string $reason): array
    {
        $this->authorize($property, $actorId);
        $currency = strtoupper(trim($currency));

        if (! in_array($currency, self::CURRENCIES, true)) {
            throw Refusal::invalid('Choose one of the currencies the hotel can take.', ['currency']);
        }

        if ($rateE4 < 1 || $rateE4 > self::MAX_RATE_E4) {
            throw Refusal::invalid('The rate is more than zero and at most 1,000,000 for one unit.', ['rate']);
        }

        if (trim($reason) === '' || mb_strlen($reason) > 300) {
            throw Refusal::invalid('A reason of at most 300 characters is required.', ['reason']);
        }

        $previous = $this->repository->currentRate($property, $currency);

        $this->transactions->run(function () use ($property, $actorId, $currency, $rateE4, $reason, $previous): void {
            $version = $this->repository->addRate($property, $this->ledger->newId(), $currency, $rateE4, trim($reason), strtolower($actorId), $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'foreign_payment.rate_set', 'exchange_rate', $currency, $previous === null ? null : ['rate_e4' => $previous['rate_e4'], 'version' => $previous['version']], ['rate_e4' => $rateE4, 'version' => $version], trim($reason)));
        });

        return $this->repository->currentRate($property, $currency) ?? throw Refusal::notFound('Rate not found.');
    }

    /**
     * Takes a payment in foreign money onto a folio, booked in the property's currency at the rate in force.
     *
     * @return array<string, mixed> the folio's payment result, plus what was taken
     */
    public function pay(PropertyId $property, string $actorId, string $folioId, string $method, string $currency, int $foreignMinor, ?string $reference, string $purpose, ?string $sourceRef = null): array
    {
        $this->assertProperty($property);
        $currency = strtoupper(trim($currency));

        if (! $this->repository->settings($property)['enabled']) {
            throw Refusal::stateConflict('Payment in a foreign currency is not switched on.');
        }

        if (! in_array($currency, self::CURRENCIES, true)) {
            throw Refusal::invalid('Choose one of the currencies the hotel can take.', ['currency']);
        }

        if ($foreignMinor < 1 || $foreignMinor > self::MAX_FOREIGN_MINOR) {
            throw Refusal::invalid('Give an amount of more than zero and at most 1,000,000.', ['amount']);
        }

        $folio = $this->folioStore->find($property, strtolower($folioId)) ?? throw Refusal::notFound('Folio not found.');
        $rate = $this->repository->currentRate($property, $currency) ?? throw Refusal::stateConflict("No rate is set for {$currency}.");
        $booked = self::convert($foreignMinor, $rate['rate_e4']);

        if ($booked < 1) {
            throw Refusal::invalid('This amount is worth less than one unit of the hotel\'s currency.', ['amount']);
        }

        $note = trim((string) $reference);
        $label = sprintf('%s %s rate %s', $currency, self::plain($foreignMinor), self::plain($rate['rate_e4'], 4));
        $text = $note === '' ? $label : mb_substr($label.' - '.$note, 0, 80);

        return $this->transactions->run(function () use ($property, $actorId, $folio, $method, $currency, $foreignMinor, $text, $purpose, $sourceRef, $rate, $booked): array {
            $result = $this->folios->pay($property, $actorId, $folio->id, $method, $booked, $text, $purpose, $sourceRef);

            if (! $result['replayed'] && ! $this->repository->recorded($property, $result['posting']['id'])) {
                $this->repository->record($property, $result['posting']['id'], $currency, $foreignMinor, $rate['rate_e4'], $rate['version'], $booked, $this->clock->nowUtc());
                $this->audit->record(new AuditEntry($property->toString(), strtolower($actorId), 'foreign_payment.taken', 'folio', $folio->id, null, ['currency' => $currency, 'foreign_minor' => $foreignMinor, 'rate_e4' => $rate['rate_e4'], 'rate_version' => $rate['version'], 'booked_minor' => $booked, 'posting_id' => $result['posting']['id']]));
                $this->outbox->publish(new OutboxEvent($property, 'frontoffice.foreign_payment.taken', $result['posting']['id'], 1, ['folio_id' => $folio->id, 'posting_id' => $result['posting']['id'], 'currency' => $currency, 'foreign_minor' => $foreignMinor, 'booked_minor' => $booked, 'actor_id' => strtolower($actorId)]));
            }

            return $result + ['foreign' => ['currency' => $currency, 'foreign_minor' => $foreignMinor, 'rate_e4' => $rate['rate_e4'], 'rate_version' => $rate['version'], 'booked_minor' => $booked]];
        });
    }

    /** Whole units of the property's currency, rounded half up, as minor units (two decimals): foreign minor times the rate to four decimals. */
    public static function convert(int $foreignMinor, int $rateE4): int
    {
        return intdiv($foreignMinor * $rateE4 + 500_000, 1_000_000) * 100;
    }

    private static function plain(int $value, int $decimals = 2): string
    {
        $whole = intdiv($value, 10 ** $decimals);
        $fraction = str_pad((string) ($value % (10 ** $decimals)), $decimals, '0', STR_PAD_LEFT);

        return $whole.'.'.$fraction;
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $this->assertProperty($property);

        if (! $this->permissions->allowsInProperty($actorId, self::SETTINGS_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not set up payment in a foreign currency.');
        }
    }

    private function assertProperty(PropertyId $property): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }
    }
}
