<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Real sign-in and property selection for feature tests that exercise the authenticated pipeline. */
trait SignsInToProperty
{
    private function createProperty(string $id, string $name = 'Hotel'): void
    {
        $property = new PropertyRecord(['name' => $name, 'timezone' => 'Asia/Jakarta', 'currency_code' => 'IDR']);
        $property->id = $id;
        $property->save();
    }

    /** @param list<string> $permissions */
    private function grant(UserRecord $user, string $propertyId, array $permissions, string $scopeType = 'property', ?string $scopeId = null): void
    {
        $roleId = strtolower((string) Str::ulid());

        DB::table('roles')->insert([
            'id' => $roleId, 'property_id' => $propertyId, 'name' => 'Role '.$roleId, 'requires_mfa' => false,
            'is_active' => true, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($permissions as $code) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            if ($permissionId === null) {
                $permissionId = strtolower((string) Str::ulid());
                DB::table('permissions')->insert(['id' => $permissionId, 'code' => $code, 'description' => null, 'created_at' => now(), 'updated_at' => now()]);
            }

            DB::table('role_permissions')->insert(['property_id' => $propertyId, 'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now()]);
        }

        DB::table('user_role_assignments')->insert([
            'id' => strtolower((string) Str::ulid()), 'property_id' => $propertyId, 'user_id' => $user->getKey(), 'role_id' => $roleId,
            'scope_type' => $scopeType, 'scope_id' => $scopeId ?? $propertyId, 'is_active' => true, 'lock_version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param list<string> $permissions */
    private function signIn(string $propertyId, array $permissions = []): UserRecord
    {
        $user = UserRecord::factory()->create();
        $this->grant($user, $propertyId, $permissions);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->post('/properties/select', ['property_id' => $propertyId])->assertRedirect('/');

        return $user;
    }
}
