<?php

declare(strict_types=1);

/*
 * External integration settings (TASK-FND-020, NFR-25, docs/OPERATIONS/INTEGRATION-CONVENTIONS.md).
 *
 * No provider is configured yet: the payment gateway (PRD Q-04, Q-16), accounting software (Q-07), messaging and the
 * channel manager are undecided, so none is guessed. A provider is added here as
 *
 *   'qris' => [
 *       'property_id' => '<ULID of the property it belongs to>',
 *       'webhook_secrets' => [env('QRIS_WEBHOOK_SECRET'), env('QRIS_WEBHOOK_SECRET_PREVIOUS')],  // rotation: new first
 *       'webhook_protocol' => null,                       // class name when the provider has its own signature scheme
 *       'connect_timeout_seconds' => 3, 'read_timeout_seconds' => 10, 'failure_threshold' => 5, 'open_seconds' => 60,
 *   ],
 *
 * Secrets live only in the environment; never in Git or logs (NFR-23).
 */
return [
    'defaults' => [
        'connect_timeout_seconds' => 3,
        // Below the shared-hosting request limit so a slow provider cannot hold a worker past it.
        'read_timeout_seconds' => 10,
        'failure_threshold' => 5,
        'open_seconds' => 60,
    ],

    'webhooks' => [
        'max_body_bytes' => 262144,
        'tolerance_seconds' => 300,
    ],

    // An unknown outcome open this long turns the `integrations` health check down (payment unknown, NFR-20).
    'unknown_down_hours' => (int) env('INTEGRATION_UNKNOWN_DOWN_HOURS', 4),

    'providers' => [],
];
