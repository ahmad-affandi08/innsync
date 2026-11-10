/** `needs`: areas of permission (see `ModuleAccessMap::areas`); the page is offered when the person holds any permission in one of them. Without it the page is offered to the whole department. */
export type NavLink = { href: string; label: string; needs?: readonly string[]; group?: string; feature?: 'loyalty' };

/** The pages of a list that the person has work in. `areas` is null when it could not be read, and then every page is offered. This only decides what is shown: each page checks the permission on the server. */
export function visibleLinks(links: readonly NavLink[], areas: readonly string[] | null | undefined, features?: { loyalty: boolean } | null): readonly NavLink[] {
    return links.filter((l) => (l.feature === undefined || features?.[l.feature] !== false) && (areas == null || l.needs === undefined || l.needs.some((n) => areas.includes(n))));
}

/**
 * The pages of every module of the back office, in one place (docs/DESIGN/03-LAYOUT-NAVIGATION.md). The shell of a module gives them to the frame of its pages, and the menu
 * of a phone shows them for every module, so that touching a module opens its list of pages instead of taking the person to one of them.
 */
export const FRONT_OFFICE_LINKS: readonly NavLink[] = [
    { href: '/front-office/today', label: 'fo.today.nav', group: 'nav.group.frontDesk' },
    { href: '/front-office/room-board', label: 'fo.board.nav', group: 'nav.group.frontDesk' },
    { href: '/front-office/availability', label: 'fo.nav.availability', group: 'nav.group.frontDesk' },
    { href: '/front-office/room-calendar', label: 'fo.nav.tape', group: 'nav.group.frontDesk' },
    { href: '/front-office/inventory', label: 'fo.nav.inventory', group: 'nav.group.frontDesk' },
    { href: '/front-office/reservations', label: 'fo.nav.reservations', needs: ['front-office.reservation'], group: 'nav.group.bookings' },
    { href: '/front-office/waitlist', label: 'fo.wl.nav', needs: ['front-office.reservation'], group: 'nav.group.bookings' },
    { href: '/front-office/groups', label: 'fo.group.nav', needs: ['front-office.group'], group: 'nav.group.bookings' },
    { href: '/front-office/day-use', label: 'fo.du.nav', needs: ['front-office.day-use'], group: 'nav.group.bookings' },
    { href: '/front-office/guests', label: 'fo.nav.guests', needs: ['front-office.guest'], group: 'nav.group.guestsStays' },
    { href: '/front-office/stays', label: 'fo.nav.stays', group: 'nav.group.guestsStays' },
    { href: '/front-office/requests', label: 'fo.req.nav', needs: ['front-office.request'], group: 'nav.group.guestsStays' },
    { href: '/front-office/feedback', label: 'fo.fb.nav', needs: ['front-office.feedback'], group: 'nav.group.guestsStays' },
    { href: '/front-office/reminders', label: 'fo.rem.nav', group: 'nav.group.guestsStays' },
    { href: '/front-office/cashier', label: 'fo.cash.nav', needs: ['front-office.cashier'], group: 'nav.group.cashierAccounts' },
    { href: '/front-office/foreign-currency', label: 'fo.foreign.nav', needs: ['front-office.foreign-payment'], group: 'nav.group.cashierAccounts' },
    { href: '/front-office/gift-cards', label: 'fo.gc.nav', needs: ['front-office.gift-card'], group: 'nav.group.cashierAccounts' },
    { href: '/front-office/companies', label: 'fo.company.nav', needs: ['front-office.company'], group: 'nav.group.cashierAccounts' },
    { href: '/front-office/commissions', label: 'fo.com.nav', needs: ['front-office.commission'], group: 'nav.group.cashierAccounts' },
    { href: '/front-office/loyalty', label: 'fo.ly.nav', needs: ['front-office.loyalty'], feature: 'loyalty', group: 'nav.group.cashierAccounts' },
    { href: '/front-office/checklists', label: 'fo.sop.nav', needs: ['front-office.sop'], group: 'nav.group.deskRoutine' },
    { href: '/front-office/logbook', label: 'fo.log.nav', needs: ['front-office.logbook'], group: 'nav.group.deskRoutine' },
    { href: '/front-office/activities', label: 'fo.act.nav', needs: ['front-office.activity'], group: 'nav.group.deskRoutine' },
    { href: '/front-office/door-locks', label: 'fo.lock.nav', needs: ['front-office.door-lock'], group: 'nav.group.deskRoutine' },
    { href: '/front-office/night-audit', label: 'fo.nav.audit', needs: ['front-office.night-audit'], group: 'nav.group.deskRoutine' },
];

