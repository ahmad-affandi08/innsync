<?php

declare(strict_types=1);

/*
 * Retention catalogue (TASK-FND-019). Defaults and floors follow docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md,
 * a draft that counsel must confirm. A property may override `default_days` inside [minimum_days, maximum_days]
 * (`identity`: privacy.retention.manage); a statutory floor can never be lowered in the application.
 *
 * `anchor` says what the period is counted from, so the owning module knows which date to pass.
 */
return [
    'categories' => [
        'financial_record' => ['default_days' => 3650, 'minimum_days' => 3650, 'maximum_days' => null, 'statutory' => true, 'anchor' => 'transaction_date', 'purgeable' => false],
        'payroll_record' => ['default_days' => 3650, 'minimum_days' => 3650, 'maximum_days' => null, 'statutory' => true, 'anchor' => 'period_end', 'purgeable' => false],
        'audit_trail' => ['default_days' => 3650, 'minimum_days' => 3650, 'maximum_days' => null, 'statutory' => false, 'anchor' => 'occurred_at', 'purgeable' => false],
        'approval_evidence' => ['default_days' => 3650, 'minimum_days' => 3650, 'maximum_days' => null, 'statutory' => false, 'anchor' => 'decided_at', 'purgeable' => false],
        'security_event' => ['default_days' => 1825, 'minimum_days' => 365, 'maximum_days' => null, 'statutory' => false, 'anchor' => 'occurred_at', 'purgeable' => false],
        'guest_identity_document' => ['default_days' => 90, 'minimum_days' => 0, 'maximum_days' => 365, 'statutory' => false, 'anchor' => 'check_out_date', 'purgeable' => true],
        'hr_personnel_document' => ['default_days' => 1825, 'minimum_days' => 0, 'maximum_days' => 3650, 'statutory' => false, 'anchor' => 'employment_end_date', 'purgeable' => true],
        'lost_found_photo' => ['default_days' => 90, 'minimum_days' => 0, 'maximum_days' => 365, 'statutory' => false, 'anchor' => 'closed_at', 'purgeable' => true],
        'laundry_claim_photo' => ['default_days' => 365, 'minimum_days' => 0, 'maximum_days' => 1095, 'statutory' => false, 'anchor' => 'decided_at', 'purgeable' => true],
        'checklist_photo' => ['default_days' => 90, 'minimum_days' => 0, 'maximum_days' => 365, 'statutory' => false, 'anchor' => 'completed_at', 'purgeable' => true],
        'sensitive_export_file' => ['default_days' => 1, 'minimum_days' => 0, 'maximum_days' => 7, 'statutory' => false, 'anchor' => 'issued_at', 'purgeable' => true],
    ],

    // Internal response targets for data subject requests, in hours (UU 27/2022; see the baseline document).
    'request_due_hours' => [
        'access' => 72,
        'correction' => 24,
        'deletion' => 72,
        'withdraw_consent' => 72,
        'objection' => 72,
    ],

    // Local time of the daily purge run, after the nightly backup.
    'purge_at' => env('RETENTION_PURGE_AT', '03:30'),

    // Files erased per run and property; the command repeats daily so a backlog drains without long locks.
    'purge_batch_size' => (int) env('RETENTION_PURGE_BATCH', 200),
];
