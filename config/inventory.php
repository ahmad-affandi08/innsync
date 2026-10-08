<?php

declare(strict_types=1);

return [
    'restock' => [
        // A daily draft purchase request for items below the minimum stock set per location. Drafts only: a person reads, changes and submits them.
        'enabled' => (bool) env('INVENTORY_RESTOCK_DRAFTS', true),
        'lead_days' => (int) env('INVENTORY_RESTOCK_LEAD_DAYS', 7),
        'run_at' => (string) env('INVENTORY_RESTOCK_AT', '06:45'),
    ],
];
