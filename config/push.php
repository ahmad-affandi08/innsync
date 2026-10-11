<?php

declare(strict_types=1);

/*
 * Web push (docs/OPERATIONS/WEB-PUSH.md): a notice on the phone or computer of a person who allowed it on that device. The push service (Google, Mozilla, Apple) is only told "something is waiting" with no
 * words in it; the device then asks this server, behind the person's own sign-in, what it is. Off until the keys are made (`php artisan innsync:push-keys`) and put in the environment.
 */
return [
    // The private key (base64 of the PEM that `innsync:push-keys` prints) and a contact the push services can use if they must reach the sender.
    'vapid_private_key' => (string) env('VAPID_PRIVATE_KEY', ''),
    'vapid_subject' => (string) env('VAPID_SUBJECT', ''),

    // Only these push services are called (the address of a device is typed by a browser, and the server must never be sent to an address of a person's choosing). A leading dot means any subdomain.
    'allowed_hosts' => ['fcm.googleapis.com', '.push.apple.com', 'updates.push.services.mozilla.com', '.push.services.mozilla.com', '.notify.windows.com'],

    // How long a push service keeps a notice for a device that is off, in seconds, and how long a notice waits to be fetched by the device.
    'ttl_seconds' => (int) env('PUSH_TTL_SECONDS', 3600),
    'notice_days' => (int) env('PUSH_NOTICE_DAYS', 3),

    // At most this many devices for one person.
    'max_devices' => (int) env('PUSH_MAX_DEVICES', 5),
];
