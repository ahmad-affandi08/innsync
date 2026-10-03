import { Link, router } from '@inertiajs/react';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Metric } from '@/components/ui/metric';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { MonthPicker } from '@/modules/finance/components/month-picker';
import { signClass, useDepartmentLabel, useMarginLabel, useMonthLabel, useSignedMoney, type BudgetDepartment, type BudgetMonth, type BudgetReport } from '@/modules/finance/lib/finance';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';

const LINK = 'text-sm font-medium underline-offset-2 hover:underline';
const MAX_MONTHS = 12;

/** Months from the first to the last, both counted. */
const monthsBetween = (from: string, to: string): number => (Number(to.slice(0, 4)) - Number(from.slice(0, 4))) * 12 + Number(to.slice(5, 7)) - Number(from.slice(5, 7)) + 1;

/** Revenue below its budget and a cost above its budget are unfavourable; `better` says which way is good. */
const isUnfavourable = (better: 'higher' | 'lower', minor: number): boolean => (better === 'higher' ? minor < 0 : minor > 0);

/** A difference (actual less budget) in colour, with an icon and the word: red and "Unfavourable", or green and "Favourable". */
function Variance({ better, currency, minor }: { better: 'higher' | 'lower'; currency: string; minor: number }) {
    const { t } = useTranslation();
    const signed = useSignedMoney();

    if (minor === 0) return <span className="text-muted-foreground">{signed(0, currency)}</span>;

    const bad = isUnfavourable(better, minor);
    const Icon = bad ? TriangleAlert : CircleCheck;

    return (
        <span className={cn('inline-flex flex-wrap items-center justify-end gap-x-1.5', bad ? 'font-medium text-danger' : 'text-success')}>
            <Icon aria-hidden="true" className="size-3.5 shrink-0" />
            <span>{signed(minor, currency)}</span>
            <span className="text-xs">{bad ? t('fin.bud.rep.unfavourable') : t('fin.bud.rep.favourable')}</span>
        </span>
    );
}

