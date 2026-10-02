import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Side = { from: string; to: string; days_closed?: number; days_in_period?: number; figures: Record<string, number> | null };
type Metric = { key: string; current: number | null; before: number | null; change: number | null; change_percent: number | null };
type Report = { meta: Meta; kind: 'day' | 'month' | 'year'; current: Side; before: Side; metrics: Metric[] };

/** A period against the one before it, from the closed days (FR-RPT-006). */
export default function ComparisonPage({ context, report: r }: { context: { currency: string }; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const money = (key: string) => key.endsWith('_minor');
    const show = (key: string, v: number | null) => (v === null ? '—' : key === 'occupancy_bp' ? `${(v / 100).toFixed(1)}%` : money(key) ? format.money(v, context.currency) : format.number(v));
    const change = (m: Metric) => {
        if (m.change === null) return '—';
        const sign = m.change > 0 ? '+' : '';
        return m.key === 'occupancy_bp' ? `${sign}${(m.change / 100).toFixed(1)} ${t('rpt.cmp.points')}` : `${sign}${show(m.key, m.change)}${m.change_percent === null ? '' : ` (${sign}${m.change_percent}%)`}`;
    };
    const range = (s: Side) => (s.from === s.to ? format.date(s.from) : `${format.date(s.from)} – ${format.date(s.to)}`);
    const closed = (s: Side) => (s.figures === null ? t('rpt.cmp.nothingYet') : t('rpt.perf.closedOf', { closed: s.days_closed ?? 0, days: s.days_in_period ?? 0 }));

    return (
        <ReportingShell description={t('rpt.cmp.description')} title={t('rpt.cmp.title')} wide>
            <nav aria-label={t('rpt.cmp.title')} className="flex flex-wrap gap-2 print:hidden">
                {(['day', 'month', 'year'] as const).map((k) => <Button aria-pressed={r.kind === k} asChild key={k} size="sm" variant={r.kind === k ? 'default' : 'outline'}><Link href={`/reports/comparison?kind=${k}`}>{t(`rpt.cmp.kind.${k}` as 'rpt.cmp.kind.day')}</Link></Button>)}
                <Button asChild size="sm" variant="outline"><a href={`/reports/comparison/export?kind=${r.kind}`}>{t('rpt.export.csv')}</a></Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </nav>
            <ReportMeta meta={r.meta} />
            {r.current.figures === null && r.before.figures === null ? <EmptyState title={t('rpt.cmp.empty')} /> : (
                <table className="w-full text-left text-sm" data-testid="comparison">
                    <thead><tr className="text-xs text-muted-foreground">
                        <th className="py-1 font-medium" scope="col">{t('rpt.cmp.figure')}</th>
                        <th scope="col">{range(r.current)}<span className="block font-normal">{closed(r.current)}</span></th>
                        <th scope="col">{range(r.before)}<span className="block font-normal">{closed(r.before)}</span></th>
                        <th scope="col">{t('rpt.cmp.change')}</th>
                    </tr></thead>
                    <tbody>{r.metrics.map((m) => (
                        <tr className="border-t border-border" data-testid={`metric-${m.key}`} key={m.key}>
                            <th className="py-1 font-medium" scope="row">{t(`rpt.cmp.metric.${m.key}` as 'rpt.cmp.metric.occupancy_bp')}</th>
                            <td>{show(m.key, m.current)}</td><td>{show(m.key, m.before)}</td><td>{change(m)}</td>
                        </tr>
                    ))}</tbody>
                </table>
            )}
            <p className="text-xs text-muted-foreground">{t('rpt.cmp.note')}</p>
        </ReportingShell>
    );
}
