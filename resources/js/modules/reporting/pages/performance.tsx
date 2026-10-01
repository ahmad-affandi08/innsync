import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = { label: string; days_closed: number; days_in_period: number; sellable_nights: number; occupied_nights: number; room_nights: number; occupancy_bp: number; room_revenue_minor: number; adr_minor: number; revpar_minor: number };
type Report = { meta: Meta & { period: { preset: string; from: string; to: string } }; by: 'day' | 'month' | 'year'; year: number; rows: Row[] };

/** Occupancy, ADR and RevPAR per day, month or year (FR-FO-044). */
export default function PerformancePage({ context, report: r }: { context: { currency: string }; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [year, setYear] = useState(String(r.year));
    const q = new URLSearchParams(r.by === 'day' ? { by: 'day', from: r.meta.period.from, to: r.meta.period.to } : { by: r.by, year: String(r.year) }).toString();
    const label = (row: Row) => (r.by === 'day' ? format.date(row.label) : row.label);

    return (
        <ReportingShell description={t('rpt.perf.description')} title={t('rpt.perf.title')} wide>
            <nav aria-label={t('rpt.perf.title')} className="flex flex-wrap gap-2 print:hidden">
                {(['day', 'month', 'year'] as const).map((by) => (
                    <Button aria-pressed={r.by === by} asChild key={by} size="sm" variant={r.by === by ? 'default' : 'outline'}><Link href={`/reports/performance?by=${by}${by === 'day' ? '' : `&year=${r.year}`}`}>{t(`rpt.perf.by.${by}` as 'rpt.perf.by.day')}</Link></Button>
                ))}
            </nav>
            {r.by === 'day'
                ? <PeriodPicker extra={{ by: 'day' }} from={r.meta.period.from} path="/reports/performance" preset={r.meta.period.preset} to={r.meta.period.to} />
                : (
                    <form className="flex flex-wrap items-end gap-3 print:hidden" onSubmit={(e) => { e.preventDefault(); router.get('/reports/performance', { by: r.by, year }); }}>
                        <FormField label={t('rpt.perf.year')}><Input inputMode="numeric" maxLength={4} onChange={(e) => setYear(e.target.value.replace(/\D/g, ''))} value={year} /></FormField>
                        <Button size="sm" type="submit" variant="outline">{t('rpt.perf.show')}</Button>
                    </form>
                )}
            <div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild size="sm" variant="outline"><a href={`/reports/performance/export?${q}`}>{t('rpt.export.csv')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </div>
            <ReportMeta meta={r.meta} />
            {r.rows.length === 0 ? <EmptyState title={t('rpt.perf.empty')} /> : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm" data-testid="performance-table">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('rpt.perf.col.period')}</th>{r.by !== 'day' && <th scope="col">{t('rpt.perf.col.closed')}</th>}<th scope="col">{t('rpt.perf.col.occupancy')}</th><th scope="col">{t('rpt.perf.col.nights')}</th><th scope="col">{t('rpt.perf.col.revenue')}</th><th scope="col">{t('rpt.perf.col.adr')}</th><th scope="col">{t('rpt.perf.col.revpar')}</th></tr></thead>
                        <tbody>{r.rows.map((row) => (
                            <tr className="border-t border-border" data-testid={`row-${row.label}`} key={row.label}>
                                <th className="py-1 font-medium" scope="row">{label(row)}</th>
                                {r.by !== 'day' && <td>{t('rpt.perf.closedOf', { closed: row.days_closed, days: row.days_in_period })}</td>}
                                <td>{(row.occupancy_bp / 100).toFixed(1)}%</td><td>{row.room_nights}</td>
                                <td>{format.money(row.room_revenue_minor, context.currency)}</td><td>{format.money(row.adr_minor, context.currency)}</td><td>{format.money(row.revpar_minor, context.currency)}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            )}
            <p className="text-xs text-muted-foreground">{t('rpt.perf.formula')}</p>
        </ReportingShell>
    );
}
