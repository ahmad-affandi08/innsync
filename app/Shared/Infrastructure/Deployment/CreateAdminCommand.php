<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Deployment;

use App\Shared\Application\Deployment\GoLiveBusinessDate;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class CreateAdminCommand extends Command
{
    protected $signature = 'innsync:create-admin
        {--email= : Admin email}
        {--password= : Admin password}
        {--name=Admin : Admin display name}
        {--property=InnSYnc Hotel : Property name}';

    protected $description = 'Create or initialize the administrator user and property';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Admin email', 'admin@innsync.my.id'));
        $password = (string) ($this->option('password') ?: $this->secret('Admin password'));
        if ($password === '') {
            $password = 'Admin12345!';
            $this->warn("No password provided, using default: {$password}");
        }

        $name = (string) ($this->option('name') ?: 'Administrator');
        $propertyName = (string) ($this->option('property') ?: 'InnSYnc Hotel');

        $this->info("Creating/updating property: {$propertyName}...");
        $propertyId = $this->property($propertyName);

        $this->info("Creating/updating admin user: {$email}...");
        $userId = $this->user($email, $name, $password);

        $roleId = $this->administratorRole($propertyId);
        $this->grantAllPermissions($propertyId, $roleId);
        $this->assignUserToRole($propertyId, $userId, $roleId);
        $this->call('innsync:install-default-roles', ['--property' => $propertyId]);
        $this->call('innsync:install-default-approvals', ['--property' => $propertyId]);
        $this->initializeBusinessDateIfNeeded($propertyId, $userId);

        $this->newLine();
        $this->info('✓ Administrator ready:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Property', $propertyName],
                ['Email', $email],
                ['Password', $password],
                ['Role', 'Administrator (All Permissions)'],
            ]
        );

        return self::SUCCESS;
    }

    private function property(string $name): string
    {
        $existing = DB::table('properties')->where('name', $name)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = strtolower((string) Str::ulid());

        DB::table('properties')->insert([
            'id' => $id,
            'name' => $name,
            'timezone' => 'Asia/Jakarta',
            'currency_code' => 'IDR',
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function user(string $email, string $name, string $password): string
    {
        $existing = DB::table('users')->where('email', $email)->first(['id', 'email_verified_at']);

        if ($existing !== null) {
            DB::table('users')->where('id', $existing->id)->update([
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => $existing->email_verified_at ?? now(),
                'updated_at' => now(),
            ]);

            return (string) $existing->id;
        }

        $id = strtolower((string) Str::ulid());

        DB::table('users')->insert([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function administratorRole(string $propertyId): string
    {
        $existing = DB::table('roles')
            ->where('property_id', $propertyId)
            ->where('name', 'Administrator')
            ->value('id');

        if ($existing !== null) {
            return $existing;
        }

        $roleId = strtolower((string) Str::ulid());

        DB::table('roles')->insert([
            'id' => $roleId,
            'property_id' => $propertyId,
            'name' => 'Administrator',
            'requires_mfa' => false,
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $roleId;
    }

    private function grantAllPermissions(string $propertyId, string $roleId): void
    {
        $permissions = $this->harvestPermissions();

        $existingCodes = DB::table('permissions')
            ->whereIn('code', $permissions)
            ->pluck('id', 'code');

        $missing = array_diff($permissions, $existingCodes->keys()->all());

        if ($missing !== []) {
            DB::table('permissions')->insert(array_map(
                static fn (string $code): array => [
                    'id' => strtolower((string) Str::ulid()),
                    'code' => $code,
                    'description' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                $missing,
            ));

            $existingCodes = DB::table('permissions')
                ->whereIn('code', $permissions)
                ->pluck('id', 'code');
        }

        DB::table('role_permissions')->insertOrIgnore(
            $existingCodes->map(static fn (string $permissionId): array => [
                'property_id' => $propertyId,
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
            ])->values()->all(),
        );
    }

    private function assignUserToRole(string $propertyId, string $userId, string $roleId): void
    {
        DB::table('user_role_assignments')->insertOrIgnore([[
            'id' => strtolower((string) Str::ulid()),
            'property_id' => $propertyId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => 'property',
            'scope_id' => $propertyId,
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
    }

    private function initializeBusinessDateIfNeeded(string $propertyId, string $userId): void
    {
        $property = PropertyId::fromString($propertyId);
        $goLive = app(GoLiveBusinessDate::class);

        app(PropertyContext::class)->run($property, function () use ($goLive, $property, $userId): void {
            $goLive->initializeIfMissing($property, $userId, now()->toDateString(), 'Initial system go-live.');
        });
    }

    /**
     * @return list<string>
     */
    private function harvestPermissions(): array
    {
        // Read constants from DevelopmentSeeder using reflection to guarantee 100% parity
        $ref = new \ReflectionClass(DevelopmentSeeder::class);

        /** @var list<string> */
        return $ref->getConstant('PERMISSIONS') ?: [];
    }
}
