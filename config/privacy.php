<?php

declare(strict_types=1);

/*
 * Field-level protection for personal data (NFR-07). The blind-index key is separate from the encryption key so that
 * rotating one does not silently break lookups made with the other; it falls back to the application key until set.
 */
return [
    'blind_index_key' => env('PRIVACY_BLIND_INDEX_KEY', env('APP_KEY', '')),
];
