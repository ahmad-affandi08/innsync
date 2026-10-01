<?php

// Backups are fail-closed: nothing runs until a destination outside the application tree and a
// dedicated encryption key (never APP_KEY, so a restore does not depend on the live app secret) are set.
return [
    // Absolute path on separate storage (another mount or synced folder). Must be outside base_path().
    'path' => env('BACKUP_PATH'),

    // base64 of 32 random bytes; generate with `php artisan backup:keygen`. Store a copy off-server.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
    'mysql_binary' => env('BACKUP_MYSQL_BINARY', 'mysql'),

    // Scratch database for restore tests. Must end in `_restore_test`; its tables are wiped each run.
    'restore_test_database' => env('BACKUP_RESTORE_TEST_DATABASE'),

    // Daily sets kept (docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md): 35 means about five weeks, so personal data
    // erased from the live system also leaves the backups within that window. Set to an empty value to keep every set.
    'keep_last' => env('BACKUP_KEEP_LAST', 35) === '' ? null : max(1, (int) env('BACKUP_KEEP_LAST', 35)),

    // Schedule (application timezone). Daily full backup per NFR-11; restore test weekly.
    'run_at' => env('BACKUP_RUN_AT', '02:00'),
    'verify_at' => env('BACKUP_VERIFY_AT', '03:30'),

    // Health thresholds (operational defaults, tune with the owner).
    'max_age_hours_degraded' => max(1, (int) env('BACKUP_MAX_AGE_HOURS_DEGRADED', 26)),
    'max_age_hours_down' => max(1, (int) env('BACKUP_MAX_AGE_HOURS_DOWN', 50)),
    'restore_test_max_age_days' => max(1, (int) env('BACKUP_RESTORE_TEST_MAX_AGE_DAYS', 35)),

    // Evidence tables are append-only, so their restored row count must fall inside the dump window.
    'append_only_tables' => ['audit_entries', 'security_events', 'stored_files'],
];
