import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DateRangePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import {
    REPORT_MAX_DAYS, signClass, spanDays, useDepartmentLabel, useMarginLabel, useOutletLabel,
    type PnlAccount, type PnlDepartment, type PnlMapping, type PnlOutlet, type PnlReport,
} from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const LINK = 'text-sm font-medium underline-offset-2 hover:underline';

/** What each department earned and spent in a period, for management. Not a financial statement and not a general ledger. */
export default function PnlPage({ report }: { report: PnlReport }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const map = useServerAction();
    const department = useDepartmentLabel();
    const margin = useMarginLabel();
    const outletLabel = useOutletLabel();
    const [period, setPeriod] = useState({ from: report.from, to: report.to });
    const [tooLong, setTooLong] = useState(false);
    const [detail, setDetail] = useState<PnlDepartment | null>(null);
    const [mapping, setMapping] = useState(false);
    const [chosen, setChosen] = useState<Record<string, string>>({});
    const [failed, setFailed] = useState<string | null>(null);
    const money = (minor: number) => format.money(minor, report.currency);
    const signed = (minor: number) => <span className={signClass(minor)}>{money(minor)}</span>;
    const notes = report.notes;

    function pickPeriod(range: { from: string; to: string }) {
        setPeriod(range);

        if (spanDays(range.from, range.to) >= REPORT_MAX_DAYS) {
            setTooLong(true);

            return;
        }

        setTooLong(false);
        router.get('/finance/pnl', range, { preserveScroll: true, preserveState: true });
    }

    function openMapping() {
        map.clear();
        setChosen({});
        setFailed(null);
        setMapping(true);
    }

    async function saveMapping(row: PnlMapping, next: string) {
        setChosen((c) => ({ ...c, [row.code]: next }));
        setFailed(null);
        const done = await map.run('/finance/pnl/mappings', { body: { outlet_code: row.code, department: next }, reload: ['report'] });

        if (done === null) {
            setFailed(row.code);
            setChosen((c) => Object.fromEntries(Object.entries(c).filter(([code]) => code !== row.code)));
        }
    }

    const columns: DataGridColumn<PnlDepartment>[] = [
        { id: 'department', label: t('fin.pnl.colDepartment'), value: (d) => department(d.department), rowHeader: true },
        { id: 'revenue', label: t('fin.pnl.colRevenue'), align: 'right', value: (d) => d.revenue_minor, cell: (d) => money(d.revenue_minor), footer: money(report.totals.revenue_minor) },
        { id: 'expenses', label: t('fin.pnl.colExpenses'), align: 'right', value: (d) => d.expenses_minor, cell: (d) => money(d.expenses_minor), footer: money(report.totals.expenses_minor) },
        { id: 'petty', label: t('fin.pnl.colPetty'), align: 'right', value: (d) => d.petty_minor, cell: (d) => money(d.petty_minor), footer: money(report.totals.petty_minor) },
        { id: 'stock', label: t('fin.pnl.colStock'), align: 'right', value: (d) => d.stock_minor, cell: (d) => money(d.stock_minor), footer: money(report.totals.stock_minor) },
        { id: 'cost', label: t('fin.pnl.colCost'), align: 'right', value: (d) => d.cost_total_minor, cell: (d) => money(d.cost_total_minor), footer: money(report.totals.cost_total_minor) },
        { id: 'result', label: t('fin.pnl.colResult'), align: 'right', value: (d) => d.result_minor, cell: (d) => signed(d.result_minor), footer: signed(report.totals.result_minor) },
        { id: 'margin', label: t('fin.pnl.colMargin'), align: 'right', value: (d) => d.margin_bp ?? -1_000_000, cell: (d) => margin(d.margin_bp), footer: margin(report.totals.margin_bp) },
        { id: 'service', label: t('fin.pnl.colService'), align: 'right', value: (d) => d.service_charge_minor, cell: (d) => money(d.service_charge_minor), footer: money(report.totals.service_charge_minor), hidden: true },
        { id: 'actions', label: t('inv.col.actions'), cell: (d) => <Button onClick={() => setDetail(d)} size="sm" type="button" variant="outline">{t('fin.pnl.details')}</Button> },
    ];

    const outletColumns: DataGridColumn<PnlOutlet>[] = [
        { id: 'outlet', label: t('fin.rev.colOutlet'), value: (o) => outletLabel(o.code, o.name), rowHeader: true },
        { id: 'revenue', label: t('fin.pnl.colRevenue'), align: 'right', value: (o) => o.revenue_minor, cell: (o) => money(o.revenue_minor) },
    ];

    const accountColumns: DataGridColumn<PnlAccount>[] = [
        { id: 'account', label: t('fin.pnl.colAccount'), value: (a) => `${a.code} · ${a.name}`, rowHeader: true },
        { id: 'category', label: t('fin.acc.category'), value: (a) => a.category, filter: 'select', filterLabel: (c) => t(`fin.cat.${c}` as MessageKey), cell: (a) => t(`fin.cat.${a.category}` as MessageKey) },
        { id: 'expenses', label: t('fin.pnl.colExpenses'), align: 'right', value: (a) => a.expenses_minor, cell: (a) => money(a.expenses_minor) },
        { id: 'petty', label: t('fin.pnl.colPetty'), align: 'right', value: (a) => a.petty_minor, cell: (a) => money(a.petty_minor) },
    ];

    const kpis: [string, ReactNode, string?][] = [
        [t('fin.pnl.revenue'), money(report.totals.revenue_minor), t('fin.pnl.revenueHint')],
        [t('fin.pnl.costs'), money(report.totals.cost_total_minor)],
        [t('fin.pnl.result'), signed(report.totals.result_minor)],
        [t('fin.pnl.margin'), margin(report.totals.margin_bp), report.totals.margin_bp === null ? t('fin.pnl.marginNone') : undefined],
        [t('fin.pnl.service'), money(report.totals.service_charge_minor), t('fin.pnl.serviceHint')],
    ];

    const mappingRows = report.mapping;

    return (
        <FinanceShell
            actions={report.may.manage ? <Button onClick={openMapping} type="button" variant="outline">{t('fin.pnl.mapping')}</Button> : undefined}
            description={t('fin.pnl.description')}
            title={t('fin.pnl.title')}
            wide
        >
            <Alert title={t('fin.pnl.bannerTitle')} tone="info">{t('fin.pnl.banner')}</Alert>

            <section aria-labelledby="fin-pnl-how-h" className="flex flex-col gap-2 border border-border bg-surface p-4 text-sm">
                <h2 className="font-semibold" id="fin-pnl-how-h">{t('fin.pnl.howTitle')}</h2>
                <ul className="flex list-disc flex-col gap-1 pl-5 text-muted-foreground">
                    <li>{t('fin.pnl.howRevenue')}</li>
                    <li>{t('fin.pnl.howCosts')}</li>
                    <li>{t('fin.pnl.howGoods')}</li>
                </ul>
            </section>

            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
                <FormField error={tooLong ? t('fin.pnl.tooLong') : undefined} label={t('fin.rev.period')}>
                    <DateRangePicker onChange={pickPeriod} value={period} />
                </FormField>
            </div>

            {notes.unclassified_payables_minor > 0 ? (
                <Alert actions={<Link className={LINK} href="/finance/payables">{t('fin.pnl.openPayables')}</Link>} title={t('fin.pnl.unclassified', { amount: money(notes.unclassified_payables_minor) })} tone="warning">
                    {t('fin.pnl.unclassifiedHint')}
                </Alert>
            ) : null}
            {notes.unmapped_outlets.length > 0 ? (
                <Alert
                    actions={report.may.manage ? <Button onClick={openMapping} size="sm" type="button" variant="outline">{t('fin.pnl.mapping')}</Button> : undefined}
                    title={t('fin.pnl.unmapped', { outlets: notes.unmapped_outlets.join(', ') })}
                    tone="warning"
                >
                    {t('fin.pnl.unmappedHint', { department: department('general') })}
                </Alert>
            ) : null}
            {notes.unverified_days > 0 ? (
                <Alert actions={<Link className={LINK} href="/finance/revenue">{t('fin.pnl.openRevenue')}</Link>} title={t('fin.pnl.unverified', { count: notes.unverified_days })} tone="warning">
                    {t('fin.pnl.unverifiedHint')}
                </Alert>
            ) : null}
            {notes.goods_payables_minor > 0 ? (
                <Alert title={t('fin.pnl.goods', { amount: money(notes.goods_payables_minor) })} tone="info">{t('fin.pnl.goodsHint')}</Alert>
            ) : null}

            <section aria-label={t('fin.pnl.title')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" data-testid="pnl-kpis">
                {kpis.map(([label, value, hint]) => <Metric detail={hint} key={label} label={label} value={value} />)}
            </section>

            <section aria-labelledby="fin-pnl-departments-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-pnl-departments-h">{t('fin.pnl.byDepartment')}</h2>
                <DataGrid
                    caption={t('fin.pnl.byDepartment')} columns={columns} empty={<EmptyState title={t('fin.pnl.empty')} />} footerLabel={t('fin.age.total')} getRowId={(d) => d.department}
                    id="fin.pnl.departments" rows={report.departments} testId="pnl-departments"
                />
            </section>

            <Dialog
                className="w-[min(52rem,calc(100vw-2rem))]"
                footer={<Button onClick={() => setDetail(null)} type="button" variant="outline">{t('fin.pnl.close')}</Button>}
                onClose={() => setDetail(null)}
                open={detail !== null}
                title={t('fin.pnl.detailTitle', { department: detail === null ? '' : department(detail.department) })}
            >
                {detail !== null && (
                    <div className="flex flex-col gap-5">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Metric label={t('fin.pnl.colRevenue')} value={money(detail.revenue_minor)} />
                            <Metric label={t('fin.pnl.colCost')} value={money(detail.cost_total_minor)} />
                            <Metric label={t('fin.pnl.colResult')} value={signed(detail.result_minor)} />
                        </div>
                        <section aria-labelledby="fin-pnl-outlets-h" className="flex flex-col gap-2">
                            <h3 className="font-semibold" id="fin-pnl-outlets-h">{t('fin.pnl.outlets')}</h3>
                            <DataGrid caption={t('fin.pnl.outlets')} columns={outletColumns} empty={<EmptyState title={t('fin.pnl.noOutlets')} />} getRowId={(o) => o.code} id="fin.pnl.outlets" rows={detail.outlets} testId="pnl-outlets" />
                        </section>
                        <section aria-labelledby="fin-pnl-accounts-h" className="flex flex-col gap-2">
                            <h3 className="font-semibold" id="fin-pnl-accounts-h">{t('fin.pnl.accounts')}</h3>
                            <DataGrid caption={t('fin.pnl.accounts')} columns={accountColumns} empty={<EmptyState title={t('fin.pnl.noAccounts')} />} getRowId={(a) => a.code} id="fin.pnl.accounts" rows={detail.accounts} testId="pnl-accounts" />
                            <p className="text-sm text-muted-foreground">{t('fin.pnl.stockLine', { amount: money(detail.stock_minor) })}</p>
                        </section>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<Button onClick={() => setMapping(false)} type="button" variant="outline">{t('fin.pnl.close')}</Button>}
                onClose={() => setMapping(false)}
                open={mapping}
                title={t('fin.pnl.mapping')}
            >
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">{t('fin.pnl.mappingHint')}</p>
                    {map.error !== null ? <ErrorState {...errorCopy} error={map.error} onRefresh={() => window.location.reload()} /> : null}
                    {mappingRows.length === 0 ? <EmptyState title={t('fin.pnl.mappingEmpty')} /> : mappingRows.map((row) => (
                        <FormField
                            error={failed === row.code ? map.fieldError('department') : undefined}
                            field="department"
                            hint={row.mapped ? undefined : <StatusBadge label={t('fin.pnl.notMapped')} tone="warning" />}
                            key={row.code}
                            label={`${outletLabel(row.code, row.name)} (${row.code})`}
                        >
                            <Select disabled={map.busy} onChange={(e) => void saveMapping(row, e.target.value)} value={chosen[row.code] ?? row.department}>
                                {report.department_list.map((d) => <option key={d} value={d}>{department(d)}</option>)}
                            </Select>
                        </FormField>
                    ))}
                </div>
            </Dialog>
        </FinanceShell>
    );
}
