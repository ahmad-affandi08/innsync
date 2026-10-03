<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\FnbSales;

use App\Modules\FnbSales\Application\PriceBook;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** FR-FBS-015: which price holds at a moment, for a channel, and which rule wins when several hold. */
final class PriceBookTest extends TestCase
{
    private const ITEM = '01arz3ndektsv4rrffq69g5fa1';

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function rule(string $id, array $over = []): array
    {
        return [...['id' => $id, 'item_id' => self::ITEM, 'variant_id' => null, 'channel' => 'all', 'kind' => 'price', 'price_minor' => 3_000_000, 'valid_from' => '2026-01-01', 'valid_to' => null, 'days' => 127, 'from_time' => null, 'to_time' => null, 'is_active' => true], ...$over];
    }

    private function book(array $rules, string $at): PriceBook
    {
        return new PriceBook($rules, new DateTimeImmutable($at));
    }

    public function test_without_a_rule_the_menu_price_stands(): void
    {
        self::assertSame([4_500_000, null], $this->book([], '2026-10-03 12:00:00')->price(self::ITEM, null, 'dine_in', 4_500_000));
    }

    public function test_a_rule_holds_only_between_its_dates_on_its_days_and_in_its_hours(): void
    {
        $happy = $this->rule('01arz3ndektsv4rrffq69g5fb1', ['kind' => 'promo', 'price_minor' => 2_000_000, 'valid_from' => '2026-10-01', 'valid_to' => '2026-10-31', 'days' => 0b0011111, 'from_time' => '16:00:00', 'to_time' => '18:00:00']);
        $price = static fn (string $at): int => (new PriceBook([$happy], new DateTimeImmutable($at)))->price(self::ITEM, null, 'dine_in', 4_500_000)[0];

        self::assertSame(2_000_000, $price('2026-10-02 17:00:00'), 'a Friday in the window');
        self::assertSame(4_500_000, $price('2026-10-02 18:00:00'), 'the end of the window is outside it');
        self::assertSame(2_000_000, $price('2026-10-02 16:00:00'), 'its start is inside');
        self::assertSame(4_500_000, $price('2026-10-03 17:00:00'), 'a Saturday is not a chosen day');
        self::assertSame(4_500_000, $price('2026-11-02 17:00:00'), 'after the last day');
        self::assertSame(4_500_000, $price('2026-09-30 17:00:00'), 'before the first day');
    }

    public function test_a_window_over_midnight_belongs_to_the_day_it_started_on(): void
    {
        $late = $this->rule('01arz3ndektsv4rrffq69g5fb2', ['price_minor' => 3_500_000, 'days' => 0b0010000, 'from_time' => '22:00:00', 'to_time' => '02:00:00']); // Fridays only
        $price = fn (string $at): int => $this->book([$late], $at)->price(self::ITEM, null, 'dine_in', 4_500_000)[0];

        self::assertSame(3_500_000, $price('2026-10-02 23:00:00'), 'Friday night');
        self::assertSame(3_500_000, $price('2026-10-03 01:00:00'), 'the small hours of Saturday still belong to Friday');
        self::assertSame(4_500_000, $price('2026-10-03 23:00:00'), 'Saturday night is not a Friday');
        self::assertSame(4_500_000, $price('2026-10-04 01:00:00'), 'the small hours of Sunday belong to Saturday');
        self::assertSame(4_500_000, $price('2026-10-02 12:00:00'), 'noon is outside the window');
    }

    public function test_the_more_specific_rule_wins_whatever_the_order_they_were_typed_in(): void
    {
        $all = $this->rule('01arz3ndektsv4rrffq69g5fb3', ['price_minor' => 4_000_000]);
        $channel = $this->rule('01arz3ndektsv4rrffq69g5fb4', ['channel' => 'room_service', 'price_minor' => 5_000_000]);
        $promo = $this->rule('01arz3ndektsv4rrffq69g5fb5', ['kind' => 'promo', 'price_minor' => 3_500_000]);
        $variant = $this->rule('01arz3ndektsv4rrffq69g5fb6', ['variant_id' => '01arz3ndektsv4rrffq69g5fv1', 'price_minor' => 6_000_000]);

        foreach ([[$all, $channel, $promo, $variant], [$variant, $promo, $channel, $all]] as $rules) {
            $book = $this->book($rules, '2026-10-02 12:00:00');
            self::assertSame([3_500_000, $promo['id']], $book->price(self::ITEM, null, 'dine_in', 4_500_000), 'a promotion beats a price list');
            self::assertSame([5_000_000, $channel['id']], $book->price(self::ITEM, null, 'room_service', 4_500_000), 'the channel beats every channel');
            self::assertSame([6_000_000, $variant['id']], $book->price(self::ITEM, '01arz3ndektsv4rrffq69g5fv1', 'dine_in', 4_500_000), 'the variant beats the item');
            self::assertSame([3_500_000, $promo['id']], $book->price(self::ITEM, '01arz3ndektsv4rrffq69g5fv2', 'takeaway', 4_500_000), 'a rule for another variant does not hold');
        }
    }

    public function test_the_rule_that_took_effect_last_wins_among_equals_and_a_retired_rule_never_holds(): void
    {
        $old = $this->rule('01arz3ndektsv4rrffq69g5fb7', ['price_minor' => 4_000_000, 'valid_from' => '2026-01-01']);
        $new = $this->rule('01arz3ndektsv4rrffq69g5fb8', ['price_minor' => 4_200_000, 'valid_from' => '2026-09-01']);
        $retired = $this->rule('01arz3ndektsv4rrffq69g5fb9', ['price_minor' => 1_000_000, 'valid_from' => '2026-10-01', 'is_active' => false]);

        self::assertSame(4_200_000, $this->book([$old, $new, $retired], '2026-10-02 12:00:00')->price(self::ITEM, null, 'dine_in', 4_500_000)[0]);
        self::assertSame(4_000_000, $this->book([$old, $new], '2026-08-15 12:00:00')->price(self::ITEM, null, 'dine_in', 4_500_000)[0]);
        self::assertSame(['dine_in', 'room_service', 'takeaway'], [PriceBook::channelOf('t', null), PriceBook::channelOf(null, 'r'), PriceBook::channelOf(null, null)]);
    }
}
