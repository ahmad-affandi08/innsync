<?php

declare(strict_types=1);

return [
    'auto_reminders' => [
        // Reminders the system writes for the front desk (a tentative booking about to arrive, a hold about to lapse). They only remind; nothing is confirmed or released by itself.
        'enabled' => (bool) env('FRONTDESK_AUTO_REMINDERS', true),
        'days_ahead' => (int) env('FRONTDESK_AUTO_REMINDERS_DAYS', 2),
        'run_at' => (string) env('FRONTDESK_AUTO_REMINDERS_AT', '06:30'),
    ],
    // How long a gift card is good for when the sale does not say (baseline; the hotel's terms decide). Months from the day of the sale.
    // Day use: a room rented for some hours with no night in it (docs/OPERATIONS/DAY-USE.md). Times are on the property's clock, HH:MM.
    'day_use' => [
        // The first start and the last end of a day use, so the room is back before the evening.
        'earliest_start' => (string) env('DAY_USE_EARLIEST_START', '06:00'),
        'latest_end' => (string) env('DAY_USE_LATEST_END', '21:00'),
        // When a guest of the house leaves on that day, a day use of the same room starts no earlier than this.
        'after_checkout' => (string) env('DAY_USE_AFTER_CHECKOUT', '12:00'),
        // How many days ahead one can be booked.
        'max_advance_days' => (int) env('DAY_USE_MAX_ADVANCE_DAYS', 60),
        // The service charge and tax scheme the price is charged with (rooms, laundry or fnb); the price is before them.
        'charge_scope' => (string) env('DAY_USE_CHARGE_SCOPE', 'rooms'),
    ],
    'activities' => [
        // The scheme (service charge and tax) a spa or activity is priced with when it is charged to a room: rooms, laundry or fnb. Prices are quoted before service charge and tax.
        'charge_scope' => (string) env('ACTIVITIES_CHARGE_SCOPE', 'rooms'),
    ],
    'loyalty' => [
        // Points for the stays of returning guests (docs/OPERATIONS/LOYALTY.md). Off until the hotel decides to run a programme. Money is in the smallest unit (Rp 10.000 = 1000000).
        'enabled' => (bool) env('LOYALTY_ENABLED', false),
        // One point for every this much room revenue (before service charge and tax) charged to a stay.
        'earn_per_point_minor' => (int) env('LOYALTY_EARN_PER_POINT_MINOR', 1_000_000),
        // What one point is worth when turned into a voucher (Rp 100 by default, about one percent back).
        'point_value_minor' => (int) env('LOYALTY_POINT_VALUE_MINOR', 10_000),
        // The fewest points that can be turned into a voucher at a time.
        'min_redeem_points' => (int) env('LOYALTY_MIN_REDEEM_POINTS', 100),
        // Points of an account with no stay and no voucher for this many months expire (0 = never expire).
        'expire_months' => (int) env('LOYALTY_EXPIRE_MONTHS', 24),
        // Tiers by the points earned over all stays; each earns this percent more points on later stays. Set LOYALTY_TIERS to a JSON list of {"key", "from_points", "bonus_percent"} to change them, or to [] for none.
        'tiers' => json_decode((string) env('LOYALTY_TIERS', '[{"key":"silver","from_points":1000,"bonus_percent":5},{"key":"gold","from_points":5000,"bonus_percent":10},{"key":"platinum","from_points":15000,"bonus_percent":20}]'), true) ?? [],
    ],
    'gift_card_valid_months' => (int) env('GIFT_CARD_VALID_MONTHS', 12),
];
