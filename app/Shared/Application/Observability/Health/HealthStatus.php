<?php

declare(strict_types=1);

namespace App\Shared\Application\Observability\Health;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';

    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Degraded => 1,
            self::Down => 2,
        };
    }

    public function worst(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }
}
