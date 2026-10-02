import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Day = { business_date: string; occupancy_bp: number; in_house: number; rooms_total: number; arrivals: number; departures: number; room_nights: number; adr_minor: number; revenue: { total: number }; collected: number };
type Report = { meta: Meta & { period: { preset: string; from: string; to: string } }; days: Day[]; totals: { room_nights: number; revenue: { total: number }; collected: number }; costs_note: string };

export default function FlashPage({ context, report: r }: { context: { currency: string }; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const q = new URLSearchParams({ from: r.meta.period.from, to: r.meta.period.to }).toString();

    const money = (minor: number) => format.money(minor, context.currency);
    const columns: DataGridColumn<Day>[] = [
        { id: 'date', label: t('rpt.flash.date'), value: (d) => d.business_date, rowHeader: true, cell: (d) => format.date(d.business_date) },
        { id: 'occupancy', label: t('rpt.flash.occupancy'), align: 'right', value: (d) => d.occupancy_bp, cell: (d) => `${(d.occupancy_bp / 100).toFixed(1)}% (${d.in_house}/${d.rooms_total})` },
        { id: 'arrivals', label: t('rpt.flash.arrivals'), align: 'right', value: (d) => d.arrivals },
        { id: 'departures', label: t('rpt.flash.departures'), align: 'right', value: (d) => d.departures },
        { id: 'nights', label: t('rpt.flash.nights'), align: 'right', value: (d) => d.room_nights, footer: r.totals.room_nights },
        { id: 'adr', label: t('rpt.flash.adr'), align: 'right', value: (d) => d.adr_minor, searchText: (d) => money(d.adr_minor), cell: (d) => money(d.adr_minor) },
        { id: 'net', label: t('rpt.flash.net'), align: 'right', value: (d) => d.revenue.total, searchText: (d) => money(d.revenue.total), cell: (d) => money(d.revenue.total), footer: <span data-testid="total-net">{money(r.totals.revenue.total)}</span> },
        { id: 'collected', label: t('rpt.flash.collected'), align: 'right', value: (d) => d.collected, searchText: (d) => money(d.collected), cell: (d) => money(d.collected), footer: money(r.totals.collected) },
    ];

    return (
        <ReportingShell description={t('rpt.flash.description')} title={t('rpt.flash.title')} wide>
            <PeriodPicker from={r.meta.period.from} path="/reports/flash" preset={r.meta.period.preset} to={r.meta.period.to} />
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/flash/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            <ReportMeta meta={r.meta} />
            <DataGrid
                caption={t('rpt.flash.title')}
                columns={columns}
                empty={<EmptyState title={t('rpt.flash.empty')} />}
                footerLabel={t('rpt.flash.total')}
                getRowId={(d) => d.business_date}
                id="rpt.flash"
                rows={r.days}
            />
            <p className="text-xs text-muted-foreground">{r.costs_note}</p>
        </ReportingShell>
    );
}
