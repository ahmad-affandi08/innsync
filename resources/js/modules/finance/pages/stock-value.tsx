import { router } from '@inertiajs/react';
import { useState } from 'react';

import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DateRangePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Metric } from '@/components/ui/metric';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { REPORT_MAX_DAYS, signClass, spanDays, useDepartmentLabel, type StockValueAmounts, type StockValueReport } from '@/modules/finance/lib/finance';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = StockValueAmounts & { department: string };
type Location = StockValueReport['locations'][number];

/** The value of the stock at the end of a period and what moved it, by department and by location, from the inventory ledger. */
export default function StockValuePage({ report }: { report: StockValueReport }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const department = useDepartmentLabel();
    const [period, setPeriod] = useState({ from: report.from, to: report.to });
    const [tooLong, setTooLong] = useState(false);
    const money = (minor: number) => format.money(minor, report.currency);
    const signed = (minor: number) => <span className={signClass(minor)}>{money(minor)}</span>;
    const totals = report.totals;

    function pickPeriod(range: { from: string; to: string }) {
        setPeriod(range);

        if (spanDays(range.from, range.to) >= REPORT_MAX_DAYS) {
            setTooLong(true);

            return;
        }

        setTooLong(false);
        router.get('/finance/stock-value', range, { preserveScroll: true, preserveState: true });
    }

    const amount = (id: string, label: string, key: keyof StockValueAmounts): DataGridColumn<Row> => ({
        id, label, align: 'right', value: (r) => r[key], cell: (r) => signed(r[key]), footer: signed(totals[key]),
    });
    const columns: DataGridColumn<Row>[] = [
        { id: 'department', label: t('fin.col.department'), value: (r) => department(r.department), rowHeader: true },
        amount('opening', t('fin.sv.opening'), 'opening_minor'),
        amount('received', t('fin.sv.received'), 'received_minor'),
        amount('returned', t('fin.sv.returned'), 'returned_minor'),
        amount('issued', t('fin.sv.issued'), 'issued_minor'),
        amount('writtenOff', t('fin.sv.writtenOff'), 'written_off_minor'),
        amount('adjusted', t('fin.sv.adjusted'), 'adjusted_minor'),
        amount('closing', t('fin.sv.closing'), 'closing_minor'),
    ];
    const locationColumns: DataGridColumn<Location>[] = [
        { id: 'code', label: t('fin.sv.colLocation'), value: (l) => `${l.code} · ${l.name}`, rowHeader: true },
        { id: 'value', label: t('fin.sv.closing'), align: 'right', value: (l) => l.value_minor, cell: (l) => signed(l.value_minor), footer: money(report.locations.reduce((sum, l) => sum + l.value_minor, 0)) },
    ];

    return (
        <FinanceShell description={t('fin.sv.description')} title={t('fin.sv.title')} wide>
            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
                <FormField error={tooLong ? t('fin.pnl.tooLong') : undefined} label={t('fin.rev.period')}>
                    <DateRangePicker onChange={pickPeriod} value={period} />
                </FormField>
            </div>

            <p className="text-sm text-muted-foreground">{t('fin.sv.explain')}</p>
            <p className="text-sm text-muted-foreground">{t('fin.sv.openingAsOf', { date: format.date(report.opening_as_of), to: format.date(report.to) })}</p>

            <section aria-label={t('fin.sv.title')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="stock-value-kpis">
                <Metric label={t('fin.sv.opening')} value={signed(totals.opening_minor)} />
                <Metric label={t('fin.sv.received')} value={signed(totals.received_minor)} />
                <Metric label={t('fin.sv.issued')} value={signed(totals.issued_minor + totals.written_off_minor)} />
                <Metric label={t('fin.sv.closing')} value={signed(totals.closing_minor)} />
            </section>

            <section aria-labelledby="fin-sv-dept-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-sv-dept-h">{t('fin.sv.byDepartment')}</h2>
                <DataGrid
                    caption={t('fin.sv.byDepartment')} columns={columns} empty={<EmptyState title={t('fin.sv.empty')} />} footerLabel={t('fin.age.total')}
                    getRowId={(r) => r.department} id="fin.stockvalue.departments" rows={report.departments} testId="stock-value-departments"
                />
            </section>

            <section aria-labelledby="fin-sv-loc-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-sv-loc-h">{t('fin.sv.byLocation')}</h2>
                <DataGrid
                    caption={t('fin.sv.byLocation')} columns={locationColumns} empty={<EmptyState title={t('fin.sv.emptyLocations')} />} footerLabel={t('fin.age.total')}
                    getRowId={(l) => l.id} id="fin.stockvalue.locations" rows={report.locations} testId="stock-value-locations"
                />
            </section>
        </FinanceShell>
    );
}
