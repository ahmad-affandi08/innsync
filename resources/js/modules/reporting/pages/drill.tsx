import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

import { Alert } from '@/components/ui/alert';
import { EmptyState } from '@/components/ui/empty-state';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { MessageKey } from '@/locales/en/index';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Column = { key: string; type: 'text' | 'date' | 'datetime' | 'money' | 'number' | 'quantity' };
type Drill = {
    card: string; metric: string; metrics: { key: string; count: number }[]; columns: Column[]; rows: Record<string, string | number | null>[]; total: number; truncated: boolean;
    figure: number | null; sum: number | null; period: { preset: string; from: string; to: string }; business_date: string; limited: boolean;
};

/** The rows a figure of a dashboard card is made of (FR-DSH-016): one tab for each figure, each row linking to its own screen. */
export default function DrillPage({ currency, drill: d }: { currency: string; drill: Drill }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const query = (metric: string) => `/dashboard/drill/${d.card}?${new URLSearchParams({ metric, from: d.period.from, to: d.period.to }).toString()}`;
    const cell = (col: Column, row: Record<string, string | number | null>): string => {
        const v = row[col.key];

        if (v === null || v === undefined || v === '') return '—';
        if (col.type === 'money') return format.money(Number(v), currency);
        if (col.type === 'date') return format.date(String(v));
        if (col.type === 'datetime') return format.instant(String(v).includes('T') ? String(v) : `${String(v).replace(' ', 'T')}Z`);
        if (col.type === 'quantity') return formatMilli(Number(v), locale);
        if (col.key === 'department') return t(`inv.dept.${String(v)}` as MessageKey);

        return String(v);
    };
    const mismatch = d.figure !== null && d.sum !== null && !d.truncated && d.figure !== d.sum;

    return (
        <ReportingShell description={t('rpt.drill.description')} title={`${t(`rpt.card.${d.card}` as 'rpt.card.occupancy')} · ${t(`rpt.drill.metric.${d.card}.${d.metric}` as 'rpt.drill.metric.occupancy.occupied')}`} wide>
            <div className="flex flex-wrap items-center gap-3 print:hidden">
                <Link className="inline-flex items-center gap-1 text-sm font-medium text-accent hover:underline" href="/dashboard"><ArrowLeft aria-hidden="true" className="size-4" />{t('rpt.drill.back')}</Link>
            </div>
            {d.limited ? <Alert title={t('rpt.dash.limited')} tone="info" /> : null}
            <div aria-label={t('rpt.drill.figures')} className="flex flex-wrap items-center" role="tablist">
                {d.metrics.map((m) => (
                    <Link aria-selected={m.key === d.metric} className={`-ml-px border border-border px-3 py-1.5 text-sm first:ml-0 ${m.key === d.metric ? 'bg-brand text-brand-foreground' : 'bg-surface hover:bg-muted'}`} data-testid={`tab-${m.key}`} href={query(m.key)} key={m.key} role="tab">
                        {t(`rpt.drill.metric.${d.card}.${m.key}` as 'rpt.drill.metric.occupancy.occupied')} <span className="tabular-nums">({m.count})</span>
                    </Link>
                ))}
            </div>
            <p className="text-sm text-muted-foreground" data-testid="drill-count">
                {t('rpt.drill.count', { shown: d.rows.length, total: d.total })}
                {d.figure !== null ? ` · ${t('rpt.drill.card')}: ${format.money(d.figure, currency)}${d.sum !== null && !d.truncated ? ` · ${t('rpt.drill.rows')}: ${format.money(d.sum, currency)}` : ''}` : ''}
            </p>
            {d.truncated ? <Alert title={t('rpt.drill.truncated', { n: d.rows.length })} tone="warning" /> : null}
            {mismatch ? <Alert title={t('rpt.drill.mismatch')} tone="danger" /> : null}
            {d.rows.length === 0 ? <EmptyState title={t('rpt.drill.empty')} /> : (
                <div className="overflow-x-auto border border-border bg-surface">
                    <Table>
                        <caption className="sr-only">{t(`rpt.card.${d.card}` as 'rpt.card.occupancy')}</caption>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {d.columns.map((c) => <TableHead className={c.type === 'money' || c.type === 'number' || c.type === 'quantity' ? 'text-right' : undefined} key={c.key} scope="col">{t(`rpt.drill.col.${c.key}` as 'rpt.drill.col.room')}</TableHead>)}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {d.rows.map((row, i) => (
                                <TableRow key={i}>
                                    {d.columns.map((c, n) => (
                                        <TableCell className={c.type === 'money' || c.type === 'number' || c.type === 'quantity' ? 'text-right tabular-nums' : undefined} key={c.key}>
                                            {n === 0 && typeof row.href === 'string' ? <Link className="font-medium underline-offset-2 hover:underline" href={row.href}>{cell(c, row)}</Link> : cell(c, row)}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </ReportingShell>
    );
}
