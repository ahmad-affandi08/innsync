import { type DataGridColumn } from '@/components/ui/data-grid';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { minorToMajorText, parseMajorToMinor } from '@/shared/money/money';
import { cn } from '@/shared/lib/utils';
import type { MessageKey } from '@/locales/en/index';

/** A payable as the overview, the schedule and the payable page send it. */
export type PayableRow = {
    id: string; supplier_id: string; supplier_code: string; supplier_name: string; source_number: string; document_number: string; due_date: string;
    amount_minor: number; paid_minor: number; credit_minor: number; pending_minor: number; balance_minor: number; available_minor: number; currency: string;
    status: 'open' | 'partial' | 'paid'; overdue: boolean; days_to_due: number; days_overdue: number;
};

export const PAYABLE_TONE: Record<string, StatusTone> = { open: 'info', partial: 'pending', paid: 'success' };
export const PAYMENT_TONE: Record<string, StatusTone> = { pending_approval: 'pending', paid: 'success', rejected: 'danger', cancelled: 'neutral', reversal: 'warning' };
export const PAYMENT_STATUSES = ['pending_approval', 'paid', 'rejected', 'cancelled', 'reversal'] as const;
export const PAYMENT_METHODS = ['transfer', 'cash', 'giro', 'other'] as const;

/** The state of a payable, with an extra red badge showing how late it is when something is owed after the due date. */
export function PayableStatus({ row }: { row: Pick<PayableRow, 'status' | 'overdue' | 'days_overdue'> }) {
    const { t } = useTranslation();

    return (
        <span className="inline-flex flex-wrap items-center gap-1.5">
            <StatusBadge label={t(`fin.status.${row.status}` as MessageKey)} tone={PAYABLE_TONE[row.status] ?? 'neutral'} />
            {row.overdue ? <StatusBadge label={t('fin.overdueDays', { days: row.days_overdue })} tone="danger" /> : null}
        </span>
    );
}

/** How far a payable's due date is: late (danger), within a week (warning) or later (neutral). */
export function DueBadge({ row }: { row: Pick<PayableRow, 'overdue' | 'days_to_due' | 'days_overdue'> }) {
    const { t } = useTranslation();

    if (row.overdue) return <StatusBadge label={t('fin.overdueDays', { days: row.days_overdue })} tone="danger" />;
    if (row.days_to_due === 0) return <StatusBadge label={t('fin.dueToday')} tone="warning" />;

    return <StatusBadge label={t('fin.dueIn', { days: row.days_to_due })} tone={row.days_to_due <= 7 ? 'warning' : 'neutral'} />;
}

export const REVENUE_TONE: Record<string, StatusTone> = { recorded: 'info', verified: 'success' };
export const EXCEPTION_TONE: Record<string, StatusTone> = { open: 'danger', explained: 'info', recovered: 'success', waived: 'neutral' };

/** The money of one slice of revenue, as the report sends it. */
export type RevenueAmounts = { base_minor: number; service_charge_minor: number; tax_minor: number; total_minor: number };
export type OutletRevenue = RevenueAmounts & { code: string; name: string | null };
export type MethodTotals = { method: string; received_minor: number; paid_back_minor: number; net_minor: number; entries: number };

const KNOWN_OUTLETS = ['rooms', 'laundry', 'fees', 'other'];
const KNOWN_METHODS = ['cash', 'qris', 'card', 'bank_transfer', 'online'];

/** An outlet by the name the property gave it, or by its translated built-in code. */
export function useOutletLabel() {
    const { t } = useTranslation();

    return (code: string, name: string | null): string => name ?? (KNOWN_OUTLETS.includes(code) ? t(`fo.bill.outlet.${code}` as MessageKey) : code);
}

/** How money was received, in the words the front office uses. */
export function useReceiptMethodLabel() {
    const { t } = useTranslation();

    return (method: string): string => (KNOWN_METHODS.includes(method) ? t(`fo.folio.method.${method}` as MessageKey) : method);
}

/** A receivable as the list and the receivable page send it. */
export type ReceivableRow = {
    id: string; number: string; customer_id: string; customer_code: string; customer_name: string; source_type: 'company_folio' | 'manual'; source_number: string; description: string;
    issued_on: string; due_date: string; amount_minor: number; received_minor: number; balance_minor: number; currency: string; status: 'open' | 'partial' | 'paid'; overdue: boolean;
    days_to_due: number; days_overdue: number; promised_on: string | null; last_note_at: string | null; note_count: number;
};

