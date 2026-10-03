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

    // A session may send this many requests and complaints in an hour.
    'requests_per_hour' => (int) env('GUEST_REQUESTS_PER_HOUR', 6),

    // The survey opens this many days before the day of departure, and on that day.
    'survey_days_before' => (int) env('GUEST_SURVEY_DAYS_BEFORE', 1),

    // An overall rating up to this opens a complaint at front office for someone to follow up.
    'survey_complaint_at_or_below' => (int) env('GUEST_SURVEY_COMPLAINT_AT_OR_BELOW', 2),

    // Self check-in (FR-GST-001 to FR-GST-007). Baselines: a link for a reservation lasts this many hours (never past the day of departure) and opens this many days before arrival; the code at the lobby lasts
    // this many days and hands out a link of this many minutes after the guest gives the reservation number and the name; that proof is limited as the stay proof is.
    'checkin' => [
        'link_hours' => (int) env('GUEST_CHECKIN_LINK_HOURS', 72),
        'open_days_before' => (int) env('GUEST_CHECKIN_OPEN_DAYS_BEFORE', 2),
        'lobby_code_days' => (int) env('GUEST_CHECKIN_LOBBY_CODE_DAYS', 90),
        'lobby_link_minutes' => (int) env('GUEST_CHECKIN_LOBBY_LINK_MINUTES', 60),
        'lookup_max_attempts' => (int) env('GUEST_CHECKIN_LOOKUP_MAX_ATTEMPTS', 5),
        'lookup_lock_minutes' => (int) env('GUEST_CHECKIN_LOOKUP_LOCK_MINUTES', 15),
        'arrivals_days' => (int) env('GUEST_CHECKIN_ARRIVALS_DAYS', 3),
        // What the guest is told about the key once a receptionist has checked them in; the receptionist may add a line of their own.
        'key_instructions' => [
            'id' => 'Ambil kartu kunci kamar Anda di meja resepsionis dengan menunjukkan identitas asli yang sama dengan yang Anda kirim.',
            'en' => 'Collect your room key card at the front desk and show the same identity document you sent.',
        ],
        // The notice shown until the hotel writes its own (version 0 is never stored; a consent to it records version 0 and the digest of these words).
        'privacy_baseline' => [
            'id' => 'Kami meminta nama, data identitas, foto identitas, alamat dan tanda tangan Anda untuk mendaftarkan Anda sebagai tamu sebagaimana diwajibkan hotel dan peraturan yang berlaku. Data ini hanya dilihat petugas resepsionis yang berwenang, disimpan sesuai masa retensi dokumen identitas tamu, dan tidak dibagikan kepada pihak lain kecuali diwajibkan hukum. Anda dapat meminta koreksi atau penghapusan data melalui resepsionis.',
            'en' => 'We ask for your name, identity details, identity photo, address and signature to register you as a guest as the hotel and the applicable regulations require. Only authorised front desk staff see this data; it is kept for the retention period of guest identity documents and not shared with anyone else unless the law requires it. You can ask the front desk to correct or erase your data.',
        ],
        // How to pay the deposit by QRIS, shown with the form.
        'qris_instructions' => [
            'id' => 'Bayar deposit dengan QRIS di meja resepsionis atau melalui kode QRIS hotel, lalu centang di bawah. Resepsionis memastikan dana diterima dan mencatatnya pada folio Anda.',
            'en' => 'Pay the deposit by QRIS at the front desk or with the hotel QRIS code, then tick below. The receptionist confirms the money arrived and records it on your folio.',
        ],
    ],
];
