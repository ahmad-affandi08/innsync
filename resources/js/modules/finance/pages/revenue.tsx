import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DateRangePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { REVENUE_TONE, signClass, useOutletLabel, useReceiptMethodLabel, useSignedMoney, type MethodTotals, type OutletRevenue, type RevenueAmounts } from '@/modules/finance/lib/finance';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Totals = RevenueAmounts & { collected_minor: number };
type Day = Totals & { id: string; date: string; currency: string; status: 'recorded' | 'verified'; verified_at: string | null };
type CorrectionPayment = { method: string; received_minor: number };
type CorrectionOutlet = RevenueAmounts & { outlet_code: string; outlet_name: string | null };
type Corrections = { count: number; revenue: CorrectionOutlet[]; payments: CorrectionPayment[]; totals: RevenueAmounts & { received_minor: number } };
type Report = {
    from: string; to: string; today: string; days: Day[]; totals: Totals; outlets: OutletRevenue[]; methods: MethodTotals[]; unverified: number; corrections: Corrections; may: { verify: boolean };
};
type Month = Totals & { month: string; days: number; outlets: OutletRevenue[] };
type Monthly = { year: number; months: Month[]; totals: Totals };
type MonthOutlet = OutletRevenue & { month: string };

/** The longest period the server answers: a range of 93 days or more is refused. */
const MAX_DAYS = 93;
const FALLBACK_CURRENCY = 'IDR';

const spanDays = (from: string, to: string) => Math.round((Date.parse(to) - Date.parse(from)) / 86_400_000);