export const RECEIVABLE_TONE: Record<string, StatusTone> = { open: 'info', partial: 'pending', paid: 'success' };
export const CUSTOMER_KINDS = ['company', 'agent', 'ota', 'other'] as const;
/** The kinds of line a receivable's receipts table lists: what was received, what took it back, and the two adjustments. */
export const RECEIPT_KIND_TONE: Record<string, StatusTone> = { receipt: 'success', reversal: 'warning', credit_note: 'info', write_off: 'neutral' };
export const NOTE_TONE: Record<string, StatusTone> = { reminder: 'info', call: 'info', promise: 'warning', dispute: 'danger', note: 'neutral' };

/** The state of a receivable, with a red badge showing how late it is when something is still owed after the due date. */
export function ReceivableStatus({ row }: { row: Pick<ReceivableRow, 'status' | 'overdue' | 'days_overdue'> }) {
    const { t } = useTranslation();

    return (
        <span className="inline-flex flex-wrap items-center gap-1.5">
            <StatusBadge label={t(`fin.status.${row.status}` as MessageKey)} tone={RECEIVABLE_TONE[row.status] ?? 'neutral'} />
            {row.overdue ? <StatusBadge label={t('fin.overdueDays', { days: row.days_overdue })} tone="danger" /> : null}
        </span>
    );
}

/** The date a customer promised to pay, in warning colour while it holds and in red once it has passed. Nothing for a paid receivable. */
export function PromiseBadge({ row, today }: { row: Pick<ReceivableRow, 'promised_on' | 'balance_minor'>; today: string }) {
    const { t } = useTranslation();
    const format = useFormatters();

    if (row.promised_on === null || row.balance_minor <= 0) return null;

    return row.promised_on < today
        ? <StatusBadge label={t('fin.ar.promiseBroken', { date: format.date(row.promised_on) })} tone="danger" />
        : <StatusBadge label={t('fin.ar.promisedOn', { date: format.date(row.promised_on) })} tone="warning" />;
}

/** A customer's kind in the words the front office and finance use. */
export function useCustomerKindLabel() {
    const { t } = useTranslation();

    return (kind: string): string => ((CUSTOMER_KINDS as readonly string[]).includes(kind) ? t(`fin.arc.kind.${kind}` as MessageKey) : kind);
}

/** A petty cash fund as the funds page and the fund page send it. */
export type PettyFund = {
    id: string; code: string; name: string; custodian_id: string; custodian_name: string | null; imprest_minor: number; max_voucher_minor: number | null; currency: string; active: boolean; lock_version: number;
    balance_minor: number; unsettled_count: number; unsettled_minor: number; pending_settlement_id: string | null;
};
export type PettyVoucherState = 'open' | 'in_settlement' | 'settled' | 'voided';
export type PettyVoucher = {
    id: string; number: string; voucher_date: string; payee: string; description: string; account_code: string; account_name: string; amount_minor: number; receipt_ref: string | null; no_receipt_reason: string | null;
    proof_count: number; state: PettyVoucherState; settlement_number: string | null; void_reason: string | null; by: string | null; may_void: boolean;
};
export type PettySettlementHead = {
    id: string; number: string; status: 'submitted' | 'approved' | 'rejected'; voucher_total_minor: number; counted_minor: number; variance_minor: number; replenish_minor: number; business_date: string;
    submitted_by: string | null; decided_by: string | null; decided_at: string | null;
};

export const PETTY_VOUCHER_TONE: Record<string, StatusTone> = { open: 'info', in_settlement: 'pending', settled: 'success', voided: 'neutral' };
export const PETTY_SETTLEMENT_TONE: Record<string, StatusTone> = { submitted: 'pending', approved: 'success', rejected: 'danger' };
export const PETTY_VOUCHER_STATES = ['open', 'in_settlement', 'settled', 'voided'] as const;

/** The state of a voucher in the words the custodian and the manager use. */
export function usePettyStateLabel() {
    const { t } = useTranslation();

    return (state: string): string => ((PETTY_VOUCHER_STATES as readonly string[]).includes(state) ? t(`fin.petty.state.${state}` as MessageKey) : state);
}

