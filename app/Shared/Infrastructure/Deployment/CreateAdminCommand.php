<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Deployment;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
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
        $property = PropertyRecord::firstOrCreate(
            ['name' => $propertyName],
            ['timezone' => 'Asia/Jakarta', 'currency_code' => 'IDR'],
        );

        $this->info("Creating/updating admin user: {$email}...");
        $user = UserRecord::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        // Update password if user already existed
        if (! $user->wasRecentlyCreated) {
            $user->update([
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
        }

        $roleId = $this->administratorRole($property->id);
        $this->grantAllPermissions($property->id, $roleId);
        $this->assignUserToRole($property->id, $user->id, $roleId);
        $this->initializeBusinessDateIfNeeded($property->id, $user->id);

        $this->newLine();
        $this->info('✓ Administrator ready:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Property', $property->name],
                ['Email', $email],
                ['Password', $password],
                ['Role', 'Administrator (All Permissions)'],
            ]
        );

        return self::SUCCESS;
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
        $context = app(PropertyContext::class);
        $service = app(PropertySettingsService::class);

        $context->run($property, function () use ($service, $property, $userId): void {
            $settings = $service->get($property);

            if ($settings->businessDate !== null) {
                return;
            }

            $service->initializeBusinessDate(
                $property,
                $userId,
                now()->toDateString(),
                $settings->lockVersion,
                'Initial system go-live.',
            );
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
