import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportFilterBar, useReportFilters, type FilterOptions } from '@/modules/reporting/components/report-filters';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Report = { options: FilterOptions;
    meta: Meta & { period: { preset: string; from: string; to: string } };
    staff: { user_id: string; name: string | null; rooms: number; average_seconds: number }[];
    kinds: { kind: string; rooms: number; average_seconds: number }[];
    totals: { rooms: number; average_seconds: number };
    inspections: { inspected: number; passed: number; first_time_pass_bp: number | null };
    checklists: { runs: number; items: number; completed: number; percent: number | null };
};

/** Rooms cleaned per person, the average time per room, inspection results and checklist completion (FR-HK-015). */
export default function HousekeepingReportPage({ report: r }: { report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const filters = useReportFilters();
    const q = new URLSearchParams({ from: r.meta.period.from, to: r.meta.period.to, ...filters.values }).toString();
    const minutes = (seconds: number) => t('rpt.hk.minutes', { minutes: format.number(Math.round(seconds / 60)) });
    const percent = (bp: number | null) => (bp === null ? '—' : `${format.number(Math.round(bp / 100))}%`);
    const staffColumns: DataGridColumn<Report['staff'][number]>[] = [
        { id: 'person', label: t('rpt.hk.person'), value: (s) => s.name ?? '—', rowHeader: true },
        { id: 'rooms', label: t('rpt.hk.rooms'), align: 'right', value: (s) => s.rooms },
        { id: 'average', label: t('rpt.hk.average'), align: 'right', value: (s) => s.average_seconds, cell: (s) => minutes(s.average_seconds) },
    ];

    return (
        <ReportingShell description={t('rpt.hk.description')} title={t('rpt.hk.title')}>
            <PeriodPicker extra={filters.values} from={r.meta.period.from} path="/reports/housekeeping" preset={r.meta.period.preset} to={r.meta.period.to} />
            <ReportFilterBar options={r.options} path="/reports/housekeeping" />
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/housekeeping/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button asChild size="sm" variant="outline"><a href={`/reports/housekeeping/export?${q}&format=pdf`}>{t('rpt.export.pdf')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            <ReportMeta meta={r.meta} />

            <dl className="grid gap-3 sm:grid-cols-4" data-testid="hk-totals">
                <div><dt className="text-xs text-muted-foreground">{t('rpt.hk.rooms')}</dt><dd className="text-xl font-semibold">{format.number(r.totals.rooms)}</dd></div>
                <div><dt className="text-xs text-muted-foreground">{t('rpt.hk.average')}</dt><dd className="text-xl font-semibold">{minutes(r.totals.average_seconds)}</dd></div>
                <div><dt className="text-xs text-muted-foreground">{t('rpt.hk.firstPass')}</dt><dd className="text-xl font-semibold">{percent(r.inspections.first_time_pass_bp)}</dd><dd className="text-xs text-muted-foreground">{t('rpt.hk.inspected', { passed: r.inspections.passed, count: r.inspections.inspected })}</dd></div>
                <div><dt className="text-xs text-muted-foreground">{t('rpt.hk.checklists')}</dt><dd className="text-xl font-semibold">{r.checklists.percent === null ? '—' : `${r.checklists.percent}%`}</dd><dd className="text-xs text-muted-foreground">{t('rpt.hk.checklistItems', { done: r.checklists.completed, total: r.checklists.items })}</dd></div>
            </dl>

            <section aria-labelledby="hk-staff-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="hk-staff-h">{t('rpt.hk.staff')}</h2>
                <DataGrid
                    caption={t('rpt.hk.staff')}
                    columns={staffColumns}
                    empty={<EmptyState title={t('rpt.hk.empty')} />}
                    getRowId={(s) => s.user_id}
                    id="rpt.hk.staff"
                    rows={r.staff}
                    testId="hk-staff"
                />
            </section>

            {r.kinds.length > 0 && (
                <section aria-labelledby="hk-kinds-h" className="flex flex-col gap-1">
                    <h2 className="text-lg font-semibold" id="hk-kinds-h">{t('rpt.hk.kinds')}</h2>
                    <Table data-testid="hk-kinds">
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead scope="col">{t('rpt.hk.kind')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.hk.rooms')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('rpt.hk.average')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {r.kinds.map((k) => (
                                <TableRow key={k.kind}>
                                    <TableHead className="font-medium text-foreground" scope="row">{t(`hk.kind.${k.kind}` as 'hk.kind.departure')}</TableHead>
                                    <TableCell className="text-right tabular-nums">{k.rooms}</TableCell>
                                    <TableCell className="text-right tabular-nums">{minutes(k.average_seconds)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            )}
        </ReportingShell>
    );
}