/** A signed amount with its sign always shown: "+Rp 5.000" or "−Rp 5.000". */
export function useSignedMoney() {
    const format = useFormatters();

    return (minor: number, currency: string): string => (minor === 0 ? format.money(0, currency) : `${minor > 0 ? '+' : '−'}${format.money(Math.abs(minor), currency)}`);
}

/** The difference found at a count (cash counted less the book balance): none, short (red) or over (amber). */
export function PettyVariance({ currency, minor }: { currency: string; minor: number }) {
    const { t } = useTranslation();
    const format = useFormatters();

    if (minor === 0) return <StatusBadge label={t('fin.petty.noDifference')} tone="success" />;

    return minor < 0
        ? <StatusBadge label={t('fin.petty.short', { amount: format.money(-minor, currency) })} tone="danger" />
        : <StatusBadge label={t('fin.petty.over', { amount: format.money(minor, currency) })} tone="warning" />;
}

/** The columns of a table of petty cash vouchers, shared by the fund page and the settlement page. `actions` is added last when the screen has any. */
export function usePettyVoucherColumns(currency: string, actions?: DataGridColumn<PettyVoucher>): DataGridColumn<PettyVoucher>[] {
    const { t } = useTranslation();
    const format = useFormatters();
    const stateLabel = usePettyStateLabel();

    return [
        { id: 'number', label: t('fin.petty.v.number'), value: (v) => v.number, rowHeader: true },
        { id: 'date', label: t('fin.petty.v.date'), value: (v) => v.voucher_date, cell: (v) => format.date(v.voucher_date) },
        { id: 'payee', label: t('fin.petty.v.payee'), value: (v) => v.payee, searchText: (v) => `${v.payee} ${v.description}`, cell: (v) => (
            <span className="flex flex-col"><span>{v.payee}</span><span className="text-xs text-muted-foreground">{v.description}</span></span>
        ) },
        { id: 'account', label: t('fin.petty.v.account'), value: (v) => v.account_code, searchText: (v) => `${v.account_code} ${v.account_name}`, cell: (v) => `${v.account_code} · ${v.account_name}`, hidden: true },
        { id: 'amount', label: t('fin.petty.v.amount'), align: 'right', value: (v) => v.amount_minor, cell: (v) => format.money(v.amount_minor, currency) },
        {
            id: 'receipt', label: t('fin.petty.v.receipt'), value: (v) => (v.no_receipt_reason !== null ? 'none' : v.proof_count > 0 ? 'proof' : 'missing'), filter: 'select',
            filterLabel: (value) => t(`fin.petty.receipt.${value}` as MessageKey), searchText: (v) => `${v.receipt_ref ?? ''} ${v.no_receipt_reason ?? ''}`,
            cell: (v) => (
                <span className="flex flex-col gap-1">
                    {v.receipt_ref !== null ? <span>{v.receipt_ref}</span> : null}
                    {v.no_receipt_reason !== null ? (
                        <>
                            <span><StatusBadge label={t('fin.petty.receipt.none')} tone="warning" /></span>
                            <span className="text-xs text-muted-foreground">{v.no_receipt_reason}</span>
                        </>
                    ) : null}
                    {v.proof_count > 0
                        ? <span className="text-xs text-muted-foreground">{t('fin.petty.proofCount', { count: v.proof_count })}</span>
                        : v.no_receipt_reason === null ? <span><StatusBadge label={t('fin.petty.receipt.missing')} tone="warning" /></span> : null}
                </span>
            ),
        },
        {
            id: 'state', label: t('fin.petty.v.state'), value: (v) => v.state, filter: 'select', filterLabel: stateLabel,
            cell: (v) => (
                <span className="flex flex-col gap-1">
                    <span><StatusBadge label={stateLabel(v.state)} tone={PETTY_VOUCHER_TONE[v.state] ?? 'neutral'} /></span>
                    {v.settlement_number !== null ? <span className="text-xs text-muted-foreground">{v.settlement_number}</span> : null}
                    {v.void_reason !== null ? <span className="text-xs text-muted-foreground">{v.void_reason}</span> : null}
                </span>
            ),
        },
        { id: 'by', label: t('fin.petty.v.by'), value: (v) => v.by ?? '', cell: (v) => v.by ?? '—', hidden: true },
        ...(actions === undefined ? [] : [actions]),
    ];
}

/** The longest range the management reports and the exports answer: a range of a year (366 days) or more is refused with a 422. */
export const REPORT_MAX_DAYS = 366;

