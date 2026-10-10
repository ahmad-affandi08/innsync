<?php

return [
    // E-mail the technician a work order was given to, and the supervisor or manager on duty when one passes its warning or its deadline. Off until the owner switches it on; the message names the number, priority and place only.
    'email_notice' => (bool) env('MAINTENANCE_EMAIL_NOTICE', false),
];
