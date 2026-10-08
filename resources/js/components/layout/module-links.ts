export type NavLink = { href: string; label: string };

/**
 * The pages of every module of the back office, in one place (docs/DESIGN/03-LAYOUT-NAVIGATION.md). The shell of a module gives them to the frame of its pages, and the menu
 * of a phone shows them for every module, so that touching a module opens its list of pages instead of taking the person to one of them.
 */
export const FRONT_OFFICE_LINKS: readonly NavLink[] = [
    { href: '/front-office/today', label: 'fo.today.nav' },
    { href: '/front-office/room-board', label: 'fo.board.nav' },
    { href: '/front-office/availability', label: 'fo.nav.availability' },
    { href: '/front-office/room-calendar', label: 'fo.nav.tape' },
    { href: '/front-office/reservations', label: 'fo.nav.reservations' },
    { href: '/front-office/guests', label: 'fo.nav.guests' },
    { href: '/front-office/stays', label: 'fo.nav.stays' },
    { href: '/front-office/inventory', label: 'fo.nav.inventory' },
    { href: '/front-office/reminders', label: 'fo.rem.nav' },
    { href: '/front-office/requests', label: 'fo.req.nav' },
    { href: '/front-office/feedback', label: 'fo.fb.nav' },
    { href: '/front-office/checklists', label: 'fo.sop.nav' },
    { href: '/front-office/logbook', label: 'fo.log.nav' },
    { href: '/front-office/cashier', label: 'fo.cash.nav' },
    { href: '/front-office/groups', label: 'fo.group.nav' },
    { href: '/front-office/companies', label: 'fo.company.nav' },
    { href: '/front-office/foreign-currency', label: 'fo.foreign.nav' },
    { href: '/front-office/night-audit', label: 'fo.nav.audit' },
];

export const HOUSEKEEPING_LINKS: readonly NavLink[] = [
    { href: '/housekeeping', label: 'hk.nav.board' },
    { href: '/housekeeping/my-rooms', label: 'hk.nav.mine' },
    { href: '/housekeeping/checklists', label: 'hk.nav.checklists' },
    { href: '/housekeeping/linen', label: 'hk.nav.linen' },
    { href: '/housekeeping/par-levels', label: 'hk.nav.par' },
    { href: '/housekeeping/supplies', label: 'hk.nav.supplies' },
    { href: '/housekeeping/lost-found', label: 'hk.nav.lostfound' },
    { href: '/housekeeping/damage-reports', label: 'hk.nav.damage' },
    { href: '/inventory/requests?department=housekeeping', label: 'hk.nav.purchasing' },
    { href: '/front-office/room-board', label: 'hk.nav.frontdesk' },
    { href: '/laundry/new', label: 'hk.nav.laundry' },
];

export const LAUNDRY_LINKS: readonly NavLink[] = [
    { href: '/laundry', label: 'ldy.nav.queue' },
    { href: '/laundry/new', label: 'ldy.nav.new' },
    { href: '/laundry/claims', label: 'ldy.nav.claims' },
    { href: '/laundry/prices', label: 'ldy.nav.prices' },
    { href: '/laundry/supplies', label: 'ldy.nav.supplies' },
    { href: '/inventory/requests?department=laundry', label: 'ldy.nav.purchasing' },
];

export const FNB_LINKS: readonly NavLink[] = [
    { href: '/fnb/pos', label: 'fnb.nav.pos' },
    { href: '/guest/orders', label: 'guest.nav.orders' },
    { href: '/fnb/register', label: 'fnb.nav.register' },
    { href: '/fnb/shift', label: 'fnb.nav.shift' },
    { href: '/fnb/outlets', label: 'fnb.nav.outlets' },
    { href: '/fnb/menu', label: 'fnb.nav.menu' },
    { href: '/fnb/prices', label: 'fnb.nav.prices' },
    { href: '/fnb/room-service', label: 'fnb.nav.roomService' },
    { href: '/fnb/minibar', label: 'fnb.nav.minibar' },
    { href: '/inventory/requisitions', label: 'inv.nav.requisitions' },
    { href: '/inventory/counts?location_kind=bar', label: 'fnb.nav.counts' },
    { href: '/fnb/routines', label: 'fnb.nav.routines' },
    { href: '/fnb/damage-reports', label: 'fnb.nav.damage' },
    { href: '/inventory/requests?department=fnb', label: 'fnb.nav.purchasing' },
];