export const HOUSEKEEPING_LINKS: readonly NavLink[] = [
    { href: '/housekeeping', label: 'hk.nav.board' },
    { href: '/housekeeping/my-rooms', label: 'hk.nav.mine', needs: ['housekeeping.task'] },
    { href: '/housekeeping/checklists', label: 'hk.nav.checklists', needs: ['housekeeping.checklist'] },
    { href: '/housekeeping/linen', label: 'hk.nav.linen', needs: ['housekeeping.linen'] },
    { href: '/housekeeping/par-levels', label: 'hk.nav.par', needs: ['housekeeping.par'] },
    { href: '/housekeeping/supplies', label: 'hk.nav.supplies', needs: ['housekeeping.supplies'] },
    { href: '/housekeeping/lost-found', label: 'hk.nav.lostfound', needs: ['housekeeping.lostfound'] },
    { href: '/housekeeping/damage-reports', label: 'hk.nav.damage' },
    { href: '/inventory/requests?department=housekeeping', label: 'hk.nav.purchasing', needs: ['purchasing.request'] },
    { href: '/laundry/new', label: 'hk.nav.laundry' },
];

export const LAUNDRY_LINKS: readonly NavLink[] = [
    { href: '/laundry', label: 'ldy.nav.queue' },
    { href: '/laundry/new', label: 'ldy.nav.new' },
    { href: '/laundry/claims', label: 'ldy.nav.claims', needs: ['laundry.claim'] },
    { href: '/laundry/prices', label: 'ldy.nav.prices', needs: ['laundry.prices'] },
    { href: '/laundry/supplies', label: 'ldy.nav.supplies', needs: ['laundry.supplies'] },
    { href: '/inventory/requests?department=laundry', label: 'ldy.nav.purchasing', needs: ['purchasing.request'] },
];

export const FNB_LINKS: readonly NavLink[] = [
    { href: '/fnb/pos', label: 'fnb.nav.pos' },
    { href: '/guest/orders', label: 'guest.nav.orders' },
    { href: '/fnb/register', label: 'fnb.nav.register', needs: ['fnb.cashier'] },
    { href: '/fnb/shift', label: 'fnb.nav.shift', needs: ['fnb.cashier'] },
    { href: '/fnb/outlets', label: 'fnb.nav.outlets', needs: ['fnb.setup'] },
    { href: '/fnb/menu', label: 'fnb.nav.menu', needs: ['fnb.setup'] },
    { href: '/fnb/prices', label: 'fnb.nav.prices', needs: ['fnb.prices'] },
    { href: '/fnb/room-service', label: 'fnb.nav.roomService' },
    { href: '/fnb/minibar', label: 'fnb.nav.minibar', needs: ['fnb.minibar'] },
    { href: '/inventory/requisitions', label: 'inv.nav.requisitions', needs: ['inventory.requisition'] },
    { href: '/inventory/counts?location_kind=bar', label: 'fnb.nav.counts', needs: ['inventory.count'] },
    { href: '/fnb/routines', label: 'fnb.nav.routines' },
    { href: '/fnb/damage-reports', label: 'fnb.nav.damage' },
    { href: '/inventory/requests?department=fnb', label: 'fnb.nav.purchasing', needs: ['purchasing.request'] },
];

