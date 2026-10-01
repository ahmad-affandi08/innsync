<?php

declare(strict_types=1);

/*
 * UI language policy (TASK-FND-013, NFR-12, FR-GST-017). Indonesian and English
 * are supported; internal enum/database codes stay language-neutral. The default
 * locale is an operational setting (APP_LOCALE), not a guessed hotel policy, and
 * must be one of `supported` or the application refuses to resolve a locale.
 */
return [
    'supported' => ['id', 'en'],

    'default' => env('APP_LOCALE', 'en'),

    /** Session key holding the language chosen through the language switcher. */
    'session_key' => 'locale',
];
