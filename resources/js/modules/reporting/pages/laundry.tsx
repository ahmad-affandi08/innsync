import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = { received: number; pieces: number; express: number; ready: number; on_time: number; average_seconds: number | null; charged_minor: number; discrepancies: number; cancelled: number };
type Report = {
    meta: Meta & { period: { preset: string; from: string; to: string } };
    rows: (Row & { date: string })[];
    totals: Row & { on_time_percent: number | null };
};

/** Guest laundry volume, speed and charges per day (FR-LDY-010). */
export default function LaundryReportPage({ context, report: r }: { context: { currency: string }; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const q = new URLSearchParams({ from: r.meta.period.from, to: r.meta.period.to }).toString();
    const minutes = (seconds: number | null) => (seconds === null ? '—' : t('rpt.ldy.minutes', { minutes: format.number(Math.round(seconds / 60)) }));
    const col = (k: string) => t(`rpt.ldy.col.${k}` as 'rpt.ldy.col.date');
    const columns: DataGridColumn<Report['rows'][number]>[] = [
        { id: 'date', label: col('date'), value: (x) => x.date, searchText: (x) => `${x.date} ${format.date(x.date)}`, rowHeader: true, cell: (x) => format.date(x.date), footer: <span data-testid="ldy-totals">{t('rpt.flash.total')}</span> },
        { id: 'received', label: col('received'), align: 'right', value: (x) => x.received, footer: r.totals.received },
        { id: 'pieces', label: col('pieces'), align: 'right', value: (x) => x.pieces, footer: r.totals.pieces },
        { id: 'express', label: col('express'), align: 'right', value: (x) => x.express, footer: r.totals.express },
        { id: 'ready', label: col('ready'), align: 'right', value: (x) => x.ready, footer: r.totals.ready },
        { id: 'onTime', label: col('onTime'), align: 'right', value: (x) => x.on_time, footer: `${r.totals.on_time}${r.totals.on_time_percent === null ? '' : ` (${r.totals.on_time_percent}%)`}` },
        { id: 'average', label: col('average'), align: 'right', value: (x) => x.average_seconds, cell: (x) => minutes(x.average_seconds), footer: minutes(r.totals.average_seconds) },
        { id: 'charged', label: col('charged'), align: 'right', value: (x) => x.charged_minor, cell: (x) => format.money(x.charged_minor, context.currency), footer: format.money(r.totals.charged_minor, context.currency) },
        { id: 'difference', label: col('difference'), align: 'right', value: (x) => x.discrepancies, footer: r.totals.discrepancies },
        { id: 'cancelled', label: col('cancelled'), align: 'right', value: (x) => x.cancelled, footer: r.totals.cancelled },
    ];

    return (
        <ReportingShell description={t('rpt.ldy.description')} title={t('rpt.ldy.title')} wide>
            <PeriodPicker from={r.meta.period.from} path="/reports/laundry" preset={r.meta.period.preset} to={r.meta.period.to} />
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/laundry/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            <ReportMeta meta={r.meta} />
            <p className="text-xs text-muted-foreground">{t('rpt.ldy.costNote')}</p>
            <DataGrid
                caption={t('rpt.ldy.title')}
                columns={columns}
                empty={<EmptyState title={t('rpt.ldy.empty')} />}
                getRowId={(x) => x.date}
                id="rpt.laundry"
                rows={r.rows}
                testId="ldy-rows"
            />
        </ReportingShell>
    );
}
