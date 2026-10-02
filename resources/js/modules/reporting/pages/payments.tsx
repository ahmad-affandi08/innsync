import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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
                <div className="border border-border bg-surface">
                    <Table>
                        <caption className="sr-only">{t('rpt.pay.title')}</caption>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead scope="col">{t('rpt.pay.method')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.pay.received')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.pay.paidBack')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.pay.net')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.pay.count')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>{r.rows.map((row) => (
                            <TableRow key={row.method}>
                                <TableHead className="font-medium text-foreground" scope="row">{method(row.method)}</TableHead>
                                <TableCell className="text-right tabular-nums">{format.money(row.received_minor, context.currency)}</TableCell>
                                <TableCell className="text-right tabular-nums">{format.money(row.paid_back_minor, context.currency)}</TableCell>
                                <TableCell className="text-right tabular-nums">{format.money(row.net_minor, context.currency)}</TableCell>
                                <TableCell className="text-right tabular-nums">{row.count}</TableCell>
                            </TableRow>
                        ))}</TableBody>
                        <TableFooter>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="font-medium text-foreground" scope="row">{t('rpt.flash.total')}</TableHead>
                                <TableCell className="text-right tabular-nums">{format.money(r.totals.received_minor, context.currency)}</TableCell>
                                <TableCell className="text-right tabular-nums">{format.money(r.totals.paid_back_minor, context.currency)}</TableCell>
                                <TableCell className="text-right tabular-nums" data-testid="total-net">{format.money(r.totals.net_minor, context.currency)}</TableCell>
                                <TableCell />
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            )}
        </ReportingShell>
    );
}
