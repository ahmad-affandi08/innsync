<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\IdentityAccess\Infrastructure\Persistence\Eloquent\UserRecord;
use App\Modules\Property\Application\Settings\PropertySettingsService;
use App\Modules\Property\Infrastructure\Persistence\Eloquent\PropertyRecord;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Domain\Tenancy\PropertyId;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local sandbox data only: a demo property, a dev user, and an
 * Administrator role granted every permission code currently declared in
 * the codebase (harvested from the modules' own `*_PERMISSION` constants,
 * not a separate catalog -- none exists, see RoleRecord/PermissionRecord).
 * Never runs outside local/testing, and never part of the deploy pipeline
 * (see docs/RULES/06-DATABASE-RULES.md: seeders are reference/dev data).
 */
final class DevelopmentSeeder extends Seeder
{
    private const DEV_EMAIL = 'dev@innsync.test';

    private const DEV_PASSWORD = 'Password123!';

    private const PROPERTY_NAME = 'Demo Hotel';

    private const ROLE_NAME = 'Administrator';

    /** @var list<string> */
    private const PERMISSIONS = [
        'finance.account.manage',
        'finance.audit.view',
        'finance.budget.manage',
        'finance.correction.approve',
        'finance.export',
        'finance.payable.manage',
        'finance.payable.view',
        'finance.payment.record',
        'finance.payment.reverse',
        'finance.petty.manage',
        'finance.petty.operate',
        'finance.petty.view',
        'finance.receipt.record',
        'finance.receipt.reverse',
        'finance.receivable.adjust',
        'finance.receivable.manage',
        'finance.receivable.view',
        'finance.reconcile.manage',
        'finance.recurring.manage',
        'finance.recurring.view',
        'finance.report.view',
        'finance.revenue.view',
        'front-office.availability.view',
        'front-office.cashier.manage',
        'front-office.cashier.operate',
        'front-office.cashier.settings',
        'front-office.cashier.view',
        'front-office.company.link',
        'front-office.company.manage',
        'front-office.company.view',
        'front-office.feedback.manage',
        'front-office.feedback.view',
        'front-office.folio.correct',
        'front-office.folio.manage',
        'front-office.folio.refund',
        'front-office.folio.view',
        'front-office.foreign-payment.settings',
        'front-office.group.manage',
        'front-office.group.view',
        'front-office.guarantee.override',
        'front-office.guest-contact.view',
        'front-office.guest-identity.view',
        'front-office.guest.correct',
        'front-office.hold.manage',
        'front-office.late-charge.post',
        'front-office.logbook.read',
        'front-office.logbook.write',
        'front-office.night-audit.run',
        'front-office.night-audit.view',
        'front-office.night-audit.waive',
        'front-office.overbooking.manage',
        'front-office.overbooking.override',
        'front-office.penalty.waive',
        'front-office.rate.change',
        'front-office.registration.terms',
        'front-office.request.manage',
        'front-office.request.view',
        'front-office.reservation.manage',
        'front-office.reservation.view',
        'front-office.room-block.manage',
        'front-office.sop.manage',
        'front-office.sop.perform',
        'front-office.sop.view',
        'front-office.stay-fee.apply',
        'front-office.stay-fee.policy',
        'front-office.stay-fee.waive',
        'front-office.stay.manage',
        'front-office.stay.view',
        'housekeeping.checklist.manage',
        'housekeeping.checklist.perform',
        'housekeeping.checklist.view',
        'housekeeping.inspection.perform',
        'housekeeping.inspection.waive',
        'housekeeping.linen.manage',
        'housekeeping.lostfound.manage',
        'housekeeping.lostfound.record',
        'housekeeping.par.manage',
        'housekeeping.settings.manage',
        'housekeeping.task.manage',
        'housekeeping.task.perform',
        'housekeeping.view',
        'identity.approval-policy.manage',
        'inventory.catalog.manage',
        'inventory.catalog.view',
        'inventory.count.approve',
        'inventory.count.manage',
        'inventory.stock.adjust',
        'inventory.stock.negative',
        'inventory.stock.post',
        'inventory.stock.view',
        'inventory.transfer.receive',
        'inventory.transfer.send',
        'inventory.valuation.view',
        'laundry.claim.approve',
        'laundry.claim.record',
        'laundry.claim.view',
        'laundry.linen.handle',
        'laundry.order.cancel',
        'laundry.order.deliver',
        'laundry.order.intake',
        'laundry.order.process',
        'laundry.prices.manage',
        'laundry.view',
        'property.catalog.manage',
        'property.catalog.view',
        'property.policies.manage',
        'property.policies.view',
        'property.rates.manage',
        'property.rates.view',
        'property.settings.manage',
        'property.tax.manage',
        'property.tax.view',
        'purchasing.budget.manage',
        'purchasing.invoice.manage',
        'purchasing.invoice.resolve',
        'purchasing.order.manage',
        'purchasing.order.view',
        'purchasing.quote.manage',
        'purchasing.receipt.post',
        'purchasing.report.view',
        'purchasing.request.create',
        'purchasing.request.view',
        'purchasing.return.post',
        'purchasing.supplier.manage',
        'purchasing.supplier.rate',
        'purchasing.supplier.view',
        'reporting.audit.view',
        'reporting.builder.use',
        'reporting.dashboard.view',
        'reporting.guests.export',
        'reporting.guests.view',
        'reporting.housekeeping.view',
        'reporting.obligations.manage',
        'reporting.obligations.view',
        'reporting.outlets.manage',
        'reporting.report.view',
        'reporting.revenue.view',
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $property = PropertyRecord::firstOrCreate(
            ['name' => self::PROPERTY_NAME],
            ['timezone' => 'Asia/Jakarta', 'currency_code' => 'IDR'],
        );

        $user = UserRecord::firstOrCreate(
            ['email' => self::DEV_EMAIL],
            [
                'name' => 'Dev Admin',
                'password' => Hash::make(self::DEV_PASSWORD),
                'email_verified_at' => now(),
            ],
        );

        $roleId = $this->administratorRole($property->id);
        $this->grantAllPermissions($property->id, $roleId);
        $this->assignUserToRole($property->id, $user->id, $roleId);
        $this->initializeBusinessDateIfNeeded($property->id, $user->id);

        $this->command?->info(sprintf(
            'Dev login ready: %s / %s (property: %s)',
            self::DEV_EMAIL,
            self::DEV_PASSWORD,
            self::PROPERTY_NAME,
        ));
    }

    private function administratorRole(string $propertyId): string
    {
        $existing = DB::table('roles')
            ->where('property_id', $propertyId)
            ->where('name', self::ROLE_NAME)
            ->value('id');

        if ($existing !== null) {
            return $existing;
        }

        $roleId = strtolower((string) Str::ulid());

        DB::table('roles')->insert([
            'id' => $roleId,
            'property_id' => $propertyId,
            'name' => self::ROLE_NAME,
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
        $existingCodes = DB::table('permissions')
            ->whereIn('code', self::PERMISSIONS)
            ->pluck('id', 'code');

        $missing = array_diff(self::PERMISSIONS, $existingCodes->keys()->all());

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
                ->whereIn('code', self::PERMISSIONS)
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

    /**
     * Go-live is a one-time, audited, reasoned action (BR-001) -- run through
     * the real application service inside a bound PropertyContext rather than
     * writing the settings row by hand, so the domain invariants and audit
     * trail stay exactly as they are for a real property.
     */
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
                'Local development sandbox go-live.',
            );
        });
    }
}
