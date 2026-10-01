<?php

return [
    // Dedicated local disk outside public/. Blobs are additionally encrypted by the application encrypter.
    'disk' => env('PRIVATE_FILES_DISK', 'private_files'),
];
