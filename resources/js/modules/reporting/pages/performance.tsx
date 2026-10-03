import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = { label: string; days_closed: number; days_in_period: number; sellable_nights: number; occupied_nights: number; room_nights: number; occupancy_bp: number; room_revenue_minor: number; adr_minor: number; revpar_minor: number };
type Report = { meta: Meta & { period: { preset: string; from: string; to: string } }; by: 'day' | 'month' | 'year'; year: number; rows: Row[] };

/** Occupancy, ADR and RevPAR per day, month or year (FR-FO-044). */
export default function PerformancePage({ context, report: r }: { context: { currency: string }; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [year, setYear] = useState(String(r.year));
    const q = new URLSearchParams(r.by === 'day' ? { by: 'day', from: r.meta.period.from, to: r.meta.period.to } : { by: r.by, year: String(r.year) }).toString();
    const label = (row: Row) => (r.by === 'day' ? format.date(row.label) : row.label);

    const money = (minor: number) => format.money(minor, context.currency);
    const columns: DataGridColumn<Row>[] = [
        { id: 'period', label: t('rpt.perf.col.period'), value: (row) => row.label, rowHeader: true, cell: (row) => label(row) },
        ...(r.by !== 'day' ? [{ id: 'closed', label: t('rpt.perf.col.closed'), align: 'right' as const, value: (row: Row) => row.days_closed, cell: (row: Row) => t('rpt.perf.closedOf', { closed: row.days_closed, days: row.days_in_period }) }] : []),
        { id: 'occupancy', label: t('rpt.perf.col.occupancy'), align: 'right', value: (row) => row.occupancy_bp, cell: (row) => `${(row.occupancy_bp / 100).toFixed(1)}%` },
        { id: 'nights', label: t('rpt.perf.col.nights'), align: 'right', value: (row) => row.room_nights },
        { id: 'revenue', label: t('rpt.perf.col.revenue'), align: 'right', value: (row) => row.room_revenue_minor, cell: (row) => money(row.room_revenue_minor) },
        { id: 'adr', label: t('rpt.perf.col.adr'), align: 'right', value: (row) => row.adr_minor, cell: (row) => money(row.adr_minor) },
        { id: 'revpar', label: t('rpt.perf.col.revpar'), align: 'right', value: (row) => row.revpar_minor, cell: (row) => money(row.revpar_minor) },
    ];

    return (
        <ReportingShell description={t('rpt.perf.description')} title={t('rpt.perf.title')} wide>
            <nav aria-label={t('rpt.perf.title')} className="flex flex-wrap gap-2 print:hidden">
                {(['day', 'month', 'year'] as const).map((by) => (
                    <Button aria-pressed={r.by === by} asChild key={by} size="sm" variant={r.by === by ? 'default' : 'outline'}><Link href={`/reports/performance?by=${by}${by === 'day' ? '' : `&year=${r.year}`}`}>{t(`rpt.perf.by.${by}` as 'rpt.perf.by.day')}</Link></Button>
                ))}
            </nav>
            {r.by === 'day'
                ? <PeriodPicker extra={{ by: 'day' }} from={r.meta.period.from} path="/reports/performance" preset={r.meta.period.preset} to={r.meta.period.to} />
                : (
                    <form className="flex flex-wrap items-end gap-3 print:hidden" onSubmit={(e) => { e.preventDefault(); router.get('/reports/performance', { by: r.by, year }); }}>
                        <FormField label={t('rpt.perf.year')}><Input inputMode="numeric" maxLength={4} onChange={(e) => setYear(e.target.value.replace(/\D/g, ''))} value={year} /></FormField>
                        <Button size="sm" type="submit" variant="outline">{t('rpt.perf.show')}</Button>
                    </form>
                )}
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/performance/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button asChild size="sm" variant="outline"><a href={`/reports/performance/export?${q}&format=pdf`}>{t('rpt.export.pdf')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            <ReportMeta meta={r.meta} />
            <DataGrid
                caption={t('rpt.perf.title')}
                columns={columns}
                empty={<EmptyState title={t('rpt.perf.empty')} />}
                getRowId={(row) => row.label}
                id={`rpt.perf.${r.by}`}
                rowTestId={(row) => `row-${row.label}`}
                rows={r.rows}
                testId="performance-table"
            />
            <p className="text-xs text-muted-foreground">{t('rpt.perf.formula')}</p>
        </ReportingShell>
    );
}
