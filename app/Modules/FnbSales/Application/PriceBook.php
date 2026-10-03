<?php

declare(strict_types=1);

namespace App\Modules\FnbSales\Application;

use DateTimeImmutable;

/**
 * The prices of an outlet at one moment (FR-FBS-015): the menu price, unless a price list or a promotion holds for the channel, the date, the day and the hour.
 *
 * When several rules hold, the more specific wins: one for the variant over one for the item, one for the channel over one for every channel, a promotion over a price
 * list, then the one that took effect last, then the newest. The outcome never depends on how many rules there are or in which order they were typed.
 */
final readonly class PriceBook
{
    public const CHANNELS = ['dine_in', 'room_service', 'takeaway'];

    /** @param list<array<string, mixed>> $rules the active rules of the outlet; `$at` is the moment on the property's wall clock */
    public function __construct(private array $rules, private DateTimeImmutable $at) {}

    /** The channel a bill is sold through. */
    public static function channelOf(?string $tableId, ?string $roomId): string
    {
        return $roomId !== null ? 'room_service' : ($tableId !== null ? 'dine_in' : 'takeaway');
    }

    /** @return array{0: int, 1: string|null} the unit price and the rule that set it (null: the menu price) */
    public function price(string $itemId, ?string $variantId, string $channel, int $listPrice): array
    {
        $best = null;

        foreach ($this->rules as $rule) {
            if (! $this->holds($rule, $itemId, $variantId, $channel)) {
                continue;
            }

            if ($best === null || $this->rank($rule) > $this->rank($best)) {
                $best = $rule;
            }
        }

        return $best === null ? [$listPrice, null] : [(int) $best['price_minor'], (string) $best['id']];
    }

    /** @param array<string, mixed> $rule */
    public function holds(array $rule, string $itemId, ?string $variantId, string $channel): bool
    {
        if (! (bool) $rule['is_active'] || $rule['item_id'] !== $itemId) {
            return false;
        }

        if ($rule['variant_id'] !== null && $rule['variant_id'] !== $variantId) {
            return false;
        }

        if ($rule['channel'] !== 'all' && $rule['channel'] !== $channel) {
            return false;
        }

        $from = $rule['from_time'] === null ? null : substr((string) $rule['from_time'], 0, 8);
        $to = $rule['to_time'] === null ? null : substr((string) $rule['to_time'], 0, 8);
        $time = $this->at->format('H:i:s');
        $day = $this->at;

        if ($from !== null && $to !== null) {
            if ($from < $to) {
                if ($time < $from || $time >= $to) {
                    return false;
                }
            } elseif ($time >= $from) {
                // Overnight window, before midnight: it is today's.
            } elseif ($time < $to) {
                // After midnight it still belongs to the day it started on.
                $day = $this->at->modify('-1 day');
            } else {
                return false;
            }
        }

        $date = $day->format('Y-m-d');

        if ($date < (string) $rule['valid_from'] || ($rule['valid_to'] !== null && $date > (string) $rule['valid_to'])) {
            return false;
        }

        return ((int) $rule['days'] & (1 << ((int) $day->format('N') - 1))) !== 0;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array{0: int, 1: int, 2: int, 3: string, 4: string}
     */
    private function rank(array $rule): array
    {
        return [$rule['variant_id'] !== null ? 1 : 0, $rule['channel'] !== 'all' ? 1 : 0, $rule['kind'] === 'promo' ? 1 : 0, (string) $rule['valid_from'], (string) $rule['id']];
    }
}
