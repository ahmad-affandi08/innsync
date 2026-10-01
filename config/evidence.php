<?php

return [
    // Q-15 remains unresolved. Null means retain indefinitely; never guess a deletion date.
    'audit_minimum_retention_days' => env('AUDIT_MINIMUM_RETENTION_DAYS'),
    'security_event_minimum_retention_days' => env('SECURITY_EVENT_MINIMUM_RETENTION_DAYS'),
];
