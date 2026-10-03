import { type DataGridColumn } from '@/components/ui/data-grid';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

/** A payable as the overview, the schedule and the payable page send it. */
export type PayableRow = {
    id: string; supplier_id: string; supplier_code: string; supplier_name: string; source_number: string; document_number: string; due_date: string;
    amount_minor: number; paid_minor: number; credit_minor: number; pending_minor: number; balance_minor: number; available_minor: number; currency: string;
    status: 'open' | 'partial' | 'paid'; overdue: boolean; days_to_due: number; days_overdue: number;
};

export const PAYABLE_TONE: Record<string, StatusTone> = { open: 'info', partial: 'pending', paid: 'success' };
export const PAYMENT_TONE: Record<string, StatusTone> = { pending_approval: 'pending', paid: 'success', rejected: 'danger', cancelled: 'neutral' };
export const PAYMENT_STATUSES = ['pending_approval', 'paid', 'rejected', 'cancelled'] as const;
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