/** The days between two calendar dates (`YYYY-MM-DD`). */
export const spanDays = (from: string, to: string): number => Math.round((Date.parse(to) - Date.parse(from)) / 86_400_000);

const KNOWN_DEPARTMENTS = ['front_office', 'housekeeping', 'laundry', 'fnb', 'kitchen', 'maintenance', 'hr', 'finance', 'purchasing', 'general'];
const SUPPLIER_METHODS = ['transfer', 'cash', 'giro', 'other'];
const RECEIVABLE_METHODS = ['transfer', 'giro', 'online'];

/** A department in the words the owners use, the same as on the expense accounts. */
export function useDepartmentLabel() {
    const { t } = useTranslation();

    return (department: string): string => {
        return KNOWN_DEPARTMENTS.includes(department) ? t(`inv.dept.${department}` as MessageKey) : department;
    };
}

/** A margin sent in basis points (hundredths of a percent) as a percentage; a dash when there is no revenue to measure it against. */
export function useMarginLabel() {
    const { locale } = useTranslation();
    const percent = new Intl.NumberFormat(locale, { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 });

    return (basisPoints: number | null): string => (basisPoints === null ? '—' : percent.format(basisPoints / 10_000));
}

/** Text colour of a result or a movement: red when negative. */
export const signClass = (minor: number): string => (minor < 0 ? 'text-danger' : '');

/** An amount typed by a person that may be negative ("-1500,50" or "−1500"), in minor units; null when it is not a clear amount. */
export function parseSignedMajorToMinor(text: string, currency: string): number | null {
    const trimmed = text.trim();
    const negative = trimmed.startsWith('-') || trimmed.startsWith('\u2212');
    const minor = parseMajorToMinor(negative ? trimmed.slice(1).trim() : trimmed, currency);

    return minor === null ? null : negative && minor !== 0 ? -minor : minor;
}

export { minorToMajorText };

/** The management P&L as the server sends it. Money is in minor units; a margin in basis points. */
export type PnlOutlet = { code: string; name: string | null; revenue_minor: number };
export type PnlAccount = { code: string; name: string; category: string; expenses_minor: number; petty_minor: number; recurring_minor: number };
export type PnlAmounts = {
    revenue_minor: number; service_charge_minor: number; expenses_minor: number; petty_minor: number; recurring_minor: number; stock_minor: number; cost_total_minor: number; result_minor: number; margin_bp: number | null;
};
export type PnlDepartment = PnlAmounts & { department: string; outlets: PnlOutlet[]; accounts: PnlAccount[] };
export type PnlMapping = { code: string; name: string | null; department: string; mapped: boolean };
export type PnlReport = {
    from: string; to: string; today: string; currency: string; departments: PnlDepartment[]; totals: PnlAmounts;
    notes: { unclassified_payables_minor: number; goods_payables_minor: number; unmapped_outlets: string[]; unverified_days: number };
    mapping: PnlMapping[]; department_list: string[]; may: { manage: boolean };
};

/** The stock value report and the food cost report as the server sends them. */
export type StockValueAmounts = { opening_minor: number; received_minor: number; returned_minor: number; issued_minor: number; written_off_minor: number; adjusted_minor: number; closing_minor: number };
export type StockValueReport = {
    from: string; to: string; today: string; opening_as_of: string; currency: string; departments: (StockValueAmounts & { department: string })[];
    totals: StockValueAmounts; locations: { id: string; code: string; name: string; value_minor: number }[];
};
export type FoodCostDepartment = { department: string; issued_minor: number; written_off_minor: number; adjusted_minor: number; cost_minor: number; purchased_minor: number };
export type FoodCostReport = {
    from: string; to: string; today: string; currency: string; outlets: { code: string; name: string | null; sales_minor: number }[]; sales_minor: number;
    departments: FoodCostDepartment[]; totals: Omit<FoodCostDepartment, 'department'>; food_cost_bp: number | null; waste_bp: number | null;
    target: { bp: number; is_default: boolean; lock_version: number | null; allowed_minor: number }; status: 'no_sales' | 'over' | 'within'; over_minor: number;
    notes: { outlets_mapped: boolean; unverified_days: number }; may: { manage: boolean };
};

