import { Button } from '@/components/ui/button';
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

    return (
        <ReportingShell description={t('rpt.flash.description')} title={t('rpt.flash.title')} wide>
            <PeriodPicker from={r.meta.period.from} path="/reports/flash" preset={r.meta.period.preset} to={r.meta.period.to} />
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/flash/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            <ReportMeta meta={r.meta} />
            {r.days.length === 0 ? <EmptyState title={t('rpt.flash.empty')} /> : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('rpt.flash.date')}</th><th scope="col">{t('rpt.flash.occupancy')}</th><th scope="col">{t('rpt.flash.arrivals')}</th><th scope="col">{t('rpt.flash.departures')}</th><th scope="col">{t('rpt.flash.nights')}</th><th scope="col">{t('rpt.flash.adr')}</th><th scope="col">{t('rpt.flash.net')}</th><th scope="col">{t('rpt.flash.collected')}</th></tr></thead>
                        <tbody>{r.days.map((d) => (
                            <tr className="border-t border-border" key={d.business_date}><th className="py-1 font-medium" scope="row">{format.date(d.business_date)}</th><td>{(d.occupancy_bp / 100).toFixed(1)}% ({d.in_house}/{d.rooms_total})</td><td>{d.arrivals}</td><td>{d.departures}</td><td>{d.room_nights}</td><td>{format.money(d.adr_minor, context.currency)}</td><td>{format.money(d.revenue.total, context.currency)}</td><td>{format.money(d.collected, context.currency)}</td></tr>
                        ))}</tbody>
                        <tfoot><tr className="border-t border-border font-medium"><th className="py-1" scope="row">{t('rpt.flash.total')}</th><td /><td /><td /><td>{r.totals.room_nights}</td><td /><td data-testid="total-net">{format.money(r.totals.revenue.total, context.currency)}</td><td>{format.money(r.totals.collected, context.currency)}</td></tr></tfoot>
                    </table>
                </div>
            )}
            <p className="text-xs text-muted-foreground">{r.costs_note}</p>
        </ReportingShell>
    );
}
