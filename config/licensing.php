<?php

declare(strict_types=1);

return [
    /*
    | How many properties this installation may hold, set by the vendor per agreement (one purchase is normally one property). 0 means no limit,
    | which is the default: nothing is closed until the agreement says so. The limit is read from the server's environment, not from a screen, so
    | the hotel's own administrators cannot raise it.
    */
    'max_properties' => max(0, (int) env('INNSYNC_MAX_PROPERTIES', 0)),
];
