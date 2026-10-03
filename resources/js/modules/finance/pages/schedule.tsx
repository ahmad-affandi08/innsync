import { router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { DueBadge, type PayableRow } from '@/modules/finance/lib/finance';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Schedule = { today: string; days: number; until: string; rows: PayableRow[]; overdue_minor: number; due_minor: number };

const HORIZONS = [7, 14, 30, 60] as const;

/** What is overdue and what falls due within the chosen number of days, soonest first. */
export default function SchedulePage({ schedule }: { schedule: Schedule }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const money = (minor: number, currency: string) => format.money(minor, currency);
    const currency = schedule.rows[0]?.currency ?? 'IDR';
    const rows = [...schedule.rows].sort((a, b) => a.due_date.localeCompare(b.due_date));

    const columns: DataGridColumn<PayableRow>[] = [
        { id: 'due', label: t('fin.col.dueDate'), value: (p) => p.due_date, cell: (p) => format.date(p.due_date), rowHeader: true },
        { id: 'when', label: t('fin.col.due'), value: (p) => p.days_to_due, cell: (p) => <DueBadge row={p} /> },
        { id: 'supplier', label: t('fin.col.supplier'), value: (p) => p.supplier_name, searchText: (p) => `${p.supplier_code} ${p.supplier_name}` },
        { id: 'document', label: t('fin.col.document'), value: (p) => p.document_number },
        { id: 'ours', label: t('fin.col.ourNumber'), value: (p) => p.source_number, hidden: true },
        { id: 'balance', label: t('fin.col.balance'), align: 'right', value: (p) => p.balance_minor, cell: (p) => money(p.balance_minor, p.currency) },
        { id: 'actions', label: t('inv.col.actions'), cell: (p) => <Button onClick={() => router.visit(`/finance/payables/${p.id}`)} size="sm" type="button" variant="outline">{t('fin.sch.open')}</Button> },
    ];

    return (
        <FinanceShell description={t('fin.sch.description')} title={t('fin.sch.title')} wide>
            <div className="flex flex-wrap items-end gap-3">
                <div className="w-full max-w-xs">
                    <Select aria-label={t('fin.sch.horizon')} onChange={(e) => router.get('/finance/schedule', { days: e.target.value }, { preserveScroll: true })} searchable={false} value={String(schedule.days)}>
                        {HORIZONS.map((d) => <option key={d} value={d}>{t('fin.sch.days', { days: d })}</option>)}
                    </Select>
                </div>
                <p className="pb-2 text-sm text-muted-foreground">{t('fin.sch.until', { date: format.date(schedule.until) })}</p>
            </div>

            <div className="grid gap-3 sm:grid-cols-2" data-testid="schedule-summary">
                <Metric label={t('fin.sch.overdue')} value={money(schedule.overdue_minor, currency)} />
                <Metric label={t('fin.sch.due')} value={money(schedule.due_minor, currency)} />
            </div>

            <DataGrid caption={t('fin.sch.title')} columns={columns} empty={<EmptyState title={t('fin.sch.empty')} />} getRowId={(p) => p.id} id="fin.schedule" rows={rows} testId="schedule" />
        </FinanceShell>
    );
}
