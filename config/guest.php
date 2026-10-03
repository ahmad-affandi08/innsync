<?php

declare(strict_types=1);

/*
 * Guest self-service (FR-GST). Baselines, not hotel policy: the owner can change them in the environment.
 * A code scanned opens a session on that code only; a session ends by itself, and proving a stay is limited so the surname of a guest cannot be guessed.
 */
return [
    // How long a session lasts after the code was scanned, in minutes. A table is a meal; a room is a stay's day.
    'session_minutes' => ['table' => (int) env('GUEST_SESSION_TABLE_MINUTES', 240), 'room' => (int) env('GUEST_SESSION_ROOM_MINUTES', 720)],

    // Wrong room number or surname: this many tries, then the session cannot try again for this many minutes.
    'verify_max_attempts' => (int) env('GUEST_VERIFY_MAX_ATTEMPTS', 5),
    'verify_lock_minutes' => (int) env('GUEST_VERIFY_LOCK_MINUTES', 15),

    // One order may carry this many lines and portions of a line, and one session may place this many orders in an hour.
    'order_max_lines' => (int) env('GUEST_ORDER_MAX_LINES', 30),
    'order_max_quantity' => (int) env('GUEST_ORDER_MAX_QUANTITY', 20),
    'orders_per_hour' => (int) env('GUEST_ORDERS_PER_HOUR', 6),
];
