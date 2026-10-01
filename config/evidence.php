<?php

// Minimum retention stamped on immutable evidence (NFR-29). Defaults follow config/retention.php
// (docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md); the environment can only lengthen them in practice,
// because evidence is never deleted by the application.
return [
    'audit_minimum_retention_days' => env('AUDIT_MINIMUM_RETENTION_DAYS', 3650),
    'security_event_minimum_retention_days' => env('SECURITY_EVENT_MINIMUM_RETENTION_DAYS', 1825),
];
