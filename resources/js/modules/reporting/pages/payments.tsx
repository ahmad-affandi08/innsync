import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = { method: string; received_minor: number; paid_back_minor: number; net_minor: number; count: number };
type Report = { meta: Meta & { period: { preset: string; from: string; to: string } }; rows: Row[]; totals: { received_minor: number; paid_back_minor: number; net_minor: number } };

export default function PaymentsPage({ context, report: r }: { context: { currency: string }; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const q = new URLSearchParams({ from: r.meta.period.from, to: r.meta.period.to }).toString();
    const method = (m: string) => (['cash', 'qris', 'card', 'bank_transfer', 'online'].includes(m) ? t(`fo.folio.method.${m}` as 'fo.folio.method.cash') : m);

    return (
        <ReportingShell description={t('rpt.pay.description')} title={t('rpt.pay.title')}>
            <PeriodPicker from={r.meta.period.from} path="/reports/payments" preset={r.meta.period.preset} to={r.meta.period.to} />
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/payments/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            <ReportMeta meta={r.meta} />
            {r.rows.length === 0 ? <EmptyState title={t('rpt.pay.empty')} /> : (
                <table className="w-full text-left text-sm">
                    <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('rpt.pay.method')}</th><th scope="col">{t('rpt.pay.received')}</th><th scope="col">{t('rpt.pay.paidBack')}</th><th scope="col">{t('rpt.pay.net')}</th><th scope="col">{t('rpt.pay.count')}</th></tr></thead>
                    <tbody>{r.rows.map((row) => (
                        <tr className="border-t border-border" key={row.method}><th className="py-1 font-medium" scope="row">{method(row.method)}</th><td>{format.money(row.received_minor, context.currency)}</td><td>{format.money(row.paid_back_minor, context.currency)}</td><td>{format.money(row.net_minor, context.currency)}</td><td>{row.count}</td></tr>
                    ))}</tbody>
                    <tfoot><tr className="border-t border-border font-medium"><th className="py-1" scope="row">{t('rpt.flash.total')}</th><td>{format.money(r.totals.received_minor, context.currency)}</td><td>{format.money(r.totals.paid_back_minor, context.currency)}</td><td data-testid="total-net">{format.money(r.totals.net_minor, context.currency)}</td><td /></tr></tfoot>
                </table>
            )}
        </ReportingShell>
    );
}
