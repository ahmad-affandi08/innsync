<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Domain;

/** Why a room needs servicing. The order of work follows FR-HK-003: departures, then vacant dirty rooms, guest requests, stay-overs. */
enum TaskKind: string
{
    case Departure = 'departure';
    case Vacant = 'vacant';
    case Request = 'request';
    case Stayover = 'stayover';
    case Rework = 'rework';

    /** Lower is more urgent. A failed inspection comes before everything: the room was already promised. */
    public function priority(): int
    {
        return match ($this) {
            self::Rework => 1,
            self::Departure => 2,
            self::Vacant => 3,
            self::Request => 4,
            self::Stayover => 5,
        };
    }
}
