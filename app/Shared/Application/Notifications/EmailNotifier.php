<?php

declare(strict_types=1);

namespace App\Shared\Application\Notifications;

/**
 * A plain notice to one person by e-mail, for something that is waiting for them (a laundry order past its promised time). The address is personal data: the caller takes it from the identity module
 * only to send the notice and does not keep or log it. A notice that cannot be sent is answered with `false`, never thrown: it must not undo the business action it reports.
 */
interface EmailNotifier
{
    public function notify(string $address, string $subject, string $body): bool;
}
