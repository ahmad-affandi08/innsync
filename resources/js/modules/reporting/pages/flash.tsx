import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import type { MessageKey } from '@/locales/en/index';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportFilterBar, useReportFilters, type FilterOptions } from '@/modules/reporting/components/report-filters';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Day = { business_date: string; occupancy_bp: number; in_house: number; rooms_total: number; arrivals: number; departures: number; room_nights: number; adr_minor: number; revenue: { total: number }; collected: number };
type Report = { options: FilterOptions; meta: Meta & { period: { preset: string; from: string; to: string } }; days: Day[]; totals: { room_nights: number; revenue: { total: number }; collected: number }; costs: Costs; events: { action: string; count: number }[] };
type CostRow = { department: string; supplier_minor: number; petty_minor: number; recurring_minor: number; stock_minor: number; cost_total_minor: number };
type Costs = { available: false } | { available: true; departments: CostRow[]; totals: Omit<CostRow, 'department'> };

export default function FlashPage({ context, report: r }: { context: { currency: string }; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const filters = useReportFilters();
    const q = new URLSearchParams({ from: r.meta.period.from, to: r.meta.period.to, ...filters.values }).toString();

    const money = (minor: number) => format.money(minor, context.currency);
    const costColumns = (totals: Omit<CostRow, 'department'>): DataGridColumn<CostRow>[] => [
        { id: 'department', label: t('rpt.flash.department'), value: (c) => c.department, rowHeader: true, cell: (c) => t(`inv.dept.${c.department}` as MessageKey) },
        { id: 'supplier', label: t('rpt.flash.costSupplier'), align: 'right', value: (c) => c.supplier_minor, cell: (c) => money(c.supplier_minor), footer: money(totals.supplier_minor) },
        { id: 'petty', label: t('rpt.flash.costPetty'), align: 'right', value: (c) => c.petty_minor, cell: (c) => money(c.petty_minor), footer: money(totals.petty_minor) },
        { id: 'recurring', label: t('rpt.flash.costRecurring'), align: 'right', value: (c) => c.recurring_minor, cell: (c) => money(c.recurring_minor), footer: money(totals.recurring_minor) },
        { id: 'stock', label: t('rpt.flash.costStock'), align: 'right', value: (c) => c.stock_minor, cell: (c) => money(c.stock_minor), footer: money(totals.stock_minor) },
        { id: 'total', label: t('rpt.flash.costTotal'), align: 'right', value: (c) => c.cost_total_minor, cell: (c) => money(c.cost_total_minor), footer: <span data-testid="total-cost">{money(totals.cost_total_minor)}</span> },
    ];
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
            <PeriodPicker extra={filters.values} from={r.meta.period.from} path="/reports/flash" preset={r.meta.period.preset} to={r.meta.period.to} />
            <ReportFilterBar options={r.options} path="/reports/flash" />
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/flash/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button asChild size="sm" variant="outline"><a href={`/reports/flash/export?${q}&format=pdf`}>{t('rpt.export.pdf')}</a></Button>
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
            <section aria-labelledby="flash-costs-h" className="flex flex-col gap-2" data-testid="flash-costs">
                <h2 className="text-lg font-semibold" id="flash-costs-h">{t('rpt.flash.costs')}</h2>
                {r.costs.available ? (
                    r.costs.departments.length === 0 ? <p className="text-sm text-muted-foreground">{t('rpt.flash.costsNone')}</p> : (
                        <DataGrid
                            caption={t('rpt.flash.costs')}
                            columns={costColumns(r.costs.totals)}
                            empty={<EmptyState title={t('rpt.flash.costsNone')} />}
                            footerLabel={t('rpt.flash.total')}
                            getRowId={(c) => c.department}
                            id="rpt.flash.costs"
                            rows={r.costs.departments}
                        />
                    )
                ) : <p className="text-sm text-muted-foreground">{t('rpt.flash.costsHidden')}</p>}
            </section>
            <section aria-labelledby="flash-events-h" className="flex flex-col gap-2" data-testid="flash-events">
                <h2 className="text-lg font-semibold" id="flash-events-h">{t('rpt.flash.events')}</h2>
                {r.events.length === 0 ? <p className="text-sm text-muted-foreground">{t('rpt.flash.eventsNone')}</p> : (
                    <ul className="grid gap-1 text-sm sm:grid-cols-2">
                        {r.events.map((e) => <li className="flex justify-between gap-2 border-b border-border py-1" key={e.action}><span>{t(`rpt.flash.event.${e.action}` as MessageKey)}</span><span className="tabular-nums font-medium">{e.count}</span></li>)}
                    </ul>
                )}
            </section>
        </ReportingShell>
    );
}