/** The cash flow summary as the server sends it. */
export type CashGroup = 'cash' | 'bank';
export type CashReceipt = { source: 'guest' | 'receivable'; method: string; group: CashGroup; amount_minor: number; received_minor: number; paid_back_minor: number };
export type CashPayment = { source: 'supplier' | 'petty' | 'recurring'; method: string; group: CashGroup; amount_minor: number };
export type CashSplit = { cash: number; bank: number; total: number };
export type CashBalance = { opening_minor: number; closing_minor: number | null };
export type CashBalances = { opening_date: string; lock_version: number; reached: boolean; cash: CashBalance; bank: CashBalance };
export type CashFlowReport = {
    from: string; to: string; today: string; currency: string; receipts: CashReceipt[]; payments: CashPayment[];
    totals: { in: CashSplit; out: CashSplit }; net: CashSplit; balances: CashBalances | null; may: { manage: boolean };
};

/** How a cash flow line was paid, in the words of the screen it came from. */
export function useCashMethodLabel() {
    const { t } = useTranslation();
    const receiptMethod = useReceiptMethodLabel();

    return (source: string, method: string): string => {
        if (source === 'guest') return receiptMethod(method);

        if (source === 'receivable') return RECEIVABLE_METHODS.includes(method) ? t(`fin.ar.method.${method}` as MessageKey) : method;

        return SUPPLIER_METHODS.includes(method) ? t(`fin.method.${method}` as MessageKey) : method;
    };
}

/** A correction to a booked revenue day as the corrections list, the correction page and the revenue day send it. */
export type CorrectionHead = {
    id: string; number: string; status: 'pending' | 'approved' | 'rejected'; day_date: string; reason: string; line_count: number; revenue_minor: number; received_minor: number;
    requested_by: string | null; requested_at: string; decided_by: string | null; effective_date: string | null;
};

export const CORRECTION_STATUSES = ['pending', 'approved', 'rejected'] as const;
export const CORRECTION_TONE: Record<string, StatusTone> = { pending: 'pending', approved: 'success', rejected: 'danger' };

/** A reconciliation exception (refund, chargeback, settlement difference or payment of unknown status). Not the cash differences of shifts, which have their own tab. */
export const RECON_KINDS = ['refund', 'chargeback', 'settlement_discrepancy', 'unknown_payment', 'late_sale'] as const;
export const RECON_STATUSES = ['open', 'matched', 'adjusted', 'waived'] as const;
export const RECON_TONE: Record<string, StatusTone> = { open: 'danger', matched: 'success', adjusted: 'info', waived: 'neutral' };

/** The currency of a page whose props carry none (recurring expenses and the budget grid): the property's own. */
export const DEFAULT_CURRENCY = 'IDR';

/** A recurring expense as the list and the detail page send it (FR-FIN-016). */
export const RECURRING_FREQUENCIES = ['monthly', 'quarterly', 'yearly'] as const;
export type RecurringFrequency = (typeof RECURRING_FREQUENCIES)[number];
export type RecurringState = 'upcoming' | 'due_soon' | 'overdue' | 'finished' | 'paused';
export const RECURRING_STATES: RecurringState[] = ['overdue', 'due_soon', 'upcoming', 'paused', 'finished'];
export type RecurringAccount = { id: string; code: string; name: string; department: string };
export type RecurringItem = {
    id: string; name: string; expense_account_id: string; account_code: string; account_name: string; department: string; payee: string | null; amount_minor: number; frequency: RecurringFrequency;
    due_day: number; start_month: string; end_date: string | null; remind_days: number; next_due: string | null; days_to_due: number | null; days_overdue: number; state: RecurringState; active: boolean;
    lock_version: number; upcoming: string[];
};
export type RecurringHistory = {
    due_date: string; status: 'paid' | 'skipped'; amount_minor: number | null; paid_on: string | null; method: string | null; reference: string | null; note: string | null; by: string | null;
};

/** The ways a recurring expense can be paid, and those that need the number of the transfer or giro. */
export const RECURRING_METHODS = ['cash', 'transfer', 'giro', 'other'] as const;
export const REFERENCE_METHODS: readonly string[] = ['transfer', 'giro'];
export const RECURRING_TONE: Record<RecurringState, StatusTone> = { overdue: 'danger', due_soon: 'warning', upcoming: 'neutral', paused: 'neutral', finished: 'neutral' };

