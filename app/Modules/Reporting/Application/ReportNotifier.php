<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

/** How a person is told that a scheduled report is ready. The notice never carries figures; it points at the exports page. */
interface ReportNotifier
{
    /** @return bool whether the notice was handed to the mail system */
    public function notify(string $address, string $subject, string $body): bool;
}
