import { router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { PAYMENT_STATUSES, PAYMENT_TONE } from '@/modules/finance/lib/finance';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Payment = {
    id: string; number: string; status: string; supplier_name: string; payable_number: string; document_number: string; amount_minor: number; currency: string; method: string;
    paid_on: string; reference: string | null; created_by_name: string | null; mine: boolean; reversal_number: string | null; reverses_number: string | null;
};
type Overview = { payments: Payment[]; methods: string[]; may: { record: boolean } };

/** Every payment to a supplier, newest first. */
export default function PaymentsPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const label = (s: string) => t(`fin.pmt.status.${s}` as MessageKey);
    const method = (m: string) => t(`fin.method.${m}` as MessageKey);

    const signed = (p: Payment) => (p.status === 'reversal' ? -p.amount_minor : p.amount_minor);
    const related = (p: Payment) => (p.reversal_number !== null ? t('fin.pmt.reversedBy', { number: p.reversal_number }) : p.reverses_number !== null ? t('fin.pmt.takesBack', { number: p.reverses_number }) : '');

    const columns: DataGridColumn<Payment>[] = [
        { id: 'number', label: t('fin.col.number'), value: (p) => p.number, rowHeader: true },
        { id: 'supplier', label: t('fin.col.supplier'), value: (p) => p.supplier_name },
        { id: 'document', label: t('fin.col.document'), value: (p) => p.document_number },
        { id: 'ours', label: t('fin.col.ourNumber'), value: (p) => p.payable_number, hidden: true },
        { id: 'amount', label: t('fin.col.amount'), align: 'right', value: (p) => signed(p), cell: (p) => format.money(signed(p), p.currency) },
        { id: 'method', label: t('fin.col.method'), value: (p) => p.method, filter: 'select', filterLabel: method, cell: (p) => method(p.method) },
        { id: 'paidOn', label: t('fin.col.paidOn'), value: (p) => p.paid_on, cell: (p) => format.date(p.paid_on) },
        { id: 'reference', label: t('fin.col.reference'), value: (p) => p.reference ?? '', cell: (p) => p.reference ?? '—' },
        { id: 'by', label: t('fin.col.madeBy'), value: (p) => p.created_by_name ?? '', cell: (p) => p.created_by_name ?? '—', hidden: true },
        { id: 'state', label: t('inv.col.status'), value: (p) => p.status, filter: 'select', filterLabel: label, cell: (p) => <StatusBadge label={label(p.status)} tone={PAYMENT_TONE[p.status] ?? 'neutral'} /> },
        { id: 'related', label: t('fin.pmt.related'), value: related, cell: (p) => related(p) || '—' },
        { id: 'actions', label: t('inv.col.actions'), cell: (p) => <Button onClick={() => router.visit(`/finance/payments/${p.id}`)} size="sm" type="button" variant="outline">{t('fin.pmt.open')}</Button> },
    ];

    return (
        <FinanceShell description={t('fin.pmt.description')} title={t('fin.pmt.title')} wide>
            <div className="max-w-xs">
                <Select aria-label={t('inv.col.status')} onChange={(e) => router.get('/finance/payments', e.target.value ? { status: e.target.value } : {}, { preserveScroll: true })} searchable={false} value={status}>
                    <option value="">{t('fin.pmt.allStatuses')}</option>
                    {PAYMENT_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                </Select>
            </div>

            <DataGrid caption={t('fin.pmt.title')} columns={columns} empty={<EmptyState title={t('fin.pmt.empty')} />} getRowId={(p) => p.id} id="fin.payments" rows={overview.payments} testId="payments" />
        </FinanceShell>
    );
}
