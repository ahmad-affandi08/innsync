<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Application\Access;

/**
 * The roles every property starts with (owner instruction 2026-10-07: less set-up at the start; the roles stay editable).
 *
 * Each role lists permission patterns: an exact code, `prefix.*` for every code that starts with `prefix.`, `*` for every code, and `!pattern` to take
 * matching codes out again. The roles follow the people named in the PRD (Receptionist, Manager on Duty, Night Auditor, the heads of housekeeping, kitchen,
 * maintenance, purchasing, finance and HR) and give each only what the work needs. No role here may manage users or roles: that stays with the
 * Administrator, who creates the accounts.
 *
 * A property gets a role only when it has none of that name, so a role the property already changed is never overwritten.
 */
final class DefaultRoles
{
    private const NO_ACCESS_ADMIN = ['!identity.user.manage', '!identity.role.manage'];

    /** @return array<string, list<string>> role name => permission patterns */
    public static function all(): array
    {
        return [
            'General Manager' => ['*', ...self::NO_ACCESS_ADMIN],
            'Owner (read only)' => ['*.view', 'reporting.builder.use', 'reporting.guests.view', '!finance.audit.view'],
            'Front Office Manager' => [
                'front-office.*', 'guest.*', 'property.*.view', 'reporting.dashboard.view', 'reporting.report.view', 'reporting.revenue.view', 'reporting.guests.view',
                'housekeeping.view', 'laundry.view', 'finance.receivable.view', 'offline.reconcile',
            ],
            'Receptionist' => [
                'front-office.availability.view', 'front-office.cashier.operate', 'front-office.cashier.view', 'front-office.company.view', 'front-office.feedback.manage',
                'front-office.feedback.view', 'front-office.folio.manage', 'front-office.folio.view', 'front-office.group.view', 'front-office.guest-contact.view',
                'front-office.guest-identity.view', 'front-office.hold.manage', 'front-office.late-charge.post', 'front-office.logbook.read', 'front-office.logbook.write',
                'front-office.night-audit.view', 'front-office.registration.terms', 'front-office.request.manage', 'front-office.request.view', 'front-office.reservation.manage',
                'front-office.reservation.view', 'front-office.sop.perform', 'front-office.sop.view', 'front-office.stay-fee.apply', 'front-office.stay.manage', 'front-office.stay.view',
                'guest.checkin.manage', 'guest.qr.manage', 'property.catalog.view', 'property.policies.view', 'property.rates.view', 'housekeeping.view', 'laundry.view',
                'laundry.order.intake', 'fnb.minibar.operate',
            ],
            'Night Auditor' => [
                'front-office.night-audit.run', 'front-office.night-audit.view', 'front-office.cashier.view', 'front-office.cashier.operate', 'front-office.folio.view',
                'front-office.stay.view', 'front-office.reservation.view', 'front-office.logbook.read', 'front-office.logbook.write', 'front-office.availability.view',
                'reporting.dashboard.view', 'reporting.revenue.view', 'finance.revenue.view',
            ],
            'Housekeeping Supervisor' => [
                'housekeeping.*', 'laundry.view', 'laundry.order.intake', 'laundry.linen.handle', 'maintenance.work.report', 'front-office.availability.view',
                'front-office.logbook.read', 'front-office.logbook.write', 'inventory.requisition.request', 'inventory.stock.view', 'reporting.housekeeping.view',
            ],
            'Room Attendant' => [
                'housekeeping.view', 'housekeeping.task.perform', 'housekeeping.checklist.perform', 'housekeeping.checklist.view', 'housekeeping.supplies.use',
                'housekeeping.lostfound.record', 'maintenance.work.report', 'laundry.linen.handle',
            ],
            'Laundry Staff' => [
                'laundry.view', 'laundry.order.intake', 'laundry.order.process', 'laundry.order.deliver', 'laundry.linen.handle', 'laundry.supplies.use',
                'laundry.claim.record', 'laundry.claim.view', 'front-office.availability.view',
            ],
            'F&B Cashier' => ['fnb.pos.operate', 'fnb.cashier.operate', 'fnb.receipt.reprint', 'kitchen.board.operate'],
            'F&B Supervisor' => ['fnb.*', 'kitchen.board.operate', 'kitchen.report.view', 'reporting.outlets.manage', 'inventory.stock.view', 'inventory.requisition.request'],
            'Kitchen Staff' => [
                'kitchen.board.operate', 'kitchen.damage.report', 'kitchen.ingredients.use', 'kitchen.production.record', 'kitchen.waste.record',
                'inventory.requisition.request', 'inventory.stock.view',
            ],
            'Kitchen Head' => ['kitchen.*', 'inventory.catalog.view', 'inventory.stock.view', 'inventory.requisition.request', 'inventory.count.manage', 'fnb.setup.manage', 'fnb.prices.manage'],
            'Maintenance Technician' => ['maintenance.work.perform', 'maintenance.work.report', 'inventory.requisition.request', 'inventory.stock.view'],
            'Maintenance Head' => ['maintenance.*', 'inventory.requisition.request', 'inventory.stock.view', 'purchasing.request.create'],
            'Storekeeper' => ['inventory.*', '!inventory.count.approve', '!inventory.stock.negative', 'purchasing.receipt.post', 'purchasing.order.view', 'purchasing.supplier.view'],
            'Purchasing Officer' => ['purchasing.*', '!purchasing.budget.manage', 'inventory.catalog.view', 'inventory.stock.view', 'finance.payable.view'],
            'Finance Staff' => [
                'finance.*', '!finance.account.manage', '!finance.tax.manage', '!finance.budget.manage', '!finance.correction.approve', '!finance.payroll.pay',
                '!finance.payment.reverse', '!finance.receipt.reverse', 'reporting.revenue.view', 'reporting.report.view', 'purchasing.invoice.manage', 'purchasing.report.view',
                'property.tax.view',
            ],
            'Finance Manager' => ['finance.*', 'reporting.*', 'purchasing.invoice.*', 'purchasing.budget.manage', 'purchasing.report.view', 'property.tax.*', 'inventory.valuation.view', 'inventory.count.approve'],
            'HR Manager' => ['hr.*', 'reporting.dashboard.view', 'privacy.request.manage'],
        ];
    }

