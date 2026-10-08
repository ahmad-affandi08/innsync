import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { DEFAULT_CURRENCY, minorToMajorText, signClass, useDepartmentLabel, useMonthName, type BudgetOverview } from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Cell = { revenue: string; cost: string };
type Column = keyof Cell;
type Summary = { department: string; revenue_minor: number; cost_minor: number; months: number };

const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1);
const currency = DEFAULT_CURRENCY;

/** An empty cell is 0; text that is not a clear amount is null. */
const parseCell = (text: string): number | null => (text.trim() === '' ? 0 : parseMajorToMinor(text, currency));

/** The budget of each department by month: the net revenue it should earn and the direct cost it may spend, in the terms of the management P&L. */
export default function BudgetPage({ budget }: { budget: BudgetOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const departmentLabel = useDepartmentLabel();
    const monthName = useMonthName();
    const save = useServerAction();
    const [department, setDepartment] = useState(budget.departments[0] ?? '');
    const [drafts, setDrafts] = useState<Record<string, Cell[]>>({});
    const [fill, setFill] = useState<Cell>({ revenue: '', cost: '' });
    const [badFill, setBadFill] = useState<Column | null>(null);
    const [reason, setReason] = useState('');
    const [saved, setSaved] = useState<string | null>(null);
    const money = (minor: number) => format.money(minor, currency);
    const years = Array.from({ length: 7 }, (_, i) => budget.year - 3 + i);
    const key = `${budget.year}:${department}`;

    const stored = useMemo(() => MONTHS.map((m) => budget.rows.find((r) => r.department === department && r.month === m)), [budget.rows, department]);
    const storedMinor = stored.map((r) => ({ revenue: r?.revenue_minor ?? 0, cost: r?.cost_minor ?? 0 }));
    const cells: Cell[] = drafts[key] ?? stored.map((r) => ({ revenue: r === undefined || r.revenue_minor === 0 ? '' : minorToMajorText(r.revenue_minor, currency), cost: r === undefined || r.cost_minor === 0 ? '' : minorToMajorText(r.cost_minor, currency) }));
    const parsed = cells.map((c) => ({ revenue: parseCell(c.revenue), cost: parseCell(c.cost) }));
    const invalid = parsed.some((p) => p.revenue === null || p.cost === null);
    const dirty = parsed.some((p, i) => p.revenue !== storedMinor[i]?.revenue || p.cost !== storedMinor[i]?.cost);
    const total = (column: Column) => parsed.reduce((sum, p) => sum + (p[column] ?? 0), 0);
    const manage = budget.may.manage;

    const summary: Summary[] = budget.departments.map((d) => {
        const own = budget.rows.filter((r) => r.department === d);

        return { department: d, revenue_minor: own.reduce((s, r) => s + r.revenue_minor, 0), cost_minor: own.reduce((s, r) => s + r.cost_minor, 0), months: own.filter((r) => r.revenue_minor > 0 || r.cost_minor > 0).length };
    });

    function pickYear(next: string) {
        setSaved(null);
        router.get('/finance/budget', { year: Number(next) }, { preserveScroll: true, preserveState: true });
    }

    function pickDepartment(next: string) {
        setSaved(null);
        save.clear();
        setDepartment(next);
    }

    function setCell(index: number, column: Column, text: string) {
        setSaved(null);
        setDrafts({ ...drafts, [key]: cells.map((c, i) => (i === index ? { ...c, [column]: text } : c)) });
    }

    function fillColumn(column: Column) {
        const minor = parseCell(fill[column]);

        setBadFill(minor === null ? column : null);
        if (minor === null) return;
        setSaved(null);
        setDrafts({ ...drafts, [key]: cells.map((c) => ({ ...c, [column]: minor === 0 ? '' : minorToMajorText(minor, currency) })) });
    }

    async function saveBudget() {
        if (invalid) return;
        const done = await save.run('/finance/budget', {
            body: {
                year: budget.year, department, reason: reason.trim(),
                months: parsed.map((p, i) => ({ month: i + 1, revenue_minor: p.revenue ?? 0, cost_minor: p.cost ?? 0 })),
            },
            reload: ['budget'],
        });

        if (done === null) return;
        setDrafts(Object.fromEntries(Object.entries(drafts).filter(([k]) => k !== key)));
        setReason('');
        setSaved(t('fin.bud.saved', { department: departmentLabel(department), year: budget.year }));
    }

    const summaryColumns: DataGridColumn<Summary>[] = [
        { id: 'department', label: t('fin.pnl.colDepartment'), value: (s) => departmentLabel(s.department), rowHeader: true },
        { id: 'revenue', label: t('fin.bud.yearRevenue'), align: 'right', value: (s) => s.revenue_minor, cell: (s) => money(s.revenue_minor), footer: money(summary.reduce((sum, s) => sum + s.revenue_minor, 0)) },
        { id: 'cost', label: t('fin.bud.yearCost'), align: 'right', value: (s) => s.cost_minor, cell: (s) => money(s.cost_minor), footer: money(summary.reduce((sum, s) => sum + s.cost_minor, 0)) },
        {
            id: 'result', label: t('fin.bud.yearResult'), align: 'right', value: (s) => s.revenue_minor - s.cost_minor,
            cell: (s) => <span className={signClass(s.revenue_minor - s.cost_minor)}>{money(s.revenue_minor - s.cost_minor)}</span>,
            footer: <span className={signClass(summary.reduce((sum, s) => sum + s.revenue_minor - s.cost_minor, 0))}>{money(summary.reduce((sum, s) => sum + s.revenue_minor - s.cost_minor, 0))}</span>,
        },
        { id: 'months', label: t('fin.bud.monthsSet'), value: (s) => s.months, cell: (s) => (s.months === 0 ? t('fin.bud.none') : t('fin.bud.monthsCount', { count: s.months })) },
        {
            id: 'actions', label: t('inv.col.actions'),
            cell: (s) => <Button disabled={s.department === department} onClick={() => pickDepartment(s.department)} size="sm" type="button" variant="outline">{manage ? t('fin.bud.edit') : t('fin.bud.view')}</Button>,
        },
    ];

    const fillField = (column: Column) => (
        <div className="flex flex-col gap-1.5">
            <FormField error={badFill === column ? t('fin.petty.badAmount') : undefined} label={t(column === 'revenue' ? 'fin.bud.fillRevenue' : 'fin.bud.fillCost', { currency })}>
                <MoneyInput onChange={(e) => { setBadFill(null); setFill({ ...fill, [column]: e.target.value }); }} value={fill[column]} />
            </FormField>
            <Button onClick={() => fillColumn(column)} size="sm" type="button" variant="outline">{t('fin.bud.fillButton')}</Button>
        </div>
    );

    return (
        <FinanceShell
            actions={<Button asChild variant="outline"><Link href={`/finance/budget/report?from=${budget.year}-01&to=${budget.year}-12`}>{t('fin.bud.openReport')}</Link></Button>}
            description={t('fin.bud.description')}
            title={t('fin.bud.title')}
            wide
        >
            <Alert title={t('fin.bud.explainTitle')} tone="info">
                <p>{t('fin.bud.explainRevenue')}</p>
                <p>{t('fin.bud.explainCost')}</p>
                <p>{t('fin.bud.explainSame')}</p>
            </Alert>

            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
                <div className="w-36">
                    <FormField label={t('fin.bud.year')}>
                        <Select onChange={(e) => pickYear(e.target.value)} searchable={false} value={String(budget.year)}>
                            {years.map((y) => <option key={y} value={y}>{y}</option>)}
                        </Select>
                    </FormField>
                </div>
                <div className="w-64 max-w-full">
                    <FormField label={t('fin.acc.department')}>
                        <Select onChange={(e) => pickDepartment(e.target.value)} value={department}>
                            {budget.departments.map((d) => <option key={d} value={d}>{departmentLabel(d)}</option>)}
                        </Select>
                    </FormField>
                </div>
            </div>

            {saved !== null ? <Alert title={saved} tone="success" /> : null}
            {!manage ? <p className="text-sm text-muted-foreground">{t('fin.bud.readOnly')}</p> : null}

            <section aria-labelledby="fin-bud-grid-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-bud-grid-h">{t('fin.bud.gridTitle', { department: departmentLabel(department), year: budget.year })}</h2>
                {manage ? <p className="text-sm text-muted-foreground">{t('fin.bud.gridHint', { currency })}</p> : null}

                {manage ? (
                    <div className="flex flex-col gap-2 border border-border bg-surface p-4 print:hidden">
                        <h3 className="text-sm font-semibold">{t('fin.bud.fillTitle')}</h3>
                        <div className="grid gap-3 sm:grid-cols-2 lg:max-w-xl">{fillField('revenue')}{fillField('cost')}</div>
                    </div>
                ) : null}

                <Table data-testid="budget-grid">
                    <caption className="sr-only">{t('fin.bud.gridTitle', { department: departmentLabel(department), year: budget.year })}</caption>
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">{t('fin.bud.colMonth')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('fin.bud.colRevenue')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('fin.bud.colCost')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {MONTHS.map((m, i) => {
                            const month = monthName(m);
                            const cell = cells[i] ?? { revenue: '', cost: '' };
                            const row = parsed[i];

                            return (
                                <TableRow key={m}>
                                    <TableHead scope="row">{month}</TableHead>
                                    {manage ? (
                                        <>
                                            <TableCell className="text-right">
                                                <MoneyInput aria-invalid={row?.revenue === null ? true : undefined} aria-label={t('fin.bud.cellRevenue', { month })} className="min-h-9 text-right tabular-nums" onChange={(e) => setCell(i, 'revenue', e.target.value)} value={cell.revenue} />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <MoneyInput aria-invalid={row?.cost === null ? true : undefined} aria-label={t('fin.bud.cellCost', { month })} className="min-h-9 text-right tabular-nums" onChange={(e) => setCell(i, 'cost', e.target.value)} value={cell.cost} />
                                            </TableCell>
                                        </>
                                    ) : (
                                        <>
                                            <TableCell className="text-right tabular-nums">{money(row?.revenue ?? 0)}</TableCell>
                                            <TableCell className="text-right tabular-nums">{money(row?.cost ?? 0)}</TableCell>
                                        </>
                                    )}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableHead scope="row">{t('fin.age.total')}</TableHead>
                            <TableCell className="text-right tabular-nums">{money(total('revenue'))}</TableCell>
                            <TableCell className="text-right tabular-nums">{money(total('cost'))}</TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>

                {invalid ? <p className="text-sm text-danger" role="alert">{t('fin.bud.badCell')}</p> : null}

                {manage ? (
                    <div className="flex flex-col gap-3 border border-border bg-surface p-4 print:hidden">
                        {save.error !== null ? <ErrorState {...errorCopy} error={save.error} onRefresh={() => window.location.reload()} /> : null}
                        {save.fieldError('months') !== undefined ? <p className="text-sm text-danger" role="alert">{save.fieldError('months')}</p> : null}
                        <FormField error={save.fieldError('reason')} field="reason" hint={t('fin.bud.reasonHint')} label={t('fin.bud.reason')}>
                            <Textarea maxLength={300} onChange={(e) => setReason(e.target.value)} value={reason} />
                        </FormField>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button disabled={!dirty || invalid} loading={save.busy} onClick={() => void saveBudget()} type="button">{t('fin.bud.save')}</Button>
                            {dirty ? <p aria-live="polite" className="text-sm text-muted-foreground">{t('fin.bud.unsaved')}</p> : null}
                        </div>
                    </div>
                ) : null}
            </section>

            <section aria-labelledby="fin-bud-summary-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-bud-summary-h">{t('fin.bud.summaryTitle', { year: budget.year })}</h2>
                <DataGrid
                    caption={t('fin.bud.summaryTitle', { year: budget.year })} columns={summaryColumns} empty={<EmptyState title={t('fin.bud.summaryEmpty')} />} footerLabel={t('fin.age.total')}
                    getRowId={(s) => s.department} id="fin.budget.summary" rows={summary} testId="budget-summary"
                />
            </section>
        </FinanceShell>
    );
}
