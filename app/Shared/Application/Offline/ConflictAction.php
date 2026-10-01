<?php

declare(strict_types=1);

namespace App\Shared\Application\Offline;

/** What the person should do about a conflict; language-neutral, like the error envelope (TASK-FND-009). */
enum ConflictAction: string
{
    case Refresh = 'refresh';
    case Review = 'review';
    case Retry = 'retry';
}
