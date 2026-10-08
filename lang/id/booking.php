<?php

return [
    'guest_subject' => 'Permintaan pemesanan Anda :number sudah kami terima',
    'guest_body' => "Halo :name,\n\nTerima kasih telah memilih :hotel. Kami menerima permintaan Anda :number untuk :nights malam, :arrival sampai :departure, untuk :adults dewasa dan :children anak.\n\nIni masih permintaan, belum pemesanan yang dikonfirmasi. Hotel akan mengonfirmasi dan menghubungi Anda. Pembayaran dilakukan di hotel; belum ada yang ditagih secara daring.\n\n:notice\n\nJika bukan Anda yang membuat permintaan ini, abaikan email ini.",
    'hotel_subject' => 'Permintaan pemesanan daring baru :number',
    'hotel_body' => "Seorang tamu mengirim permintaan pemesanan lewat halaman web Anda.\n\nNomor: :number\nTamu: :name\nTelepon: :phone\nEmail: :email\nMenginap: :arrival sampai :departure (:nights malam)\nTamu: :adults dewasa, :children anak\nPermintaan: :notes\n\nIni reservasi tentatif. Buka Front Office > Reservasi untuk mengonfirmasinya.",
    'pre_arrival_subject' => 'Menginap di :hotel besok',
    'pre_arrival_body' => "Halo :name,\n\nIni pengingat bahwa pemesanan Anda :number di :hotel dimulai besok, :arrival, dan berakhir pada :departure.\n\nKami menantikan kedatangan Anda. Bila rencana Anda berubah, balas email ini atau hubungi hotel.",
    'thank_you_subject' => 'Terima kasih telah menginap di :hotel',
    'thank_you_body' => "Halo :name,\n\nTerima kasih telah menginap di :hotel (pemesanan :number, :arrival sampai :departure). Semoga Anda nyaman, dan kami senang bila dapat menyambut Anda kembali.",
];
