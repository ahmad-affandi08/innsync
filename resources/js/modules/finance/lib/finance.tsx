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