/** The revenue of the closed days: by day, outlet, payment method and month. */
export default function RevenuePage({ monthly, report }: { monthly: Monthly; report: Report }) {
    const { locale, t } = useTranslation();
    const format = useFormatters();
    const outletLabel = useOutletLabel();
    const methodLabel = useReceiptMethodLabel();
    const signed = useSignedMoney();
    const [tab, setTab] = useState('daily');
    const [period, setPeriod] = useState({ from: report.from, to: report.to });
    const [tooLong, setTooLong] = useState(false);
    const currency = report.days[0]?.currency ?? FALLBACK_CURRENCY;
    const money = (minor: number) => format.money(minor, currency);
    const statusLabel = (s: string) => t(`fin.rev.${s}` as MessageKey);
    const monthLabel = (month: string) => new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${month}-01T00:00:00Z`));
    const thisYear = Number(report.today.slice(0, 4));
    const years = useMemo(() => {
        const list = Array.from({ length: 5 }, (_, i) => thisYear - i);

        return list.includes(monthly.year) ? list : [monthly.year, ...list].sort((a, b) => b - a);
    }, [thisYear, monthly.year]);

    function go(next: { from: string; to: string; year: number }) {
        router.get('/finance/revenue', { from: next.from, to: next.to, year: next.year }, { preserveScroll: true, preserveState: true });
    }

    function pickPeriod(range: { from: string; to: string }) {
        setPeriod(range);

        if (spanDays(range.from, range.to) >= MAX_DAYS) {
            setTooLong(true);

            return;
        }

        setTooLong(false);
        go({ ...range, year: monthly.year });
    }

    const amountColumns = <T extends RevenueAmounts>(totals?: RevenueAmounts): DataGridColumn<T>[] => [
        { id: 'base', label: t('fin.rev.base'), align: 'right', value: (r) => r.base_minor, cell: (r) => money(r.base_minor), footer: totals ? money(totals.base_minor) : undefined },
        { id: 'service', label: t('fin.rev.service'), align: 'right', value: (r) => r.service_charge_minor, cell: (r) => money(r.service_charge_minor), footer: totals ? money(totals.service_charge_minor) : undefined },
        { id: 'tax', label: t('fin.rev.tax'), align: 'right', value: (r) => r.tax_minor, cell: (r) => money(r.tax_minor), footer: totals ? money(totals.tax_minor) : undefined },
        { id: 'total', label: t('fin.rev.billed'), align: 'right', value: (r) => r.total_minor, cell: (r) => money(r.total_minor), footer: totals ? money(totals.total_minor) : undefined },
    ];

    const dayColumns: DataGridColumn<Day>[] = [
        { id: 'date', label: t('fin.rev.colDate'), value: (d) => d.date, cell: (d) => <Link className="underline" href={`/finance/revenue/${d.date}`}>{format.date(d.date)}</Link>, rowHeader: true },
        ...amountColumns<Day>(report.totals),
        { id: 'collected', label: t('fin.rev.collected'), align: 'right', value: (d) => d.collected_minor, cell: (d) => money(d.collected_minor), footer: money(report.totals.collected_minor) },
        {
            id: 'state', label: t('fin.rev.colStatus'), value: (d) => d.status, filter: 'select', filterLabel: statusLabel,
            cell: (d) => <StatusBadge label={statusLabel(d.status)} tone={REVENUE_TONE[d.status] ?? 'neutral'} />,
        },
        { id: 'verifiedAt', label: t('fin.day.verifiedTitle'), value: (d) => d.verified_at ?? '', cell: (d) => (d.verified_at === null ? '—' : format.instant(d.verified_at)), hidden: true },
        { id: 'actions', label: t('inv.col.actions'), cell: (d) => <Button onClick={() => router.visit(`/finance/revenue/${d.date}`)} size="sm" type="button" variant="outline">{t('fin.pay.open')}</Button> },
    ];

    const outletColumns: DataGridColumn<OutletRevenue>[] = [
        { id: 'outlet', label: t('fin.rev.colOutlet'), value: (o) => outletLabel(o.code, o.name), rowHeader: true },
        ...amountColumns<OutletRevenue>(report.totals),
    ];

    const methodColumns: DataGridColumn<MethodTotals>[] = [
        { id: 'method', label: t('fin.col.method'), value: (m) => methodLabel(m.method), rowHeader: true },
        { id: 'received', label: t('fin.rev.colReceived'), align: 'right', value: (m) => m.received_minor, cell: (m) => money(m.received_minor) },
        { id: 'paidBack', label: t('fin.rev.colPaidBack'), align: 'right', value: (m) => m.paid_back_minor, cell: (m) => money(m.paid_back_minor) },
        { id: 'net', label: t('fin.rev.colNet'), align: 'right', value: (m) => m.net_minor, cell: (m) => money(m.net_minor) },
        { id: 'entries', label: t('fin.rev.colEntries'), align: 'right', value: (m) => m.entries },
    ];

    const monthColumns: DataGridColumn<Month>[] = [
        { id: 'month', label: t('fin.rev.colMonth'), value: (m) => m.month, cell: (m) => monthLabel(m.month), rowHeader: true },
        { id: 'days', label: t('fin.rev.colDays'), align: 'right', value: (m) => m.days },
        ...amountColumns<Month>(monthly.totals),
        { id: 'collected', label: t('fin.rev.collected'), align: 'right', value: (m) => m.collected_minor, cell: (m) => money(m.collected_minor), footer: money(monthly.totals.collected_minor) },
    ];

    const monthOutlets = useMemo<MonthOutlet[]>(() => monthly.months.flatMap((m) => m.outlets.map((o) => ({ ...o, month: m.month }))), [monthly.months]);
    const monthOutletColumns: DataGridColumn<MonthOutlet>[] = [
        { id: 'month', label: t('fin.rev.colMonth'), value: (o) => o.month, filter: 'select', filterLabel: monthLabel, cell: (o) => monthLabel(o.month), rowHeader: true },
        { id: 'outlet', label: t('fin.rev.colOutlet'), value: (o) => outletLabel(o.code, o.name), filter: 'select' },
        ...amountColumns<MonthOutlet>(),
    ];

    const net = (minor: number) => <span className={signClass(minor)}>{signed(minor, currency)}</span>;
    const corrections = report.corrections;
    const correctionOutletColumns: DataGridColumn<CorrectionOutlet>[] = [
        { id: 'outlet', label: t('fin.rev.colOutlet'), value: (o) => outletLabel(o.outlet_code, o.outlet_name), rowHeader: true },
        { id: 'base', label: t('fin.rev.base'), align: 'right', value: (o) => o.base_minor, cell: (o) => net(o.base_minor) },
        { id: 'service', label: t('fin.rev.service'), align: 'right', value: (o) => o.service_charge_minor, cell: (o) => net(o.service_charge_minor) },
        { id: 'tax', label: t('fin.rev.tax'), align: 'right', value: (o) => o.tax_minor, cell: (o) => net(o.tax_minor) },
        { id: 'total', label: t('fin.rev.billed'), align: 'right', value: (o) => o.total_minor, cell: (o) => net(o.total_minor) },
    ];
    const correctionPaymentColumns: DataGridColumn<CorrectionPayment>[] = [
        { id: 'method', label: t('fin.col.method'), value: (p) => methodLabel(p.method), rowHeader: true },
        { id: 'received', label: t('fin.rev.colReceived'), align: 'right', value: (p) => p.received_minor, cell: (p) => net(p.received_minor) },
    ];

    const kpis: [string, number][] = [
        [t('fin.rev.base'), report.totals.base_minor],
        [t('fin.rev.service'), report.totals.service_charge_minor],
        [t('fin.rev.tax'), report.totals.tax_minor],
        [t('fin.rev.billed'), report.totals.total_minor],
        [t('fin.rev.collected'), report.totals.collected_minor],
    ];

    return (
        <FinanceShell description={t('fin.rev.description')} title={t('fin.rev.title')} wide>
            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
                <FormField error={tooLong ? t('fin.rev.tooLong', { max: MAX_DAYS - 1 }) : undefined} label={t('fin.rev.period')}>
                    <DateRangePicker onChange={pickPeriod} value={period} />
                </FormField>
            </div>

            <section aria-label={t('fin.rev.title')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" data-testid="revenue-kpis">
                {kpis.map(([label, minor]) => <Metric key={label} label={label} value={money(minor)} />)}
                <Metric detail={t('fin.rev.unverifiedHint')} label={t('fin.rev.unverified')} value={format.number(report.unverified)} />
            </section>

            {corrections.count > 0 ? (
                <section aria-labelledby="fin-rev-corrections-h" className="flex flex-col gap-3" data-testid="revenue-corrections">
                    <h2 className="text-lg font-semibold" id="fin-rev-corrections-h">{t('fin.rev.corrections')}</h2>
                    <p className="text-sm text-muted-foreground">{t('fin.rev.correctionsHint', { count: corrections.count })} <Link className="underline" href="/finance/corrections?status=approved">{t('fin.rev.correctionsLink')}</Link></p>
                    <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        {([[t('fin.rev.base'), corrections.totals.base_minor], [t('fin.rev.service'), corrections.totals.service_charge_minor], [t('fin.rev.tax'), corrections.totals.tax_minor], [t('fin.rev.billed'), corrections.totals.total_minor], [t('fin.rev.collected'), corrections.totals.received_minor]] as [string, number][]).map(([label, minor]) => (
                            <div className="border border-border bg-surface p-3" key={label}>
                                <dt className="text-xs text-muted-foreground">{label}</dt>
                                <dd className="mt-1 text-base font-semibold tabular-nums">{net(minor)}</dd>
                            </div>
                        ))}
                    </dl>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <DataGrid caption={t('fin.rev.correctionsOutlets')} columns={correctionOutletColumns} getRowId={(o) => o.outlet_code} id="fin.revenue.corrections.outlets" rows={corrections.revenue} testId="revenue-correction-outlets" />
                        <DataGrid caption={t('fin.rev.correctionsMethods')} columns={correctionPaymentColumns} getRowId={(p) => p.method} id="fin.revenue.corrections.methods" rows={corrections.payments} testId="revenue-correction-methods" />
                    </div>
                    <p className="text-sm text-muted-foreground">{t('fin.rev.correctionsBefore')}</p>
                </section>
            ) : null}

            <Tabs onValueChange={setTab} value={tab}>
                <TabsList>
                    <TabsTrigger value="daily">{t('fin.rev.tabDaily')}</TabsTrigger>
                    <TabsTrigger value="outlets">{t('fin.rev.tabOutlets')}</TabsTrigger>
                    <TabsTrigger value="methods">{t('fin.rev.tabMethods')}</TabsTrigger>
                    <TabsTrigger value="monthly">{t('fin.rev.tabMonthly')}</TabsTrigger>
                </TabsList>

                <TabsContent value="daily">
                    <DataGrid caption={t('fin.rev.tabDaily')} columns={dayColumns} empty={<EmptyState title={t('fin.rev.emptyDaily')} />} footerLabel={t('fin.age.total')} getRowId={(d) => d.id} id="fin.revenue.days" rows={report.days} testId="revenue-days" />
                </TabsContent>

                <TabsContent value="outlets">
                    <DataGrid caption={t('fin.rev.tabOutlets')} columns={outletColumns} empty={<EmptyState title={t('fin.rev.emptyOutlets')} />} footerLabel={t('fin.age.total')} getRowId={(o) => o.code} id="fin.revenue.outlets" rows={report.outlets} testId="revenue-outlets" />
                </TabsContent>

                <TabsContent value="methods">
                    <DataGrid caption={t('fin.rev.tabMethods')} columns={methodColumns} empty={<EmptyState title={t('fin.rev.emptyMethods')} />} getRowId={(m) => m.method} id="fin.revenue.methods" rows={report.methods} testId="revenue-methods" />
                </TabsContent>

                <TabsContent className="flex flex-col gap-6" value="monthly">
                    <div className="max-w-xs print:hidden">
                        <FormField label={t('fin.rev.year')}>
                            <Select onChange={(e) => go({ from: report.from, to: report.to, year: Number(e.target.value) })} searchable={false} value={String(monthly.year)}>
                                {years.map((y) => <option key={y} value={String(y)}>{y}</option>)}
                            </Select>
                        </FormField>
                    </div>

                    <section aria-labelledby="fin-rev-months-h" className="flex flex-col gap-3">
                        <h2 className="text-lg font-semibold" id="fin-rev-months-h">{t('fin.rev.monthlyTitle', { year: monthly.year })}</h2>
                        <DataGrid caption={t('fin.rev.tabMonthly')} columns={monthColumns} empty={<EmptyState title={t('fin.rev.emptyMonthly')} />} footerLabel={t('fin.age.total')} getRowId={(m) => m.month} id="fin.revenue.months" rows={monthly.months} testId="revenue-months" />
                    </section>

                    <section aria-labelledby="fin-rev-month-outlets-h" className="flex flex-col gap-3">
                        <h2 className="text-lg font-semibold" id="fin-rev-month-outlets-h">{t('fin.rev.monthlyOutlets')}</h2>
                        <DataGrid caption={t('fin.rev.monthlyOutlets')} columns={monthOutletColumns} empty={<EmptyState title={t('fin.rev.emptyMonthly')} />} getRowId={(o) => `${o.month}:${o.code}`} id="fin.revenue.monthOutlets" rows={monthOutlets} testId="revenue-month-outlets" />
                    </section>
                </TabsContent>
            </Tabs>
        </FinanceShell>
    );
}
