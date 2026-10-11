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
    'gift_card_valid_months' => (int) env('GIFT_CARD_VALID_MONTHS', 12),
];
