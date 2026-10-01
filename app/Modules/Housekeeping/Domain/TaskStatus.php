<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Domain;

enum TaskStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Assigned, self::InProgress], true);
    }
}
