import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { useTranslation } from '@/shared/i18n/i18n';
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