export const KITCHEN_LINKS: readonly NavLink[] = [
    { href: '/kitchen', label: 'kitchen.nav.board' },
    { href: '/kitchen/recipes', label: 'kitchen.nav.recipes', needs: ['kitchen.recipe'] },
    { href: '/kitchen/menu-report', label: 'kitchen.nav.report', needs: ['kitchen.report'] },
    { href: '/kitchen/production', label: 'kitchen.nav.production', needs: ['kitchen.production'] },
    { href: '/kitchen/waste', label: 'kitchen.nav.waste', needs: ['kitchen.waste'] },
    { href: '/kitchen/ingredient-use', label: 'kitchen.nav.ingredients', needs: ['kitchen.ingredients'] },
    { href: '/kitchen/routines', label: 'kitchen.nav.routines' },
    { href: '/inventory/lots?department=kitchen', label: 'kitchen.nav.lots', needs: ['inventory.stock'] },
    { href: '/inventory/requisitions', label: 'inv.nav.requisitions', needs: ['inventory.requisition'] },
    { href: '/inventory/counts?location_kind=kitchen', label: 'kitchen.nav.counts', needs: ['inventory.count'] },
    { href: '/kitchen/damage-reports', label: 'kitchen.nav.damage' },
    { href: '/inventory/requests?department=kitchen', label: 'kitchen.nav.purchasing', needs: ['purchasing.request'] },
];

export const MAINTENANCE_LINKS: readonly NavLink[] = [
    { href: '/maintenance', label: 'mtc.nav.orders' },
    { href: '/maintenance/duties', label: 'mtc.nav.duties' },
    { href: '/maintenance/assets', label: 'mtc.nav.assets' },
    { href: '/maintenance/vendor-work', label: 'mtc.nav.vendor', needs: ['maintenance.vendor'] },
    { href: '/maintenance/reports', label: 'mtc.nav.reports' },
];

export const HR_LINKS: readonly NavLink[] = [
    { href: '/hr/me', label: 'hr.nav.me', group: 'nav.group.mine' },
    { href: '/hr/employees', label: 'hr.nav.employees', needs: ['hr.employee'], group: 'nav.group.people' },
    { href: '/hr/roster', label: 'hr.nav.roster', needs: ['hr.roster'], group: 'nav.group.people' },
    { href: '/hr/attendance', label: 'hr.nav.attendance', needs: ['hr.attendance'], group: 'nav.group.people' },
    { href: '/hr/face', label: 'hr.nav.face', needs: ['hr.attendance'], group: 'nav.group.people' },
    { href: '/hr/leave', label: 'hr.nav.leave', group: 'nav.group.leaveNews' },
    { href: '/hr/swaps', label: 'hr.nav.swaps', group: 'nav.group.leaveNews' },
    { href: '/hr/announcements', label: 'hr.nav.announcements', group: 'nav.group.leaveNews' },
    { href: '/hr/conduct', label: 'hr.nav.conduct', needs: ['hr.conduct'], group: 'nav.group.performance' },
    { href: '/hr/performance', label: 'hr.nav.performance', needs: ['hr.performance'], group: 'nav.group.performance' },
    { href: '/hr/appraisals', label: 'hr.nav.appraisals', needs: ['hr.appraisal'], group: 'nav.group.performance' },
    { href: '/hr/payroll', label: 'hr.nav.payroll', needs: ['hr.payroll'], group: 'nav.group.payroll' },
    { href: '/hr/service-charge', label: 'hr.nav.serviceCharge', needs: ['hr.service-charge'], group: 'nav.group.payroll' },
    { href: '/hr/payroll/runs', label: 'hr.nav.payrollRuns', needs: ['hr.payroll'], group: 'nav.group.payroll' },
    { href: '/hr/payslips', label: 'hr.nav.payslips', group: 'nav.group.payroll' },
];

