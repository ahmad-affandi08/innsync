import { Button } from '@/components/ui/button';
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

    return (
        <ReportingShell description={t('rpt.ldy.description')} title={t('rpt.ldy.title')} wide>
            <PeriodPicker from={r.meta.period.from} path="/reports/laundry" preset={r.meta.period.preset} to={r.meta.period.to} />
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/laundry/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            <ReportMeta meta={r.meta} />
            <p className="text-xs text-muted-foreground">{t('rpt.ldy.costNote')}</p>
            {r.rows.length === 0 ? <EmptyState title={t('rpt.ldy.empty')} /> : (
                <table className="w-full text-left text-sm" data-testid="ldy-rows">
                    <thead><tr className="text-xs text-muted-foreground">{['date', 'received', 'pieces', 'express', 'ready', 'onTime', 'average', 'charged', 'difference', 'cancelled'].map((k, i) => <th className={i === 0 ? 'py-1 font-medium' : undefined} key={k} scope="col">{t(`rpt.ldy.col.${k}` as 'rpt.ldy.col.date')}</th>)}</tr></thead>
                    <tbody>{r.rows.map((x) => (
                        <tr className="border-t border-border" key={x.date}><th className="py-1 font-medium" scope="row">{format.date(x.date)}</th><td>{x.received}</td><td>{x.pieces}</td><td>{x.express}</td><td>{x.ready}</td><td>{x.on_time}</td><td>{minutes(x.average_seconds)}</td><td>{format.money(x.charged_minor, context.currency)}</td><td>{x.discrepancies}</td><td>{x.cancelled}</td></tr>
                    ))}</tbody>
                    <tfoot><tr className="border-t border-border font-medium" data-testid="ldy-totals"><th className="py-1" scope="row">{t('rpt.flash.total')}</th><td>{r.totals.received}</td><td>{r.totals.pieces}</td><td>{r.totals.express}</td><td>{r.totals.ready}</td><td>{r.totals.on_time}{r.totals.on_time_percent === null ? '' : ` (${r.totals.on_time_percent}%)`}</td><td>{minutes(r.totals.average_seconds)}</td><td>{format.money(r.totals.charged_minor, context.currency)}</td><td>{r.totals.discrepancies}</td><td>{r.totals.cancelled}</td></tr></tfoot>
                </table>
            )}
        </ReportingShell>
    );
}