export const KITCHEN_LINKS: readonly NavLink[] = [
    { href: '/kitchen', label: 'kitchen.nav.board' },
    { href: '/kitchen/recipes', label: 'kitchen.nav.recipes' },
    { href: '/kitchen/menu-report', label: 'kitchen.nav.report' },
    { href: '/kitchen/production', label: 'kitchen.nav.production' },
    { href: '/kitchen/waste', label: 'kitchen.nav.waste' },
    { href: '/kitchen/ingredient-use', label: 'kitchen.nav.ingredients' },
    { href: '/kitchen/routines', label: 'kitchen.nav.routines' },
    { href: '/inventory/lots?department=kitchen', label: 'kitchen.nav.lots' },
    { href: '/inventory/requisitions', label: 'inv.nav.requisitions' },
    { href: '/inventory/counts?location_kind=kitchen', label: 'kitchen.nav.counts' },
    { href: '/kitchen/damage-reports', label: 'kitchen.nav.damage' },
    { href: '/inventory/requests?department=kitchen', label: 'kitchen.nav.purchasing' },
];

export const MAINTENANCE_LINKS: readonly NavLink[] = [
    { href: '/maintenance', label: 'mtc.nav.orders' },
    { href: '/maintenance/duties', label: 'mtc.nav.duties' },
    { href: '/maintenance/assets', label: 'mtc.nav.assets' },
    { href: '/maintenance/vendor-work', label: 'mtc.nav.vendor' },
    { href: '/maintenance/reports', label: 'mtc.nav.reports' },
];

export const HR_LINKS: readonly NavLink[] = [
    { href: '/hr/me', label: 'hr.nav.me' },
    { href: '/hr/employees', label: 'hr.nav.employees' },
    { href: '/hr/roster', label: 'hr.nav.roster' },
    { href: '/hr/attendance', label: 'hr.nav.attendance' },
    { href: '/hr/leave', label: 'hr.nav.leave' },
    { href: '/hr/swaps', label: 'hr.nav.swaps' },
    { href: '/hr/announcements', label: 'hr.nav.announcements' },
    { href: '/hr/conduct', label: 'hr.nav.conduct' },
    { href: '/hr/performance', label: 'hr.nav.performance' },
    { href: '/hr/appraisals', label: 'hr.nav.appraisals' },
    { href: '/hr/payroll', label: 'hr.nav.payroll' },
    { href: '/hr/service-charge', label: 'hr.nav.serviceCharge' },
    { href: '/hr/payroll/runs', label: 'hr.nav.payrollRuns' },
    { href: '/hr/payslips', label: 'hr.nav.payslips' },
];

export const INVENTORY_LINKS: readonly NavLink[] = [
    { href: '/inventory/stock', label: 'inv.nav.stock' },
    { href: '/inventory/lots', label: 'inv.nav.lots' },
    { href: '/inventory/transfers', label: 'inv.nav.transfers' },
    { href: '/inventory/requisitions', label: 'inv.nav.requisitions' },
    { href: '/inventory/counts', label: 'inv.nav.counts' },
    { href: '/inventory/suppliers', label: 'inv.nav.suppliers' },
    { href: '/inventory/requests', label: 'inv.nav.requests' },
    { href: '/inventory/orders', label: 'inv.nav.orders' },
    { href: '/inventory/receipts', label: 'inv.nav.receipts' },
    { href: '/inventory/invoices', label: 'inv.nav.invoices' },
    { href: '/inventory/returns', label: 'inv.nav.returns' },
    { href: '/inventory/quotes', label: 'inv.nav.quotes' },
    { href: '/inventory/reports/purchases', label: 'inv.nav.purchaseReport' },
    { href: '/inventory/reports/deliveries', label: 'inv.nav.deliveryReport' },
    { href: '/inventory/purchasing-settings', label: 'inv.nav.purchasingSettings' },
    { href: '/inventory/items', label: 'inv.nav.items' },
    { href: '/inventory/locations', label: 'inv.nav.locations' },
];

