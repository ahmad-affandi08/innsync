<?php

declare(strict_types=1);

namespace App\Shared\Domain\Time;

use InvalidArgumentException;

/** A wall-clock time that does not exist because clocks skip forward (daylight-saving gap). */
final class NonexistentLocalTime extends InvalidArgumentException
{
    public static function at(string $local, string $zone): self
    {
        return new self(sprintf('The local time %s does not exist in %s.', $local, $zone));
    }
}
