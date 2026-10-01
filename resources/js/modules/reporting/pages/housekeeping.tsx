import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Report = {
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
    const q = new URLSearchParams({ from: r.meta.period.from, to: r.meta.period.to }).toString();
    const minutes = (seconds: number) => t('rpt.hk.minutes', { minutes: format.number(Math.round(seconds / 60)) });
    const percent = (bp: number | null) => (bp === null ? '—' : `${format.number(Math.round(bp / 100))}%`);

    return (
        <ReportingShell description={t('rpt.hk.description')} title={t('rpt.hk.title')}>
            <PeriodPicker from={r.meta.period.from} path="/reports/housekeeping" preset={r.meta.period.preset} to={r.meta.period.to} />
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/housekeeping/export?${q}`}>{t('rpt.export.csv')}</a></Button>
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
                {r.staff.length === 0 ? <EmptyState title={t('rpt.hk.empty')} /> : (
                    <table className="w-full text-left text-sm" data-testid="hk-staff">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('rpt.hk.person')}</th><th scope="col">{t('rpt.hk.rooms')}</th><th scope="col">{t('rpt.hk.average')}</th></tr></thead>
                        <tbody>{r.staff.map((s) => <tr className="border-t border-border" key={s.user_id}><th className="py-1 font-medium" scope="row">{s.name ?? '—'}</th><td>{s.rooms}</td><td>{minutes(s.average_seconds)}</td></tr>)}</tbody>
                    </table>
                )}
            </section>

            {r.kinds.length > 0 && (
                <section aria-labelledby="hk-kinds-h" className="flex flex-col gap-1">
                    <h2 className="text-lg font-semibold" id="hk-kinds-h">{t('rpt.hk.kinds')}</h2>
                    <table className="w-full text-left text-sm" data-testid="hk-kinds">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('rpt.hk.kind')}</th><th scope="col">{t('rpt.hk.rooms')}</th><th scope="col">{t('rpt.hk.average')}</th></tr></thead>
                        <tbody>{r.kinds.map((k) => <tr className="border-t border-border" key={k.kind}><th className="py-1 font-medium" scope="row">{t(`hk.kind.${k.kind}` as 'hk.kind.departure')}</th><td>{k.rooms}</td><td>{minutes(k.average_seconds)}</td></tr>)}</tbody>
                    </table>
                </section>
            )}
        </ReportingShell>
    );
}