export const FINANCE_LINKS: readonly NavLink[] = [
    { href: '/finance/payables', label: 'fin.nav.payables' },
    { href: '/finance/payments', label: 'fin.nav.payments' },
    { href: '/finance/schedule', label: 'fin.nav.schedule' },
    { href: '/finance/aging', label: 'fin.nav.aging' },
    { href: '/finance/receivables', label: 'fin.nav.receivables' },
    { href: '/finance/receivables/aging', label: 'fin.nav.receivableAging' },
    { href: '/finance/customers', label: 'fin.nav.customers' },
    { href: '/finance/revenue', label: 'fin.nav.revenue' },
    { href: '/finance/cash', label: 'fin.nav.cash' },
    { href: '/finance/corrections', label: 'fin.nav.corrections' },
    { href: '/finance/settlements', label: 'fin.nav.settlements' },
    { href: '/finance/exceptions', label: 'fin.nav.exceptions' },
    { href: '/finance/audit', label: 'fin.nav.audit' },
    { href: '/finance/petty', label: 'fin.nav.petty' },
    { href: '/finance/pnl', label: 'fin.nav.pnl' },
    { href: '/finance/cashflow', label: 'fin.nav.cashflow' },
    { href: '/finance/stock-value', label: 'fin.nav.stockValue' },
    { href: '/finance/food-cost', label: 'fin.nav.foodCost' },
    { href: '/finance/recurring', label: 'fin.nav.recurring' },
    { href: '/finance/budget', label: 'fin.nav.budget' },
    { href: '/finance/budget/report', label: 'fin.nav.budgetReport' },
    { href: '/finance/payroll', label: 'fin.nav.payroll' },
    { href: '/finance/tax', label: 'fin.nav.tax' },
    { href: '/finance/export', label: 'fin.nav.export' },
    { href: '/finance/accounts', label: 'fin.nav.accounts' },
];

export const PROPERTY_LINKS: readonly NavLink[] = [
    { href: '/setup', label: 'setup.nav' },
    { href: '/property/settings', label: 'property.action.settings' },
    { href: '/property/rooms', label: 'property.action.rooms' },
    { href: '/property/rates', label: 'property.action.rates' },
    { href: '/property/tax', label: 'property.action.tax' },
    { href: '/property/policies', label: 'policy.nav' },
    { href: '/access/users', label: 'acc.nav.users' },
    { href: '/access/roles', label: 'acc.nav.roles' },
    { href: '/sync/exceptions', label: 'sync.nav' },
    { href: '/property/system', label: 'sys.nav' },
    { href: '/property/messaging', label: 'msg.nav' },
    { href: '/property/branding', label: 'brand.nav' },
    { href: '/property/online-booking', label: 'ob.nav' },
];

export const REPORT_LINKS: readonly NavLink[] = [
    { href: '/reports', label: 'rpt.nav.reports' },
    { href: '/reports/builder', label: 'rpt.nav.builder' },
    { href: '/reports/exports', label: 'rpt.nav.exports' },
    { href: '/reports/schedules', label: 'rpt.nav.schedules' },
    { href: '/reports/outlets', label: 'rpt.nav.outlets' },
];

/** The dashboard and the report pages share one shell, which shows the dashboard first. */
export const REPORTING_LINKS: readonly NavLink[] = [{ href: '/dashboard', label: 'rpt.nav.dashboard' }, ...REPORT_LINKS];

/** The pages of each module, by the key of the module in the frame. A module with no list of pages is a single page. */
export const MODULE_LINKS: Readonly<Record<string, readonly NavLink[]>> = {
    'front-office': FRONT_OFFICE_LINKS,
    housekeeping: HOUSEKEEPING_LINKS,
    laundry: LAUNDRY_LINKS,
    fnb: FNB_LINKS,
    kitchen: KITCHEN_LINKS,
    maintenance: MAINTENANCE_LINKS,
    hr: HR_LINKS,
    inventory: INVENTORY_LINKS,
    finance: FINANCE_LINKS,
    reports: REPORT_LINKS,
    property: PROPERTY_LINKS,
};


