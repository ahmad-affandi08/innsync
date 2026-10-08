<?php

declare(strict_types=1);

return [
    'face' => [
        // How far apart two face descriptors may be to count as the same person (Euclidean distance of the 128 numbers the browser makes). 0.6 is the library's usual value;
        // 0.5 is stricter. A lower number refuses more of the right people, a higher one lets more of the wrong ones through.
        'max_distance' => (float) env('FACE_MAX_DISTANCE', 0.5),
        // Photos taken when a person is registered; all must look like one person.
        'samples' => 3,
    ],
];
