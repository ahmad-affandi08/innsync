import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DateRangePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { DEFAULT_CURRENCY, spanDays } from '@/modules/finance/lib/finance';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Figures = Record<string, unknown>;
type Row = {
    id: string; occurred_at: string; action: string; aggregate_type: string; aggregate_id: string; before: Figures | null; after: Figures | null;
    reason: string | null; approval: string | null; actor_id: string | null; actor_name: string | null;
};
type Trail = { from: string; to: string; group: string; groups: string[]; rows: Row[]; total: number; page: number; page_size: number; staff: { id: string; name: string }[] };

/** The longest range the server answers is 93 days. */
const MAX_DAYS = 93;
const ACTION_PATTERN = /^[a-z][a-z0-9_.]{1,118}$/;

/** A moment as the server stores it: `2026-10-03 10:15:00.000000` in UTC, or already an ISO instant. */
const instantOf = (value: string): string => (/(Z|[+-]\d{2}:?\d{2})$/.test(value) ? value : `${value.replace(' ', 'T')}Z`);

/** The text of one figure: money in minor units as an amount, nothing as a dash, anything nested as compact JSON. */
function useFigure() {
    const { t } = useTranslation();
    const format = useFormatters();

    return (key: string, value: unknown): string => {
        if (value === null || value === undefined || value === '') return '—';
        if (typeof value === 'number') return key.endsWith('_minor') ? format.money(value, DEFAULT_CURRENCY) : format.number(value);
        if (typeof value === 'boolean') return t(value ? 'fin.aud.yes' : 'fin.aud.no');
        if (typeof value === 'string') return value;

        return JSON.stringify(value);
    };
}

