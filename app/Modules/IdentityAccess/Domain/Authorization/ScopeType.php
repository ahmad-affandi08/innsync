<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Domain\Authorization;

enum ScopeType: string
{
    case Property = 'property';
    case Outlet = 'outlet';
    case Department = 'department';
}