    /**
     * The roles of a small team (a resort or a villa), where one person does several jobs. They are made when the property says it is small; the starting roles
     * for a hotel that nobody holds and nobody changed are then switched off, so the team is not offered nineteen roles.
     *
     * @return array<string, list<string>> role name => permission patterns
     */
    public static function small(): array
    {
        $hotel = self::all();

        return [
            'Resort Manager' => ['*', ...self::NO_ACCESS_ADMIN],
            'Front Desk & Cashier' => [
                ...$hotel['Receptionist'], 'front-office.night-audit.run', 'front-office.cashier.operate', 'fnb.pos.operate', 'fnb.cashier.operate', 'fnb.receipt.reprint',
                'laundry.order.intake',
            ],
            'Housekeeping Team' => [
                'housekeeping.view', 'housekeeping.task.perform', 'housekeeping.checklist.perform', 'housekeeping.checklist.view', 'housekeeping.supplies.use', 'housekeeping.lostfound.record',
                'maintenance.work.report', 'laundry.view', 'laundry.order.intake', 'laundry.order.process', 'laundry.order.deliver', 'laundry.linen.handle', 'laundry.supplies.use',
                'front-office.availability.view',
            ],
            'Kitchen & Bar' => [
                'kitchen.board.operate', 'kitchen.damage.report', 'kitchen.ingredients.use', 'kitchen.production.record', 'kitchen.waste.record', 'fnb.pos.operate',
                'fnb.cashier.operate', 'fnb.minibar.operate', 'inventory.requisition.request', 'inventory.stock.view',
            ],
        ];
    }

    /**
     * The permission codes of a role among the codes that exist.
     *
     * @param  list<string>  $patterns
     * @param  list<string>  $catalog
     * @return list<string>
     */
    public static function resolve(array $patterns, array $catalog): array
    {
        $codes = [];

        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '!')) {
                continue;
            }

            foreach ($catalog as $code) {
                if (self::matches($pattern, $code)) {
                    $codes[$code] = true;
                }
            }
        }

        foreach ($patterns as $pattern) {
            if (! str_starts_with($pattern, '!')) {
                continue;
            }

            foreach (array_keys($codes) as $code) {
                if (self::matches(substr($pattern, 1), (string) $code)) {
                    unset($codes[$code]);
                }
            }
        }

        $list = array_map('strval', array_keys($codes));
        sort($list);

        return $list;
    }

    /** `*` is any run of characters, so `property.*.view` matches `property.rates.view` and `hr.*` matches everything under `hr.`. */
    public static function matches(string $pattern, string $code): bool
    {
        return preg_match('/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/', $code) === 1;
    }
}
