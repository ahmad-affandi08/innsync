import { router, usePage } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { PayableStatus, type PayableRow } from '@/modules/finance/lib/finance';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Credit = { id: string; supplier_name: string; credit_note_number: string; source_number: string; amount_minor: number; applied_minor: number; available_minor: number; business_date: string; currency: string };
type Overview = {
    today: string; currency: string; payables: PayableRow[]; owed_minor: number; overdue_minor: number; due_soon_minor: number; suppliers: { id: string; name: string }[]; credits: Credit[];
    may: { manage: boolean; pay: boolean };
};

const STATUSES = ['open', 'overdue', 'paid', 'all'] as const;

/** What the property owes its suppliers, and the credits suppliers have given. */
export default function PayablesPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const money = (minor: number, currency = overview.currency) => format.money(minor, currency);
    const current = new URLSearchParams(usePage().url.split('?')[1] ?? '').get('supplier') ?? '';

    function filter(next: { status?: string; supplier?: string }) {
        const query: Record<string, string> = { status: next.status ?? status };
        const who = next.supplier ?? current;

        if (who !== '') query.supplier = who;
        router.get('/finance/payables', query, { preserveScroll: true });
    }

    const columns: DataGridColumn<PayableRow>[] = [
        { id: 'supplier', label: t('fin.col.supplier'), value: (p) => p.supplier_name, searchText: (p) => `${p.supplier_code} ${p.supplier_name}`, rowHeader: true },
        { id: 'document', label: t('fin.col.document'), value: (p) => p.document_number },
        { id: 'ours', label: t('fin.col.ourNumber'), value: (p) => p.source_number },
        { id: 'due', label: t('fin.col.dueDate'), value: (p) => p.due_date, cell: (p) => format.date(p.due_date) },
        { id: 'amount', label: t('fin.col.amount'), align: 'right', value: (p) => p.amount_minor, cell: (p) => money(p.amount_minor, p.currency) },
        { id: 'paid', label: t('fin.col.paid'), align: 'right', value: (p) => p.paid_minor, cell: (p) => money(p.paid_minor, p.currency) },
        { id: 'balance', label: t('fin.col.balance'), align: 'right', value: (p) => p.balance_minor, cell: (p) => money(p.balance_minor, p.currency) },
        { id: 'state', label: t('inv.col.status'), value: (p) => (p.overdue ? 'overdue' : p.status), cell: (p) => <PayableStatus row={p} /> },
        { id: 'actions', label: t('inv.col.actions'), cell: (p) => <Button onClick={() => router.visit(`/finance/payables/${p.id}`)} size="sm" type="button" variant="outline">{t('fin.pay.open')}</Button> },
    ];

    const creditColumns: DataGridColumn<Credit>[] = [
        { id: 'supplier', label: t('fin.col.supplier'), value: (c) => c.supplier_name, rowHeader: true },
        { id: 'note', label: t('fin.cred.note'), value: (c) => c.credit_note_number },
        { id: 'source', label: t('fin.cred.source'), value: (c) => c.source_number },
        { id: 'date', label: t('fin.cred.date'), value: (c) => c.business_date, cell: (c) => format.date(c.business_date) },
        { id: 'amount', label: t('fin.cred.amount'), align: 'right', value: (c) => c.amount_minor, cell: (c) => money(c.amount_minor, c.currency) },
        { id: 'applied', label: t('fin.cred.applied'), align: 'right', value: (c) => c.applied_minor, cell: (c) => money(c.applied_minor, c.currency) },
        { id: 'available', label: t('fin.cred.available'), align: 'right', value: (c) => c.available_minor, cell: (c) => money(c.available_minor, c.currency) },
    ];

    return (
        <FinanceShell description={t('fin.pay.description')} title={t('fin.pay.title')} wide>
            <div className="grid gap-3 sm:grid-cols-3" data-testid="payable-summary">
                <Metric label={t('fin.pay.owed')} value={money(overview.owed_minor)} />
                <Metric label={t('fin.pay.overdue')} value={money(overview.overdue_minor)} />
                <Metric label={t('fin.pay.dueSoon')} value={money(overview.due_soon_minor)} />
            </div>

            <div className="flex flex-wrap gap-3">
                <div className="w-full max-w-xs">
                    <Select aria-label={t('fin.pay.status')} onChange={(e) => filter({ status: e.target.value })} searchable={false} value={status}>
                        {STATUSES.map((s) => <option key={s} value={s}>{t(`fin.filter.${s}` as MessageKey)}</option>)}
                    </Select>
                </div>
                <div className="w-full max-w-xs">
                    <Select aria-label={t('fin.pay.supplier')} onChange={(e) => filter({ supplier: e.target.value })} value={current}>
                        <option value="">{t('fin.pay.allSuppliers')}</option>
                        {overview.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </Select>
                </div>
            </div>

            <DataGrid caption={t('fin.pay.title')} columns={columns} empty={<EmptyState title={t('fin.pay.empty')} />} getRowId={(p) => p.id} id="fin.payables" rows={overview.payables} testId="payables" />

            <section aria-labelledby="fin-credits-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-credits-h">{t('fin.pay.credits')}</h2>
                <DataGrid caption={t('fin.pay.credits')} columns={creditColumns} empty={<EmptyState title={t('fin.pay.creditsEmpty')} />} getRowId={(c) => c.id} id="fin.credits" rows={overview.credits} testId="credits" />
            </section>
        </FinanceShell>
    );
}
