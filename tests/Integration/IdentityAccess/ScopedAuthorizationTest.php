<?php

declare(strict_types=1);

namespace Tests\Integration\IdentityAccess;

use App\Modules\IdentityAccess\Application\Authorization\ScopedAuthorizer;
use App\Modules\IdentityAccess\Application\Ports\UserAccessReader;
use App\Modules\IdentityAccess\Application\Ports\UserSessionRepository;
use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class ScopedAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY_A = '01arz3ndektsv4rrffq69g5fav';

    private const PROPERTY_B = '01arz3ndektsv4rrffq69g5faw';

    private const OUTLET_A = '01arz3ndektsv4rrffq69g5fax';

    private const OUTLET_B = '01arz3ndektsv4rrffq69g5fay';

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql'
            || config('database.connections.mysql.database') !== 'innsync_test') {
            throw new LogicException('Integration tests may only reset the innsync_test MySQL database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createProperty(self::PROPERTY_A, 'Property A');
        $this->createProperty(self::PROPERTY_B, 'Property B');
    }

    public function test_property_grant_is_hierarchical_but_never_crosses_property(): void
    {
        $user = UserRecord::factory()->create();
        $this->grant($user, self::PROPERTY_A, 'property', self::PROPERTY_A, false);
        $authorizer = app(ScopedAuthorizer::class);

        self::assertTrue($authorizer->allows(
            (string) $user->getKey(),
            'front-office.reservation.view',
            self::PROPERTY_A,
        ));
        self::assertTrue($authorizer->allows(
            (string) $user->getKey(),
            'front-office.reservation.view',
            self::PROPERTY_A,
            'outlet',
            self::OUTLET_A,
        ));
        self::assertFalse($authorizer->allows(
            (string) $user->getKey(),
            'front-office.reservation.view',
            self::PROPERTY_B,
        ));
        self::assertFalse($authorizer->allows(
            (string) $user->getKey(),
            'finance.payment.refund',
            self::PROPERTY_A,
        ));
    }

    public function test_resource_grant_is_limited_to_its_exact_scope(): void
    {
        $user = UserRecord::factory()->create();
        $this->grant($user, self::PROPERTY_A, 'outlet', self::OUTLET_A, false);
        $authorizer = app(ScopedAuthorizer::class);

        self::assertTrue($authorizer->allows(
            (string) $user->getKey(),
            'front-office.reservation.view',
            self::PROPERTY_A,
            'outlet',
            self::OUTLET_A,
        ));
        self::assertFalse($authorizer->allows(
            (string) $user->getKey(),
            'front-office.reservation.view',
            self::PROPERTY_A,
            'outlet',
            self::OUTLET_B,
        ));
        self::assertFalse($authorizer->allows(
            (string) $user->getKey(),
            'front-office.reservation.view',
            self::PROPERTY_A,
        ));
    }

    public function test_access_profile_requires_mfa_from_role_configuration(): void
    {
        $user = UserRecord::factory()->create();
        $this->grant($user, self::PROPERTY_A, 'property', self::PROPERTY_A, true);
        $reader = app(UserAccessReader::class);

        self::assertTrue($reader->requiresMfa((string) $user->getKey()));
        self::assertTrue($reader->hasPropertyAccess((string) $user->getKey(), self::PROPERTY_A));
        self::assertFalse($reader->hasPropertyAccess((string) $user->getKey(), self::PROPERTY_B));
        self::assertSame(
            [self::PROPERTY_A],
            array_map(
                static fn ($property): string => $property->id,
                $reader->authorizedProperties((string) $user->getKey()),
            ),
        );
    }

    public function test_database_rejects_a_mismatched_property_scope_id(): void
    {
        $user = UserRecord::factory()->create();
        [$roleId] = $this->createRoleAndPermission(self::PROPERTY_A, false);

        $this->expectException(QueryException::class);

        DB::table('user_role_assignments')->insert([
            'id' => strtolower((string) Str::ulid()),
            'property_id' => self::PROPERTY_A,
            'user_id' => $user->getKey(),
            'role_id' => $roleId,
            'scope_type' => 'property',
            'scope_id' => self::PROPERTY_B,
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_session_revocation_is_owner_scoped(): void
    {
        $owner = UserRecord::factory()->create();
        $other = UserRecord::factory()->create();
        $sessions = app(UserSessionRepository::class);

        $this->insertSession('owner-session', (string) $owner->getKey());
        $this->insertSession('other-session', (string) $other->getKey());

        self::assertCount(1, $sessions->forUser((string) $owner->getKey()));
        self::assertFalse($sessions->revoke((string) $owner->getKey(), 'other-session'));
        self::assertTrue($sessions->revoke((string) $owner->getKey(), 'owner-session'));
        self::assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    /** @return array{string, string} */
    private function createRoleAndPermission(string $propertyId, bool $requiresMfa): array
    {
        $roleId = strtolower((string) Str::ulid());
        $permissionId = strtolower((string) Str::ulid());

        DB::table('roles')->insert([
            'id' => $roleId,
            'property_id' => $propertyId,
            'name' => 'Test role '.$roleId,
            'requires_mfa' => $requiresMfa,
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->insert([
            'id' => $permissionId,
            'code' => 'front-office.reservation.view',
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert([
            'property_id' => $propertyId,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
        ]);

        return [$roleId, $permissionId];
    }

    private function grant(
        UserRecord $user,
        string $propertyId,
        string $scopeType,
        string $scopeId,
        bool $requiresMfa,
    ): void {
        [$roleId] = $this->createRoleAndPermission($propertyId, $requiresMfa);

        DB::table('user_role_assignments')->insert([
            'id' => strtolower((string) Str::ulid()),
            'property_id' => $propertyId,
            'user_id' => $user->getKey(),
            'role_id' => $roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createProperty(string $id, string $name): void
    {
        $property = new PropertyRecord([
            'name' => $name,
            'timezone' => 'Asia/Jakarta',
            'currency_code' => 'IDR',
        ]);
        $property->id = $id;
        $property->save();
    }

    private function insertSession(string $id, string $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test desktop browser',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }
}
