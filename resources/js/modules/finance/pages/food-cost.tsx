import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DateRangePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Metric } from '@/components/ui/metric';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { REPORT_MAX_DAYS, spanDays, useDepartmentLabel, useMarginLabel, type FoodCostDepartment, type FoodCostReport } from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Form = { percent: string; reason: string };
type Outlet = FoodCostReport['outlets'][number];

/** A percentage typed as `35` or `35,5` as basis points; null when it is not a number with at most two decimals. */
function percentToBasisPoints(text: string): number | null {
    const match = /^(\d{1,3})(?:[.,](\d{1,2}))?$/.exec(text.trim());

    return match === null ? null : Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
}

/** What the ingredients cost against the sales of the food and beverage outlets in a period, and whether that is within the owner's target. */
export default function FoodCostPage({ report }: { report: FoodCostReport }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const department = useDepartmentLabel();
    const percent = useMarginLabel();
    const [period, setPeriod] = useState({ from: report.from, to: report.to });
    const [tooLong, setTooLong] = useState(false);
    const [form, setForm] = useState<Form | null>(null);
    const [badTarget, setBadTarget] = useState(false);
    const money = (minor: number) => format.money(minor, report.currency);
    const target = report.target;

    function pickPeriod(range: { from: string; to: string }) {
        setPeriod(range);

        if (spanDays(range.from, range.to) >= REPORT_MAX_DAYS) {
            setTooLong(true);

            return;
        }

        setTooLong(false);
        router.get('/finance/food-cost', range, { preserveScroll: true, preserveState: true });
    }

    function openTarget() {
        action.clear();
        setBadTarget(false);
        setForm({ percent: String(target.bp / 100), reason: '' });
    }

    async function save() {
        if (form === null) return;
        const bp = percentToBasisPoints(form.percent);

        setBadTarget(bp === null || bp < 500 || bp > 9000);
        if (bp === null || bp < 500 || bp > 9000) return;
        const done = await action.run('/finance/food-cost/target', {
            body: { target_bp: bp, reason: form.reason.trim(), ...(target.lock_version === null ? {} : { lock_version: target.lock_version }) },
            reload: ['report'],
        });
        if (done !== null) setForm(null);
    }

    const outletColumns: DataGridColumn<Outlet>[] = [
        { id: 'outlet', label: t('fin.fc.colOutlet'), value: (o) => `${o.name ?? o.code} (${o.code})`, rowHeader: true },
        { id: 'sales', label: t('fin.fc.colSales'), align: 'right', value: (o) => o.sales_minor, cell: (o) => money(o.sales_minor), footer: money(report.sales_minor) },
    ];
    const total = report.totals;
    const amount = (id: string, label: string, key: keyof Omit<FoodCostDepartment, 'department'>): DataGridColumn<FoodCostDepartment> => ({
        id, label, align: 'right', value: (d) => d[key], cell: (d) => money(d[key]), footer: money(total[key]),
    });
    const departmentColumns: DataGridColumn<FoodCostDepartment>[] = [
        { id: 'department', label: t('fin.col.department'), value: (d) => department(d.department), rowHeader: true },
        amount('issued', t('fin.fc.colIssued'), 'issued_minor'),
        amount('writtenOff', t('fin.fc.colWrittenOff'), 'written_off_minor'),
        amount('adjusted', t('fin.fc.colAdjusted'), 'adjusted_minor'),
        amount('cost', t('fin.fc.colCost'), 'cost_minor'),
        amount('purchased', t('fin.fc.colPurchased'), 'purchased_minor'),
    ];
    const tone = report.status === 'over' ? 'danger' : report.status === 'within' ? 'success' : 'neutral';

    return (
        <FinanceShell
            actions={report.may.manage ? <Button onClick={openTarget} type="button" variant="outline">{t('fin.fc.setTarget')}</Button> : undefined}
            description={t('fin.fc.description')}
            title={t('fin.fc.title')}
            wide
        >
            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
                <FormField error={tooLong ? t('fin.pnl.tooLong') : undefined} label={t('fin.rev.period')}>
                    <DateRangePicker onChange={pickPeriod} value={period} />
                </FormField>
            </div>

            <p className="text-sm text-muted-foreground">{t('fin.fc.explain')}</p>

            {!report.notes.outlets_mapped ? (
                <Alert actions={<Link className="text-sm font-medium underline-offset-2 hover:underline" href="/finance/pnl">{t('fin.fc.toPnl')}</Link>} title={t('fin.fc.notMapped')} tone="info">{t('fin.fc.notMappedHint')}</Alert>
            ) : null}
            {report.notes.unverified_days > 0 ? <Alert title={t('fin.fc.unverified', { count: report.notes.unverified_days })} tone="warning" /> : null}

            <section aria-label={t('fin.fc.title')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" data-testid="food-cost-kpis">
                <Metric label={t('fin.fc.sales')} value={money(report.sales_minor)} />
                <Metric detail={`${t('fin.fc.waste')}: ${percent(report.waste_bp)}`} label={t('fin.fc.cost')} value={money(total.cost_minor)} />
                <Metric detail={<StatusBadge label={t(`fin.fc.status.${report.status}` as MessageKey)} tone={tone} />} label={t('fin.fc.share')} value={percent(report.food_cost_bp)} />
                <Metric detail={target.is_default ? t('fin.fc.targetBaseline') : t('fin.fc.targetSet')} label={t('fin.fc.target')} value={percent(target.bp)} />
                <Metric label={t('fin.fc.allowed')} value={money(target.allowed_minor)} />
            </section>

            {report.status === 'over' ? <Alert title={t('fin.fc.overHint', { amount: money(report.over_minor) })} tone="warning" /> : null}
            {report.status === 'within' ? <p className="text-sm text-muted-foreground">{t('fin.fc.withinHint')}</p> : null}

            <section aria-labelledby="fin-fc-outlets-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-fc-outlets-h">{t('fin.fc.outlets')}</h2>
                <DataGrid
                    caption={t('fin.fc.outlets')} columns={outletColumns} empty={<EmptyState title={t('fin.fc.emptyOutlets')} />} footerLabel={t('fin.age.total')}
                    getRowId={(o) => o.code} id="fin.foodcost.outlets" rows={report.outlets} testId="food-cost-outlets"
                />
            </section>

            <section aria-labelledby="fin-fc-dept-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-fc-dept-h">{t('fin.fc.departments')}</h2>
                <DataGrid
                    caption={t('fin.fc.departments')} columns={departmentColumns} footerLabel={t('fin.age.total')}
                    getRowId={(d) => d.department} id="fin.foodcost.departments" rows={report.departments} testId="food-cost-departments"
                />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('fin.fc.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.fc.targetTitle')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.fc.targetHint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={badTarget ? t('fin.fc.badTarget') : action.fieldError('target_bp')} field="target_bp" label={t('fin.fc.targetLabel')}>
                            <Input inputMode="decimal" onChange={(e) => setForm({ ...form, percent: e.target.value })} value={form.percent} />
                        </FormField>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fin.fc.reason')}>
                            <Textarea maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