/** The budget of departments for a range of months against what the management P&L shows for the same months. */
export default function BudgetReportPage({ report }: { report: BudgetReport }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const department = useDepartmentLabel();
    const percent = useMarginLabel();
    const monthLabel = useMonthLabel();
    const [period, setPeriod] = useState({ from: report.from, to: report.to });
    const [problem, setProblem] = useState<'order' | 'long' | null>(null);
    const currency = report.currency;
    const money = (minor: number) => format.money(minor, currency);
    const signed = (minor: number) => <span className={signClass(minor)}>{money(minor)}</span>;
    const used = (bp: number | null) => (bp === null ? t('fin.bud.rep.noBudget') : percent(bp));
    const totals = report.totals;
    const firstYear = Math.min(Number(report.from.slice(0, 4)), Number(report.to.slice(0, 4)), Number(format.calendarDateOf(new Date()).slice(0, 4)));
    const lastYear = Math.max(Number(report.from.slice(0, 4)), Number(report.to.slice(0, 4)), Number(format.calendarDateOf(new Date()).slice(0, 4)));
    const years = Array.from({ length: lastYear - firstYear + 4 }, (_, i) => firstYear - 2 + i);
    const lastDay = new Date(Date.UTC(Number(report.to.slice(0, 4)), Number(report.to.slice(5, 7)), 0)).getUTCDate();

    function pickPeriod(next: { from: string; to: string }) {
        setPeriod(next);

        if (next.from > next.to) {
            setProblem('order');

            return;
        }

        if (monthsBetween(next.from, next.to) > MAX_MONTHS) {
            setProblem('long');

            return;
        }

        setProblem(null);
        router.get('/finance/budget/report', next, { preserveScroll: true, preserveState: true });
    }

    const tile = (label: string, actual: ReactNode, budget: number, usedBp: number | null, unfavourable: boolean, unfavourableLabel: string) => (
        <Metric
            detail={(
                <span className="flex flex-col gap-1">
                    <span>{usedBp === null ? t('fin.bud.rep.budgetNone') : t('fin.bud.rep.budgetLine', { budget: money(budget), used: used(usedBp) })}</span>
                    {unfavourable ? <span><StatusBadge label={unfavourableLabel} tone="danger" /></span> : null}
                </span>
            )}
            label={label}
            value={actual}
        />
    );

    const columns: DataGridColumn<BudgetDepartment>[] = [
        { id: 'department', label: t('fin.pnl.colDepartment'), value: (d) => department(d.department), rowHeader: true },
        { id: 'budgetRevenue', label: t('fin.bud.rep.budgetRevenue'), align: 'right', value: (d) => d.budget_revenue_minor, cell: (d) => money(d.budget_revenue_minor), footer: money(totals.budget_revenue_minor) },
        { id: 'actualRevenue', label: t('fin.bud.rep.actualRevenue'), align: 'right', value: (d) => d.actual_revenue_minor, cell: (d) => money(d.actual_revenue_minor), footer: money(totals.actual_revenue_minor) },
        {
            id: 'revenueVariance', label: t('fin.bud.rep.revenueVariance'), align: 'right', value: (d) => d.revenue_variance_minor,
            cell: (d) => <Variance better="higher" currency={currency} minor={d.revenue_variance_minor} />, footer: <Variance better="higher" currency={currency} minor={totals.revenue_variance_minor} />,
        },
        { id: 'revenueUsed', label: t('fin.bud.rep.revenueUsed'), align: 'right', value: (d) => d.revenue_used_bp ?? -1, cell: (d) => used(d.revenue_used_bp), footer: used(totals.revenue_used_bp) },
        { id: 'budgetCost', label: t('fin.bud.rep.budgetCost'), align: 'right', value: (d) => d.budget_cost_minor, cell: (d) => money(d.budget_cost_minor), footer: money(totals.budget_cost_minor) },
        { id: 'actualCost', label: t('fin.bud.rep.actualCost'), align: 'right', value: (d) => d.actual_cost_minor, cell: (d) => money(d.actual_cost_minor), footer: money(totals.actual_cost_minor) },
        {
            id: 'costVariance', label: t('fin.bud.rep.costVariance'), align: 'right', value: (d) => d.cost_variance_minor,
            cell: (d) => <Variance better="lower" currency={currency} minor={d.cost_variance_minor} />, footer: <Variance better="lower" currency={currency} minor={totals.cost_variance_minor} />,
        },
        { id: 'costUsed', label: t('fin.bud.rep.costUsed'), align: 'right', value: (d) => d.cost_used_bp ?? -1, cell: (d) => used(d.cost_used_bp), footer: used(totals.cost_used_bp) },
        { id: 'budgetResult', label: t('fin.bud.rep.budgetResult'), align: 'right', value: (d) => d.budget_result_minor, cell: (d) => signed(d.budget_result_minor), footer: signed(totals.budget_result_minor), hidden: true },
        { id: 'actualResult', label: t('fin.bud.rep.actualResult'), align: 'right', value: (d) => d.actual_result_minor, cell: (d) => signed(d.actual_result_minor), footer: signed(totals.actual_result_minor), hidden: true },
        {
            id: 'resultVariance', label: t('fin.bud.rep.resultVariance'), align: 'right', value: (d) => d.result_variance_minor,
            cell: (d) => <Variance better="higher" currency={currency} minor={d.result_variance_minor} />, footer: <Variance better="higher" currency={currency} minor={totals.result_variance_minor} />, hidden: true,
        },
    ];

    const monthlyColumns: DataGridColumn<BudgetMonth>[] = [
        { id: 'month', label: t('fin.bud.colMonth'), value: (m) => m.month, cell: (m) => monthLabel(m.month), rowHeader: true },
        { id: 'budgetRevenue', label: t('fin.bud.rep.budgetRevenue'), align: 'right', value: (m) => m.budget_revenue_minor, cell: (m) => money(m.budget_revenue_minor), footer: money(totals.budget_revenue_minor) },
        { id: 'actualRevenue', label: t('fin.bud.rep.actualRevenue'), align: 'right', value: (m) => m.actual_revenue_minor, cell: (m) => money(m.actual_revenue_minor), footer: money(totals.actual_revenue_minor) },
        {
            id: 'revenueVariance', label: t('fin.bud.rep.revenueVariance'), align: 'right', value: (m) => m.actual_revenue_minor - m.budget_revenue_minor,
            cell: (m) => <Variance better="higher" currency={currency} minor={m.actual_revenue_minor - m.budget_revenue_minor} />, footer: <Variance better="higher" currency={currency} minor={totals.revenue_variance_minor} />,
        },
        { id: 'budgetCost', label: t('fin.bud.rep.budgetCost'), align: 'right', value: (m) => m.budget_cost_minor, cell: (m) => money(m.budget_cost_minor), footer: money(totals.budget_cost_minor) },
        { id: 'actualCost', label: t('fin.bud.rep.actualCost'), align: 'right', value: (m) => m.actual_cost_minor, cell: (m) => money(m.actual_cost_minor), footer: money(totals.actual_cost_minor) },
        {
            id: 'costVariance', label: t('fin.bud.rep.costVariance'), align: 'right', value: (m) => m.actual_cost_minor - m.budget_cost_minor,
            cell: (m) => <Variance better="lower" currency={currency} minor={m.actual_cost_minor - m.budget_cost_minor} />, footer: <Variance better="lower" currency={currency} minor={totals.cost_variance_minor} />,
        },
    ];

    return (
        <FinanceShell
            actions={<Button asChild variant="outline"><Link href={`/finance/budget?year=${report.to.slice(0, 4)}`}>{t('fin.bud.rep.editBudget')}</Link></Button>}
            description={t('fin.bud.rep.description')}
            title={t('fin.bud.rep.title')}
            wide
        >
            <Alert actions={<Link className={LINK} href={`/finance/pnl?from=${report.from}-01&to=${report.to}-${String(lastDay).padStart(2, '0')}`}>{t('fin.bud.rep.openPnl')}</Link>} title={t('fin.bud.rep.bannerTitle')} tone="info">
                <p>{t('fin.bud.rep.banner')}</p>
            </Alert>

            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
                <div className="w-72 max-w-full">
                    <FormField error={problem === 'order' ? t('fin.bud.rep.order') : undefined} label={t('fin.bud.rep.from')}>
                        <MonthPicker onChange={(from) => pickPeriod({ ...period, from })} value={period.from} years={years} />
                    </FormField>
                </div>
                <div className="w-72 max-w-full">
                    <FormField error={problem === 'long' ? t('fin.bud.rep.tooLong') : undefined} label={t('fin.bud.rep.to')}>
                        <MonthPicker onChange={(to) => pickPeriod({ ...period, to })} value={period.to} years={years} />
                    </FormField>
                </div>
            </div>

            {report.notes.unverified_days > 0 ? (
                <Alert actions={<Link className={LINK} href="/finance/revenue">{t('fin.pnl.openRevenue')}</Link>} title={t('fin.bud.rep.unverified', { count: report.notes.unverified_days })} tone="warning">
                    {t('fin.bud.rep.unverifiedHint')}
                </Alert>
            ) : null}
            {report.notes.unclassified_payables_minor > 0 ? (
                <Alert actions={<Link className={LINK} href="/finance/payables">{t('fin.pnl.openPayables')}</Link>} title={t('fin.bud.rep.unclassified', { amount: money(report.notes.unclassified_payables_minor) })} tone="warning">
                    {t('fin.bud.rep.unclassifiedHint')}
                </Alert>
            ) : null}

            <section aria-label={t('fin.bud.rep.title')} className="grid gap-3 sm:grid-cols-3" data-testid="budget-kpis">
                {tile(t('fin.bud.rep.kpiRevenue'), money(totals.actual_revenue_minor), totals.budget_revenue_minor, totals.revenue_used_bp, isUnfavourable('higher', totals.revenue_variance_minor), t('fin.bud.rep.revenueBelow'))}
                {tile(t('fin.bud.rep.kpiCost'), money(totals.actual_cost_minor), totals.budget_cost_minor, totals.cost_used_bp, isUnfavourable('lower', totals.cost_variance_minor), t('fin.bud.rep.costAbove'))}
                <Metric
                    detail={(
                        <span className="flex flex-col gap-1">
                            <span>{t('fin.bud.rep.budgetResultLine', { amount: money(totals.budget_result_minor) })}</span>
                            {isUnfavourable('higher', totals.result_variance_minor) ? <span><StatusBadge label={t('fin.bud.rep.resultBelow')} tone="danger" /></span> : null}
                        </span>
                    )}
                    label={t('fin.bud.rep.kpiResult')}
                    value={signed(totals.actual_result_minor)}
                />
            </section>

            <p className="text-sm text-muted-foreground">{t('fin.bud.rep.how')}</p>

            <Tabs defaultValue="fin-bud-rep-dept-h">
                <TabsList>
                    <TabsTrigger value="fin-bud-rep-dept-h">{t('fin.bud.rep.byDepartment')}</TabsTrigger>
                    <TabsTrigger value="fin-bud-rep-month-h">{t('fin.bud.rep.byMonth')}</TabsTrigger>
                </TabsList>

            <TabsContent className="flex flex-col gap-3" value="fin-bud-rep-dept-h">
                <h2 className="sr-only" id="fin-bud-rep-dept-h">{t('fin.bud.rep.byDepartment')}</h2>
                <DataGrid
                    caption={t('fin.bud.rep.byDepartment')} columns={columns} empty={<EmptyState title={t('fin.bud.rep.empty')} />} footerLabel={t('fin.age.total')}
                    getRowId={(d) => d.department} id="fin.budget.report.departments" rows={report.departments} testId="budget-departments"
                />
            </TabsContent>

            <TabsContent className="flex flex-col gap-3" value="fin-bud-rep-month-h">
                <h2 className="sr-only" id="fin-bud-rep-month-h">{t('fin.bud.rep.byMonth')}</h2>
                <DataGrid
                    caption={t('fin.bud.rep.byMonth')} columns={monthlyColumns} empty={<EmptyState title={t('fin.bud.rep.empty')} />} footerLabel={t('fin.age.total')}
                    getRowId={(m) => m.month} id="fin.budget.report.months" rows={report.monthly} testId="budget-months"
                />
            </TabsContent>
            </Tabs>
        </FinanceShell>
    );
}