/** Whether the next due date of an expense can be settled now. */
export const canSettle = (item: Pick<RecurringItem, 'state'>): boolean => item.state === 'overdue' || item.state === 'due_soon' || item.state === 'upcoming';

/** The state of a recurring expense: overdue red, due soon amber, upcoming neutral; paused and ended are muted. */
export function RecurringStateBadge({ state }: { state: RecurringState }) {
    const { t } = useTranslation();
    const muted = state === 'paused' || state === 'finished';

    return <StatusBadge className={muted ? 'border-border bg-surface text-muted-foreground' : undefined} label={t(`fin.rec.state.${state}` as MessageKey)} tone={RECURRING_TONE[state]} />;
}

/** How far the next due date is in words ("In 5 days", "Due today", "3 days overdue"); nothing for an expense that has ended. */
export function RecurringDays({ item }: { item: Pick<RecurringItem, 'days_to_due' | 'days_overdue' | 'state'> }) {
    const { t } = useTranslation();

    if (item.days_to_due === null) return null;

    const text = item.days_to_due < 0 ? t('fin.overdueDays', { days: item.days_overdue }) : item.days_to_due === 0 ? t('fin.dueToday') : t('fin.dueIn', { days: item.days_to_due });

    return <span className={cn('text-xs text-muted-foreground', item.state === 'overdue' && 'font-medium text-danger', item.state === 'due_soon' && 'font-medium text-warning')}>{text}</span>;
}

/** A month sent as `YYYY-MM` in the words of the language: "October 2026". */
export function useMonthLabel() {
    const { locale } = useTranslation();
    const formatter = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' });

    return (month: string): string => formatter.format(new Date(`${month}-01T00:00:00Z`));
}

/** The name of a month number (1 to 12): "October". */
export function useMonthName() {
    const { locale } = useTranslation();
    const formatter = new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' });

    return (month: number): string => formatter.format(new Date(Date.UTC(2000, month - 1, 1)));
}

/** The schedule of a recurring expense in words: "Every month on day 25, until 31 December 2027". */
export function useRecurringSchedule() {
    const { t } = useTranslation();
    const format = useFormatters();
    const monthLabel = useMonthLabel();

    return (item: Pick<RecurringItem, 'frequency' | 'due_day' | 'start_month' | 'end_date'>): string => {
        const base = t(`fin.rec.schedule.${item.frequency}` as MessageKey, { day: item.due_day, month: monthLabel(item.start_month) });

        return item.end_date === null ? base : t('fin.rec.schedule.until', { schedule: base, date: format.date(item.end_date) });
    };
}

/** What was paid against what was expected: as expected, above it (amber, a higher cost) or below it. Colour, icon and words together. */
export function RecurringDifference({ currency, minor }: { currency: string; minor: number }) {
    const { t } = useTranslation();
    const format = useFormatters();

    if (minor === 0) return <span className="text-sm text-muted-foreground">{t('fin.rec.asExpected')}</span>;

    return minor > 0
        ? <StatusBadge label={t('fin.rec.moreThan', { amount: format.money(minor, currency) })} tone="warning" />
        : <StatusBadge label={t('fin.rec.lessThan', { amount: format.money(-minor, currency) })} tone="info" />;
}

/** The budget of a year as the server sends it: the months that have a budget, by department. */
export type BudgetRow = { month: number; department: string; revenue_minor: number; cost_minor: number };
export type BudgetOverview = { year: number; departments: string[]; rows: BudgetRow[]; may: { manage: boolean } };

/** The budget against actual of a department (or the total): money in minor units, "used" in basis points of the budget, null without a budget. */
export type BudgetComparison = {
    budget_revenue_minor: number; actual_revenue_minor: number; budget_cost_minor: number; actual_cost_minor: number; revenue_variance_minor: number; cost_variance_minor: number;
    budget_result_minor: number; actual_result_minor: number; result_variance_minor: number; revenue_used_bp: number | null; cost_used_bp: number | null;
};
export type BudgetDepartment = BudgetComparison & { department: string };
export type BudgetMonth = { month: string; budget_revenue_minor: number; actual_revenue_minor: number; budget_cost_minor: number; actual_cost_minor: number };
export type BudgetReport = {
    from: string; to: string; currency: string; departments: BudgetDepartment[]; totals: BudgetComparison; monthly: BudgetMonth[];
    notes: { unverified_days: number; unclassified_payables_minor: number }; may: { manage: boolean };
};
