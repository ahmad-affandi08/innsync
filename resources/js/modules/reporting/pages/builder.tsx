import { useMemo, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Dataset = { columns: Record<string, string>; filters: Record<string, string[] | null>; range: string };
type Catalogue = { datasets: Record<string, Dataset>; business_date: string; view_limit: number; currency: string };
type Result = { dataset: string; columns: string[]; types: Record<string, string>; rows: Record<string, string | number | boolean | null>[]; truncated: boolean; from: string; to: string };

type Line = { i: number; row: Result['rows'][number] };

/** A simple report builder: choose a dataset, columns, filters and sort (FR-RPT-008). */
export default function BuilderPage({ catalogue }: { catalogue: Catalogue }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const names = Object.keys(catalogue.datasets);
    const [dataset, setDataset] = useState(names[0] ?? '');
    const [columns, setColumns] = useState<string[]>(() => Object.keys(catalogue.datasets[names[0] ?? '']?.columns ?? {}).slice(0, 4));
    const [filters, setFilters] = useState<Record<string, string>>({});
    const [range, setRange] = useState({ from: '', to: '' });
    const [sort, setSort] = useState({ column: '', direction: 'asc' });
    const [result, setResult] = useState<Result | null>(null);
    const set = catalogue.datasets[dataset];
    const lines = useMemo<Line[]>(() => (result?.rows ?? []).map((row, i) => ({ i, row })), [result]);

    function pick(name: string) {
        setDataset(name);
        setColumns(Object.keys(catalogue.datasets[name]?.columns ?? {}).slice(0, 4));
        setFilters({});
        setSort({ column: '', direction: 'asc' });
        setResult(null);
    }

    function query(): string {
        const q = new URLSearchParams();
        q.set('dataset', dataset);
        columns.forEach((c) => q.append('columns[]', c));
        Object.entries(filters).forEach(([k, v]) => { if (v !== '') q.set(`filters[${k}]`, v); });
        if (range.from !== '') q.set('from', range.from);
        if (range.to !== '') q.set('to', range.to);
        if (sort.column !== '') q.set('sort', sort.column);
        q.set('direction', sort.direction);
        return q.toString();
    }

    async function run() {
        const done = await action.run<{ report: Result }>(`/reports/builder/run?${query()}`, { method: 'GET' });
        if (done !== null) setResult(done.report);
    }

    const cell = (type: string, v: string | number | boolean | null) => {
        if (v === null) return '—';
        if (type === 'money') return format.money(Number(v), catalogue.currency);
        if (type === 'flag') return v ? t('rpt.builder.yes') : t('rpt.builder.no');
        if (type === 'date') return format.date(String(v));
        if (type === 'instant') return format.instant(String(v));
        if (type === 'number') return format.number(Number(v));
        return String(v);
    };

    // The columns are whatever the person chose, so the grid's columns are built from the result.
    const resultColumns = (res: Result): DataGridColumn<Line>[] => res.columns.map((c, i) => {
        const type = res.types[c];
        const label = t(`rpt.builder.col.${c}` as 'rpt.builder.col.status');
        const raw = (line: Line) => line.row[c] ?? null;
        const numeric = type === 'money' || type === 'number';
        const value = (line: Line): string | number | null => {
            const v = raw(line);
            if (v === null) return null;
            if (type === 'flag') return v ? t('rpt.builder.yes') : t('rpt.builder.no');
            if (numeric) return Number(v);
            return String(v);
        };
        // A text column is worth a filter only while it holds a handful of distinct values (a status, a source).
        const filterable = type === 'flag' || (type === 'text' && new Set(res.rows.map((x) => x[c] ?? '')).size <= 12);

        return {
            id: c, label, value, rowHeader: i === 0, align: numeric ? 'right' : undefined, filter: filterable ? 'select' : undefined,
            cell: (line: Line) => cell(type, raw(line)),
        };
    });

    return (
        <ReportingShell description={t('rpt.builder.description')} title={t('rpt.builder.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <Alert title={t('rpt.builder.note', { limit: catalogue.view_limit })} tone="info" />

            <form className="flex flex-col gap-4" onSubmit={(e) => { e.preventDefault(); void run(); }}>
                <div className="grid gap-3 sm:grid-cols-4">
                    <FormField field="dataset" error={action.fieldError('dataset')} label={t('rpt.builder.dataset')}><Select onChange={(e) => pick(e.target.value)} value={dataset}>{names.map((n) => <option key={n} value={n}>{t(`rpt.builder.ds.${n}` as 'rpt.builder.ds.reservations')}</option>)}</Select></FormField>
                    <FormField field="from" error={action.fieldError('from')} hint={t(`rpt.builder.range.${set?.range ?? 'arrival'}` as 'rpt.builder.range.arrival')} label={t('rpt.period.from')}><DatePicker onChange={(e) => setRange({ ...range, from: e.target.value })} value={range.from} /></FormField>
                    <FormField field="to" error={action.fieldError('to')} label={t('rpt.period.to')}><DatePicker onChange={(e) => setRange({ ...range, to: e.target.value })} value={range.to} /></FormField>
                    <div />
                    {set !== undefined ? Object.entries(set.filters).map(([name, allowed]) => (
                        <FormField key={name} label={`${t('rpt.builder.filter')}: ${t(`rpt.builder.col.${name}` as 'rpt.builder.col.status')}`}>
                            {allowed === null
                                ? <Input maxLength={40} onChange={(e) => setFilters({ ...filters, [name]: e.target.value.toLowerCase() })} placeholder={t('rpt.builder.anyValue')} value={filters[name] ?? ''} />
                                : <Select onChange={(e) => setFilters({ ...filters, [name]: e.target.value })} value={filters[name] ?? ''}><option value="">{t('rpt.builder.anyValue')}</option>{allowed.map((v) => <option key={v} value={v}>{v}</option>)}</Select>}
                        </FormField>
                    )) : null}
                </div>
                <fieldset className="flex flex-col gap-2" data-testid="columns">
                    <legend className="text-sm font-medium">{t('rpt.builder.columns')}</legend>
                    {action.fieldError('columns') !== undefined ? <p className="text-sm text-danger">{action.fieldError('columns')}</p> : null}
                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                        {Object.keys(set?.columns ?? {}).map((c) => (
                            <label className="flex items-center gap-2" key={c}><input checked={columns.includes(c)} onChange={(e) => setColumns(e.target.checked ? [...columns, c] : columns.filter((x) => x !== c))} type="checkbox" />{t(`rpt.builder.col.${c}` as 'rpt.builder.col.status')}</label>
                        ))}
                    </div>
                </fieldset>
                <div className="flex flex-wrap items-end gap-3">
                    <FormField label={t('rpt.builder.sort')}><Select onChange={(e) => setSort({ ...sort, column: e.target.value })} value={sort.column}><option value="">{t('rpt.builder.firstColumn')}</option>{columns.map((c) => <option key={c} value={c}>{t(`rpt.builder.col.${c}` as 'rpt.builder.col.status')}</option>)}</Select></FormField>
                    <FormField label={t('rpt.builder.direction')}><Select onChange={(e) => setSort({ ...sort, direction: e.target.value })} value={sort.direction}><option value="asc">{t('rpt.builder.asc')}</option><option value="desc">{t('rpt.builder.desc')}</option></Select></FormField>
                    <Button disabled={columns.length === 0} loading={action.busy} type="submit">{t('rpt.builder.run')}</Button>
                    <Button asChild variant="outline"><a aria-disabled={columns.length === 0} href={`/reports/builder/export?${query()}`}>{t('rpt.builder.csv')}</a></Button>
                </div>
            </form>

            {result === null ? null : result.rows.length === 0 ? <EmptyState title={t('rpt.builder.none')} /> : (
                <div className="flex flex-col gap-2">
                    <p className="text-xs text-muted-foreground" data-testid="count">{t('rpt.builder.count', { count: result.rows.length })}{result.truncated ? ` · ${t('rpt.builder.truncated', { limit: catalogue.view_limit })}` : ''}</p>
                    <DataGrid
                        caption={t('rpt.builder.title')}
                        columns={resultColumns(result)}
                        getRowId={(line) => String(line.i)}
                        id={`rpt.builder.${result.dataset}`}
                        key={`${result.dataset}:${result.columns.join(',')}`}
                        rows={lines}
                        testId="result"
                    />
                </div>
            )}
        </ReportingShell>
    );
}