/** The audit trail of financial changes: who did what, when, on which record, and the figures before and after. */
export default function AuditPage({ trail }: { trail: Trail }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const figure = useFigure();
    const query = new URLSearchParams(usePage().url.split('?')[1] ?? '');
    const [filters, setFilters] = useState({ from: trail.from, to: trail.to, group: trail.group, user: query.get('user') ?? '', action: query.get('action') ?? '' });
    const [detail, setDetail] = useState<Row | null>(null);
    const pages = Math.max(1, Math.ceil(trail.total / trail.page_size));
    const tooLong = spanDays(filters.from, filters.to) >= MAX_DAYS;
    const action = filters.action.trim();
    const badAction = action !== '' && !ACTION_PATTERN.test(action);
    const groupLabel = (g: string) => t(`fin.aud.group.${g}` as MessageKey);

    function search(page = 1) {
        if (tooLong || badAction) return;
        router.get('/finance/audit', Object.fromEntries(Object.entries({ ...filters, action, page: page > 1 ? String(page) : '' }).filter(([, v]) => v !== '')), { preserveScroll: true });
    }

    const columns: DataGridColumn<Row>[] = [
        { id: 'when', label: t('fin.aud.colWhen'), value: (r) => instantOf(r.occurred_at), cell: (r) => format.instant(instantOf(r.occurred_at)), rowHeader: true },
        { id: 'who', label: t('fin.aud.colWho'), value: (r) => r.actor_name ?? '', filter: 'select', cell: (r) => r.actor_name ?? '—' },
        { id: 'action', label: t('fin.aud.colAction'), value: (r) => r.action, cell: (r) => <span className="font-mono text-xs">{r.action}</span> },
        {
            id: 'thing', label: t('fin.aud.colThing'), value: (r) => `${r.aggregate_type} ${r.aggregate_id}`,
            cell: (r) => <span className="flex flex-col"><span>{r.aggregate_type}</span><span className="break-all text-xs text-muted-foreground">{r.aggregate_id}</span></span>,
        },
        { id: 'reason', label: t('fin.aud.colReason'), value: (r) => r.reason ?? '', cell: (r) => r.reason ?? '—' },
        { id: 'approval', label: t('fin.aud.colApproval'), value: (r) => r.approval ?? '', cell: (r) => r.approval ?? '—', hidden: true },
        {
            id: 'details', label: t('inv.col.actions'),
            cell: (r) => <Button disabled={r.before === null && r.after === null} onClick={() => setDetail(r)} size="sm" type="button" variant="outline">{t('fin.aud.details')}</Button>,
        },
    ];

    const keys = detail === null ? [] : [...new Set([...Object.keys(detail.before ?? {}), ...Object.keys(detail.after ?? {})])];

    return (
        <FinanceShell description={t('fin.aud.description')} title={t('fin.aud.title')} wide>
            <Alert title={t('fin.aud.explainTitle')} tone="info">
                <p>{t('fin.aud.explain')}</p>
            </Alert>

            <form className="grid gap-3 border border-border bg-surface p-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden" onSubmit={(e) => { e.preventDefault(); search(); }}>
                <div className="sm:col-span-2 lg:col-span-4">
                    <FormField error={tooLong ? t('fin.aud.tooLong', { max: MAX_DAYS - 1 }) : undefined} hint={t('fin.aud.periodHint', { max: MAX_DAYS - 1 })} label={t('fin.aud.period')}>
                        <DateRangePicker onChange={(range) => setFilters({ ...filters, ...range })} value={{ from: filters.from, to: filters.to }} />
                    </FormField>
                </div>
                <FormField label={t('fin.aud.group')}>
                    <Select onChange={(e) => setFilters({ ...filters, group: e.target.value })} searchable={false} value={filters.group}>
                        {trail.groups.map((g) => <option key={g} value={g}>{groupLabel(g)}</option>)}
                    </Select>
                </FormField>
                <FormField label={t('fin.aud.user')}>
                    <Select onChange={(e) => setFilters({ ...filters, user: e.target.value })} value={filters.user}>
                        <option value="">{t('fin.aud.anyone')}</option>
                        {trail.staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </Select>
                </FormField>
                <div className="sm:col-span-2">
                    <FormField error={badAction ? t('fin.aud.badAction') : undefined} hint={t('fin.aud.actionHint')} label={t('fin.aud.actionFilter')}>
                        <Input maxLength={120} onChange={(e) => setFilters({ ...filters, action: e.target.value.toLowerCase() })} value={filters.action} />
                    </FormField>
                </div>
                <div className="sm:col-span-2 lg:col-span-4">
                    <Button disabled={tooLong || badAction} size="sm" type="submit">{t('fin.aud.search')}</Button>
                </div>
            </form>

            <p className="text-sm text-muted-foreground" data-testid="audit-total">{t('fin.aud.total', { total: format.number(trail.total), from: format.date(trail.from), to: format.date(trail.to) })}</p>

            <DataGrid caption={t('fin.aud.title')} columns={columns} empty={<EmptyState title={t('fin.aud.empty')} />} getRowId={(r) => r.id} id="fin.audit" rows={trail.rows} testId="audit-rows" />

            <nav aria-label={t('ui.pagination.navigation')} className="flex items-center gap-3 print:hidden">
                <Button disabled={trail.page <= 1} onClick={() => search(trail.page - 1)} size="sm" type="button" variant="outline">{t('fin.aud.prev')}</Button>
                <span className="text-xs text-muted-foreground">{t('fin.aud.page', { page: trail.page, pages })}</span>
                <Button disabled={trail.page >= pages} onClick={() => search(trail.page + 1)} size="sm" type="button" variant="outline">{t('fin.aud.next')}</Button>
            </nav>

            <Dialog
                className="w-[min(46rem,calc(100vw-2rem))]"
                footer={<Button onClick={() => setDetail(null)} type="button" variant="outline">{t('fin.aud.close')}</Button>}
                onClose={() => setDetail(null)}
                open={detail !== null}
                title={t('fin.aud.detailTitle', { action: detail?.action ?? '' })}
            >
                {detail !== null && (
                    <div className="flex flex-col gap-3">
                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2" data-testid="audit-detail-facts">
                            <div><dt className="text-xs text-muted-foreground">{t('fin.aud.colWhen')}</dt><dd>{format.instant(instantOf(detail.occurred_at))}</dd></div>
                            <div><dt className="text-xs text-muted-foreground">{t('fin.aud.colWho')}</dt><dd>{detail.actor_name ?? '—'}</dd></div>
                            <div><dt className="text-xs text-muted-foreground">{t('fin.aud.colThing')}</dt><dd className="break-all">{detail.aggregate_type} {detail.aggregate_id}</dd></div>
                            <div><dt className="text-xs text-muted-foreground">{t('fin.aud.colApproval')}</dt><dd>{detail.approval ?? '—'}</dd></div>
                            <div className="sm:col-span-2"><dt className="text-xs text-muted-foreground">{t('fin.aud.colReason')}</dt><dd className="break-words">{detail.reason ?? '—'}</dd></div>
                        </dl>
                        {detail.before === null ? <p className="text-sm text-muted-foreground">{t('fin.aud.noBefore')}</p> : null}
                        {keys.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.aud.noFigures')}</p> : (
                            <Table data-testid="audit-figures">
                                <caption className="sr-only">{t('fin.aud.figures')}</caption>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead scope="col">{t('fin.aud.colField')}</TableHead>
                                        <TableHead scope="col">{t('fin.aud.colBefore')}</TableHead>
                                        <TableHead scope="col">{t('fin.aud.colAfter')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {keys.map((key) => {
                                        const before = figure(key, detail.before?.[key]);
                                        const after = figure(key, detail.after?.[key]);

                                        return (
                                            <TableRow key={key}>
                                                <TableHead scope="row"><span className="font-mono text-xs">{key}</span></TableHead>
                                                <TableCell className="break-words">{before}</TableCell>
                                                <TableCell className={before === after ? 'break-words' : 'break-words font-semibold'}>{after}</TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
