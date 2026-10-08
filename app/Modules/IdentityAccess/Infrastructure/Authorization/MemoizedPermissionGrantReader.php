<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Infrastructure\Authorization;

use App\Modules\IdentityAccess\Application\Ports\PermissionGrantReader;
use App\Modules\IdentityAccess\Domain\Authorization\AccessScope;
use App\Modules\IdentityAccess\Domain\Authorization\PermissionCode;
use App\Shared\Domain\Tenancy\PropertyId;

/** The grant reader with the answers of one web request remembered (see `RequestGrantMemo`). Same questions, same answers; fewer round trips. */
final readonly class MemoizedPermissionGrantReader implements PermissionGrantReader
{
    public function __construct(private PermissionGrantReader $inner, private RequestGrantMemo $memo) {}

    public function allows(string $userId, PermissionCode $permission, AccessScope $scope): bool
    {
        return $this->memo->remember('allows|'.$userId.'|'.serialize($permission).'|'.serialize($scope), fn (): bool => $this->inner->allows($userId, $permission, $scope));
    }

    public function scopesOf(string $userId, PermissionCode $permission, PropertyId $property): array
    {
        return $this->memo->remember('scopes|'.$userId.'|'.serialize($permission).'|'.$property->toString(), fn (): array => $this->inner->scopesOf($userId, $permission, $property));
    }
}
