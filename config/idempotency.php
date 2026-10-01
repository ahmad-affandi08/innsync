<?php

return [
    // Keep this stable across APP_KEY rotation so historical retries remain recognizable.
    'hash_key' => env('IDEMPOTENCY_HASH_KEY') ?: env('APP_KEY'),
];
