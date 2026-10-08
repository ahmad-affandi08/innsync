<?php

return [
    'guest_subject' => 'We received your booking request :number',
    'guest_body' => "Hello :name,\n\nThank you for choosing :hotel. We received your request :number for :nights night(s), :arrival to :departure, for :adults adult(s) and :children child(ren).\n\nThis is a request, not yet a confirmed booking. The hotel will confirm it and contact you. You pay at the hotel; nothing was charged online.\n\n:notice\n\nIf you did not make this request, you can ignore this email.",
    'hotel_subject' => 'New online booking request :number',
    'hotel_body' => "A guest sent a booking request through your web page.\n\nNumber: :number\nGuest: :name\nPhone: :phone\nEmail: :email\nStay: :arrival to :departure (:nights night(s))\nGuests: :adults adult(s), :children child(ren)\nRequest: :notes\n\nIt is a tentative reservation. Open Front Office > Reservations to confirm it.",
];
