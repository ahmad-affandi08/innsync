<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use App\Modules\Property\Application\Ports\PropertyTimeZoneReader;
use App\Modules\Property\Application\Rates\PropertyCurrencyReader;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Identifiers\IdentifierGenerator;
use App\Shared\Application\Time\Clock;
use App\Shared\Application\Transactions\TransactionRunner;
use App\Shared\Domain\Tenancy\PropertyId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Price lists and scheduled promotions of an outlet (FR-FBS-015): a price for an item (or one of its variants) that holds for a channel, on some days of the week, in some
 * hours of the day, between two dates. The menu price stays the price when no rule holds. A rule is never edited: to change a price, retire the rule and add another, so the
 * history explains every price. Bills keep the price their line was ordered at, whatever happens to the rules later.
 */
final readonly class PriceRuleService
{
    public const ALL_DAYS = 127;

    public function __construct(
        private PriceRuleStore $rules,
        private SetupStore $setup,
        private FnbAccess $access,
        private PropertyTimeZoneReader $zones,
        private PropertyCurrencyReader $currencies,
        private TransactionRunner $transactions,
        private AuditTrail $audit,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    /** The prices of an outlet as they hold now, for a bill that is being taken. */
    public function bookOf(PropertyId $property, string $outletId): PriceBook
    {
        return new PriceBook($this->rules->rules($property, $outletId, true), $this->local($property, $this->clock->nowUtc()));
    }

    /** @return array<string, mixed> */
    public function overview(PropertyId $property, string $actorId, ?string $outletId): array
    {
        $this->requireView($property, $actorId);
        $outlets = array_values(array_filter($this->setup->outlets($property), static fn (array $o): bool => (bool) $o['is_active']));
        $selected = null;

        foreach ($outlets as $o) {
            if ($outletId === null || $o['id'] === strtolower($outletId)) {
                $selected = $o;

                break;
            }
        }

        if ($outletId !== null && $selected === null) {
            throw Refusal::notFound('Outlet not found.');
        }

        $rules = [];
        $items = [];

        if ($selected !== null) {
            $book = $this->bookOf($property, $selected['id']);
            $rows = $this->rules->rules($property, $selected['id'], false);
            $names = [];

            foreach ($this->setup->items($property, $selected['id']) as $i) {
                $names[$i['id']] = $i;

                if ((bool) $i['is_active']) {
                    $items[] = [
                        'id' => $i['id'], 'code' => $i['code'], 'name' => $i['name'], 'price_minor' => (int) $i['price_minor'],
                        'variants' => array_values(array_map(static fn (array $v): array => ['id' => $v['id'], 'name' => $v['name'], 'price_minor' => (int) $v['price_minor']], array_filter($i['variants'], static fn (array $v): bool => (bool) $v['is_active']))),
                        'now' => array_combine(PriceBook::CHANNELS, array_map(static fn (string $c): int => $book->price($i['id'], null, $c, (int) $i['price_minor'])[0], PriceBook::CHANNELS)),
                    ];
                }
            }

            foreach ($rows as $r) {
                $variants = $names[$r['item_id']]['variants'] ?? [];
                $variant = null;

                foreach ($variants as $v) {
                    if ($v['id'] === $r['variant_id']) {
                        $variant = $v['name'];
                    }
                }

                $rules[] = [
                    'id' => $r['id'], 'item_id' => $r['item_id'], 'item' => $r['item_code'].' · '.$r['item_name'], 'variant_id' => $r['variant_id'], 'variant' => $variant, 'channel' => $r['channel'], 'kind' => $r['kind'], 'name' => $r['name'],
                    'price_minor' => (int) $r['price_minor'], 'valid_from' => (string) $r['valid_from'], 'valid_to' => $r['valid_to'], 'days' => (int) $r['days'], 'from_time' => $r['from_time'] === null ? null : substr((string) $r['from_time'], 0, 5),
                    'to_time' => $r['to_time'] === null ? null : substr((string) $r['to_time'], 0, 5), 'is_active' => (bool) $r['is_active'], 'retire_reason' => $r['retire_reason'], 'retired_at' => FnbTime::utc($r['retired_at']),
                    'holds_now' => (bool) $r['is_active'] && $book->holds($r, $r['item_id'], $r['variant_id'], $r['channel'] === 'all' ? 'dine_in' : $r['channel']),
                ];
            }
        }

        return [
            'currency' => $this->currencies->currencyOf($property),
            'outlets' => array_map(static fn (array $o): array => ['id' => $o['id'], 'code' => $o['code'], 'name' => $o['name']], $outlets),
            'outlet' => $selected === null ? null : ['id' => $selected['id'], 'code' => $selected['code'], 'name' => $selected['name']],
            'rules' => $rules, 'items' => $items, 'may' => ['manage' => $this->access->may($property, $actorId, FnbAccess::PRICES_MANAGE)],
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function add(PropertyId $property, string $actorId, string $outletId, array $in): array
    {
        $this->access->require($property, $actorId, FnbAccess::PRICES_MANAGE, 'This person may not set prices.');
        $outlet = $this->setup->outlet($property, strtolower($outletId)) ?? throw Refusal::invalid('Choose an outlet.', ['outlet_id']);
        $item = $this->setup->item($property, strtolower((string) ($in['item_id'] ?? ''))) ?? throw Refusal::invalid('Choose an item of the menu.', ['item_id']);

        if ($item['outlet_id'] !== $outlet['id']) {
            throw Refusal::invalid('Choose an item of the menu of this outlet.', ['item_id']);
        }

        $variantId = ($in['variant_id'] ?? null) === null ? null : strtolower((string) $in['variant_id']);

        if ($variantId !== null && count(array_filter($item['variants'], static fn (array $v): bool => $v['id'] === $variantId)) === 0) {
            throw Refusal::invalid('Choose a variant of this item.', ['variant_id']);
        }

        $channel = (string) ($in['channel'] ?? 'all');
        $kind = (string) ($in['kind'] ?? 'price');

        if (! in_array($channel, ['all', ...PriceBook::CHANNELS], true)) {
            throw Refusal::invalid('Choose where the price holds.', ['channel']);
        }

        if (! in_array($kind, ['price', 'promo'], true)) {
            throw Refusal::invalid('Choose a price list or a promotion.', ['kind']);
        }

        $name = trim((string) ($in['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 80) {
            throw Refusal::invalid('Name the price, in at most 80 characters.', ['name']);
        }

        $price = (int) ($in['price_minor'] ?? 0);

        if ($price < 1 || $price > 9_000_000_000_000) {
            throw Refusal::invalid('Give a price above zero.', ['price_minor']);
        }

        $from = $this->date($in['valid_from'] ?? null, 'valid_from');
        $to = ($in['valid_to'] ?? null) === null || $in['valid_to'] === '' ? null : $this->date($in['valid_to'], 'valid_to');

        if ($from === null) {
            throw Refusal::invalid('Give the first day the price holds.', ['valid_from']);
        }

        if ($to !== null && $to < $from) {
            throw Refusal::invalid('The last day is not before the first.', ['valid_to']);
        }

        $days = (int) ($in['days'] ?? self::ALL_DAYS);

        if ($days < 1 || $days > self::ALL_DAYS) {
            throw Refusal::invalid('Choose at least one day of the week.', ['days']);
        }

        $fromTime = $this->time($in['from_time'] ?? null, 'from_time');
        $toTime = $this->time($in['to_time'] ?? null, 'to_time');

        if (($fromTime === null) !== ($toTime === null) || ($fromTime !== null && $fromTime === $toTime)) {
            throw Refusal::invalid('Give both the hour it starts and the hour it ends, and make them differ.', ['from_time']);
        }

        $id = $this->ids->next();
        $actor = strtolower($actorId);
        $row = [
            'id' => $id, 'outlet_id' => $outlet['id'], 'item_id' => $item['id'], 'variant_id' => $variantId, 'channel' => $channel, 'kind' => $kind, 'name' => $name, 'price_minor' => $price, 'valid_from' => $from, 'valid_to' => $to,
            'days' => $days, 'from_time' => $fromTime, 'to_time' => $toTime, 'is_active' => true, 'created_by' => $actor,
        ];

        $this->transactions->run(function () use ($property, $actor, $row, $outlet, $item): void {
            $this->rules->add($property, $row, $this->clock->nowUtc());
            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_price_rule.created', 'fnb_price_rule', $row['id'], null, ['outlet' => $outlet['code'], 'item' => $item['code'], 'kind' => $row['kind'], 'channel' => $row['channel'], 'price_minor' => $row['price_minor'], 'valid_from' => $row['valid_from'], 'valid_to' => $row['valid_to']]));
        });

        return $this->overview($property, $actorId, $outlet['id']);
    }

    /** @return array<string, mixed> */
    public function retire(PropertyId $property, string $actorId, string $id, string $reason): array
    {
        $this->access->require($property, $actorId, FnbAccess::PRICES_MANAGE, 'This person may not set prices.');
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 200) {
            throw Refusal::invalid('Say why, in at most 200 characters.', ['reason']);
        }

        $rule = $this->rules->rule($property, strtolower($id)) ?? throw Refusal::notFound('Price not found.');
        $actor = strtolower($actorId);

        $this->transactions->run(function () use ($property, $actor, $rule, $reason): void {
            if (! $this->rules->retire($property, $rule['id'], $actor, $reason, $this->clock->nowUtc())) {
                throw Refusal::stateConflict('This price was retired already.');
            }

            $this->audit->record(new AuditEntry($property->toString(), $actor, 'fnb_price_rule.retired', 'fnb_price_rule', $rule['id'], ['name' => $rule['name'], 'price_minor' => (int) $rule['price_minor']], ['is_active' => false], $reason));
        });

        return $this->overview($property, $actorId, $rule['outlet_id']);
    }

    private function requireView(PropertyId $property, string $actorId): void
    {
        $this->access->assertProperty($property);

        if (! $this->access->may($property, $actorId, FnbAccess::PRICES_MANAGE) && ! $this->access->may($property, $actorId, FnbAccess::SETUP_MANAGE)) {
            throw Refusal::forbidden('This person may not see the prices.');
        }
    }

    private function local(PropertyId $property, DateTimeImmutable $at): DateTimeImmutable
    {
        $zone = $this->zones->forProperty($property);

        return $zone === null ? $at->setTimezone(new DateTimeZone('UTC')) : $zone->localize($at);
    }

    private function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);

        if ($d === false || $d->format('Y-m-d') !== (string) $value) {
            throw Refusal::invalid('Give a date as year-month-day.', [$field]);
        }

        return $d->format('Y-m-d');
    }

    private function time(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $t = DateTimeImmutable::createFromFormat('!H:i', substr((string) $value, 0, 5));

        if ($t === false || $t->format('H:i') !== substr((string) $value, 0, 5)) {
            throw Refusal::invalid('Give an hour as hours and minutes.', [$field]);
        }

        return $t->format('H:i:s');
    }
}