export const INVENTORY_LINKS: readonly NavLink[] = [
    { href: '/inventory/stock', label: 'inv.nav.stock', needs: ['inventory.stock'], group: 'nav.group.stock' },
    { href: '/inventory/lots', label: 'inv.nav.lots', needs: ['inventory.stock'], group: 'nav.group.stock' },
    { href: '/inventory/transfers', label: 'inv.nav.transfers', needs: ['inventory.transfer'], group: 'nav.group.stock' },
    { href: '/inventory/requisitions', label: 'inv.nav.requisitions', needs: ['inventory.requisition'], group: 'nav.group.stock' },
    { href: '/inventory/counts', label: 'inv.nav.counts', needs: ['inventory.count'], group: 'nav.group.stock' },
    { href: '/inventory/requests', label: 'inv.nav.requests', needs: ['purchasing.request'], group: 'nav.group.purchasing' },
    { href: '/inventory/orders', label: 'inv.nav.orders', needs: ['purchasing.order'], group: 'nav.group.purchasing' },
    { href: '/inventory/receipts', label: 'inv.nav.receipts', needs: ['purchasing.receipt'], group: 'nav.group.purchasing' },
    { href: '/inventory/invoices', label: 'inv.nav.invoices', needs: ['purchasing.invoice'], group: 'nav.group.purchasing' },
    { href: '/inventory/returns', label: 'inv.nav.returns', needs: ['purchasing.return'], group: 'nav.group.purchasing' },
    { href: '/inventory/quotes', label: 'inv.nav.quotes', needs: ['purchasing.quote'], group: 'nav.group.purchasing' },
    { href: '/inventory/reports/purchases', label: 'inv.nav.purchaseReport', needs: ['purchasing.report'], group: 'nav.group.purchaseReports' },
    { href: '/inventory/reports/deliveries', label: 'inv.nav.deliveryReport', needs: ['purchasing.report'], group: 'nav.group.purchaseReports' },
    { href: '/inventory/suppliers', label: 'inv.nav.suppliers', needs: ['purchasing.supplier'], group: 'nav.group.stockSetup' },
    { href: '/inventory/purchasing-settings', label: 'inv.nav.purchasingSettings', group: 'nav.group.stockSetup' },
    { href: '/inventory/items', label: 'inv.nav.items', needs: ['inventory.catalog'], group: 'nav.group.stockSetup' },
    { href: '/inventory/locations', label: 'inv.nav.locations', needs: ['inventory.catalog'], group: 'nav.group.stockSetup' },
];

export const FINANCE_LINKS: readonly NavLink[] = [
    { href: '/finance/revenue', label: 'fin.nav.revenue', group: 'nav.group.dailyClose' },
    { href: '/finance/cash', label: 'fin.nav.cash', group: 'nav.group.dailyClose' },
    { href: '/finance/settlements', label: 'fin.nav.settlements', group: 'nav.group.dailyClose' },
    { href: '/finance/exceptions', label: 'fin.nav.exceptions', group: 'nav.group.dailyClose' },
    { href: '/finance/corrections', label: 'fin.nav.corrections', needs: ['finance.correction'], group: 'nav.group.dailyClose' },
    { href: '/finance/payables', label: 'fin.nav.payables', needs: ['finance.payable'], group: 'nav.group.payables' },
    { href: '/finance/payments', label: 'fin.nav.payments', needs: ['finance.payment'], group: 'nav.group.payables' },
    { href: '/finance/schedule', label: 'fin.nav.schedule', needs: ['finance.payable'], group: 'nav.group.payables' },
    { href: '/finance/aging', label: 'fin.nav.aging', needs: ['finance.payable'], group: 'nav.group.payables' },
    { href: '/finance/receivables', label: 'fin.nav.receivables', needs: ['finance.receivable'], group: 'nav.group.receivables' },
    { href: '/finance/receivables/aging', label: 'fin.nav.receivableAging', needs: ['finance.receivable'], group: 'nav.group.receivables' },
    { href: '/finance/customers', label: 'fin.nav.customers', needs: ['finance.receivable'], group: 'nav.group.receivables' },
    { href: '/finance/petty', label: 'fin.nav.petty', needs: ['finance.petty'], group: 'nav.group.cashBank' },
    { href: '/finance/bank', label: 'fin.nav.bank', needs: ['finance.bank'], group: 'nav.group.cashBank' },
    { href: '/finance/payroll', label: 'fin.nav.payroll', needs: ['finance.payroll'], group: 'nav.group.cashBank' },
    { href: '/finance/recurring', label: 'fin.nav.recurring', needs: ['finance.recurring'], group: 'nav.group.planning' },
    { href: '/finance/budget', label: 'fin.nav.budget', needs: ['finance.budget'], group: 'nav.group.planning' },
    { href: '/finance/tax', label: 'fin.nav.tax', needs: ['finance.tax'], group: 'nav.group.planning' },
    { href: '/finance/assets', label: 'fin.nav.assets', needs: ['finance.asset'], group: 'nav.group.planning' },
    { href: '/finance/pnl', label: 'fin.nav.pnl', needs: ['finance.report'], group: 'nav.group.finReports' },
    { href: '/finance/cashflow', label: 'fin.nav.cashflow', needs: ['finance.report'], group: 'nav.group.finReports' },
    { href: '/finance/stock-value', label: 'fin.nav.stockValue', needs: ['finance.report'], group: 'nav.group.finReports' },
    { href: '/finance/food-cost', label: 'fin.nav.foodCost', needs: ['finance.report'], group: 'nav.group.finReports' },
    { href: '/finance/budget/report', label: 'fin.nav.budgetReport', needs: ['finance.budget'], group: 'nav.group.finReports' },
    { href: '/finance/audit', label: 'fin.nav.audit', needs: ['finance.audit'], group: 'nav.group.finReports' },
    { href: '/finance/export', label: 'fin.nav.export', needs: ['finance.export'], group: 'nav.group.finSetup' },
    { href: '/finance/accounts', label: 'fin.nav.accounts', needs: ['finance.account'], group: 'nav.group.finSetup' },
];

