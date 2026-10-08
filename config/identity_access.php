<?php

return [
    'max_failed_login_attempts' => (int) env('AUTH_MAX_FAILED_ATTEMPTS', 5),
    'lockout_seconds' => (int) env('AUTH_LOCKOUT_SECONDS', 900),
    'login_rate_limit_per_minute' => (int) env('AUTH_LOGIN_RATE_LIMIT', 5),
    // E-mail the people who may decide an approval step that one is waiting. Off until the owner switches it on; the message names nothing.
    'approval_email_notice' => (bool) env('APPROVAL_EMAIL_NOTICE', false),
    'password_confirmation_timeout' => (int) env('AUTH_PASSWORD_CONFIRM_TIMEOUT', 900),
    'totp' => [
        'digits' => 6,
        'period_seconds' => 30,
        'allowed_window' => 1,
    ],
];
