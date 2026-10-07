import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportFilterBar, useReportFilters, type FilterOptions } from '@/modules/reporting/components/report-filters';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = { code: string; name: string; kind: 'room' | 'laundry' | 'outlet' | 'other'; base: number; service_charge: number; tax: number; total: number };
type Report = { options: FilterOptions; meta: Meta & { period: { preset: string; from: string; to: string } }; rows: Row[]; totals: { base: number; service_charge: number; tax: number; total: number } };

/** What each outlet sold, with the rooms and the laundry, filtered by outlet, department and person (FR-RPT-002). */
export default function SalesPage({ context, report: r }: { context: { currency: string }; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const filters = useReportFilters();
    const q = new URLSearchParams({ from: r.meta.period.from, to: r.meta.period.to, ...filters.values }).toString();
    const money = (minor: number) => format.money(minor, context.currency);
    const label = (row: Row) => (row.kind === 'outlet' ? row.name : t(`rpt.sales.kind.${row.kind}` as 'rpt.sales.kind.room'));

    return (
        <ReportingShell description={t('rpt.sales.description')} title={t('rpt.sales.title')}>
            <PeriodPicker extra={filters.values} from={r.meta.period.from} path="/reports/sales" preset={r.meta.period.preset} to={r.meta.period.to} />
            <ReportFilterBar options={r.options} path="/reports/sales" />
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/sales/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button asChild size="sm" variant="outline"><a href={`/reports/sales/export?${q}&format=pdf`}>{t('rpt.export.pdf')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            <ReportMeta meta={r.meta} />
            {r.rows.length === 0 ? <EmptyState title={t('rpt.sales.empty')} /> : (
                <div className="border border-border bg-surface">
                    <Table>
                        <caption className="sr-only">{t('rpt.sales.title')}</caption>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead scope="col">{t('rpt.sales.outlet')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.sales.base')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.sales.service')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.sales.tax')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.sales.total')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>{r.rows.map((row) => (
                            <TableRow key={`${row.kind}-${row.code}`}>
                                <TableHead className="font-medium text-foreground" scope="row">{label(row)}</TableHead>
                                <TableCell className="text-right tabular-nums">{money(row.base)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(row.service_charge)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(row.tax)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(row.total)}</TableCell>
                            </TableRow>
                        ))}</TableBody>
                        <TableFooter>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="font-medium text-foreground" scope="row">{t('rpt.flash.total')}</TableHead>
                                <TableCell className="text-right tabular-nums">{money(r.totals.base)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(r.totals.service_charge)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(r.totals.tax)}</TableCell>
                                <TableCell className="text-right tabular-nums" data-testid="total-sales">{money(r.totals.total)}</TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            )}
        </ReportingShell>
    );
}
