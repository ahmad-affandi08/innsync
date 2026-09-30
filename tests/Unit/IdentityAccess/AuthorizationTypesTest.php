<?php

declare(strict_types=1);

namespace Tests\Unit\IdentityAccess;

use App\Modules\IdentityAccess\Domain\Authorization\AccessScope;
use App\Modules\IdentityAccess\Domain\Authorization\PermissionCode;
use App\Modules\IdentityAccess\Domain\Authorization\ScopeType;
use App\Shared\Domain\Tenancy\PropertyId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthorizationTypesTest extends TestCase
{
    #[DataProvider('invalidPermissionCodes')]
    public function test_permission_code_rejects_ambiguous_or_unsafe_values(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);

        PermissionCode::fromString($code);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPermissionCodes(): iterable
    {
        yield 'module only' => ['front-office'];
        yield 'uppercase' => ['FrontOffice.View'];
        yield 'whitespace' => ['front-office. view'];
        yield 'wildcard' => ['front-office.*'];
    }

    public function test_resource_scope_requires_a_ulid_and_cannot_impersonate_property_scope(): void
    {
        $propertyId = PropertyId::fromString('01arz3ndektsv4rrffq69g5fav');
        $scope = AccessScope::resource(
            $propertyId,
            ScopeType::Outlet,
            '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        );

        self::assertSame('01arz3ndektsv4rrffq69g5faw', $scope->scopeId());

        $this->expectException(InvalidArgumentException::class);

        AccessScope::resource($propertyId, ScopeType::Property, $propertyId->toString());
    }
}
