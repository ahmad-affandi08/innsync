<?php

return [
    'guest_subject' => 'We received your booking request :number',
    'guest_body' => "Hello :name,\n\nThank you for choosing :hotel. We received your request :number for :nights night(s), :arrival to :departure, for :adults adult(s) and :children child(ren).\n\nThis is a request, not yet a confirmed booking. The hotel will confirm it and contact you. You pay at the hotel; nothing was charged online.\n\n:notice\n\nIf you did not make this request, you can ignore this email.",
    'hotel_subject' => 'New online booking request :number',
    'hotel_body' => "A guest sent a booking request through your web page.\n\nNumber: :number\nGuest: :name\nPhone: :phone\nEmail: :email\nStay: :arrival to :departure (:nights night(s))\nGuests: :adults adult(s), :children child(ren)\nRequest: :notes\n\nIt is a tentative reservation. Open Front Office > Reservations to confirm it.",
    'pre_arrival_subject' => 'Your stay at :hotel is tomorrow',
    'pre_arrival_body' => "Hello :name,\n\nThis is a reminder that your booking :number at :hotel starts tomorrow, :arrival, and ends on :departure.\n\nWe look forward to welcoming you. If your plans changed, please reply to this email or contact the hotel.",
    'thank_you_subject' => 'Thank you for staying at :hotel',
    'thank_you_body' => "Hello :name,\n\nThank you for staying with us at :hotel (booking :number, :arrival to :departure). We hope you had a pleasant stay and would be glad to welcome you again.",
];