export const PROPERTY_LINKS: readonly NavLink[] = [
    { href: '/setup', label: 'setup.nav', needs: ['property.settings'], group: 'nav.group.hotelSetup' },
    { href: '/property/settings', label: 'property.action.settings', needs: ['property.settings'], group: 'nav.group.hotelSetup' },
    { href: '/property/rooms', label: 'property.action.rooms', needs: ['property.catalog'], group: 'nav.group.hotelSetup' },
    { href: '/property/rates', label: 'property.action.rates', needs: ['property.rates'], group: 'nav.group.hotelSetup' },
    { href: '/property/tax', label: 'property.action.tax', needs: ['property.tax'], group: 'nav.group.hotelSetup' },
    { href: '/property/policies', label: 'policy.nav', needs: ['property.policies'], group: 'nav.group.hotelSetup' },
    { href: '/access/users', label: 'acc.nav.users', needs: ['identity.user'], group: 'nav.group.access' },
    { href: '/access/roles', label: 'acc.nav.roles', needs: ['identity.role'], group: 'nav.group.access' },
    { href: '/sync/exceptions', label: 'sync.nav', needs: ['offline.reconcile'], group: 'nav.group.system' },
    { href: '/property/system', label: 'sys.nav', needs: ['property.settings'], group: 'nav.group.system' },
    { href: '/property/messaging', label: 'msg.nav', needs: ['property.settings'], group: 'nav.group.system' },
    { href: '/property/branding', label: 'brand.nav', needs: ['property.settings'], group: 'nav.group.system' },
    { href: '/property/online-booking', label: 'ob.nav', needs: ['property.settings'], group: 'nav.group.system' },
];

export const REPORT_LINKS: readonly NavLink[] = [
    { href: '/reports', label: 'rpt.nav.reports' },
    { href: '/reports/builder', label: 'rpt.nav.builder', needs: ['reporting.builder'] },
    { href: '/reports/exports', label: 'rpt.nav.exports' },
    { href: '/reports/schedules', label: 'rpt.nav.schedules', needs: ['reporting.schedule'] },
    { href: '/reports/outlets', label: 'rpt.nav.outlets', needs: ['reporting.outlets'] },
];

/** The dashboard and the report pages share one shell, which shows the dashboard first. */
export const REPORTING_LINKS: readonly NavLink[] = [{ href: '/dashboard', label: 'rpt.nav.dashboard' }, ...REPORT_LINKS];

/** The pages of each module, by the key of the module in the frame. A module with no list of pages is a single page. */
/** What the guests' self-service gives the staff: shown under Beranda, so a phone can reach the QR codes and the guests' orders. */
export const GUEST_LINKS: readonly NavLink[] = [
    { href: '/guest/checkins', label: 'guest.nav.checkins' },
    { href: '/guest/surveys', label: 'guest.nav.surveys' },
    { href: '/guest/qr', label: 'guest.nav.qr' },
];

export const MODULE_LINKS: Readonly<Record<string, readonly NavLink[]>> = {
    home: GUEST_LINKS,
    'front-office': FRONT_OFFICE_LINKS,
    housekeeping: HOUSEKEEPING_LINKS,
    laundry: LAUNDRY_LINKS,
    fnb: FNB_LINKS,
    kitchen: KITCHEN_LINKS,
    maintenance: MAINTENANCE_LINKS,
    hr: HR_LINKS,
    inventory: INVENTORY_LINKS,
    finance: FINANCE_LINKS,
    reports: REPORTING_LINKS,
    property: PROPERTY_LINKS,
};


