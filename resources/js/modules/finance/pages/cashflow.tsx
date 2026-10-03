import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker, DateRangePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Metric } from '@/components/ui/metric';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import {
    minorToMajorText, parseSignedMajorToMinor, REPORT_MAX_DAYS, signClass, spanDays, useCashMethodLabel,
    type CashGroup, type CashPayment, type CashReceipt, type CashFlowReport,
} from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Form = { cash: string; bank: string; date: string; reason: string };
type Line = { id: string; source: string; method: string; group: CashGroup; amount_minor: number; received_minor?: number; paid_back_minor?: number };

const GROUPS: CashGroup[] = ['cash', 'bank'];

/** What came in and went out by cash and by bank in a period, and the balances that follow from the opening the owner set. */
export default function CashFlowPage({ report }: { report: CashFlowReport }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const methodLabel = useCashMethodLabel();
    const [period, setPeriod] = useState({ from: report.from, to: report.to });
    const [tooLong, setTooLong] = useState(false);
    const [form, setForm] = useState<Form | null>(null);
    const [badField, setBadField] = useState<'cash' | 'bank' | null>(null);
    const currency = report.currency;
    const balances = report.balances;
    const money = (minor: number) => format.money(minor, currency);
    const signed = (minor: number) => <span className={signClass(minor)}>{money(minor)}</span>;
    const groupLabel = (g: string) => t(g === 'cash' ? 'fin.cf.cash' : 'fin.cf.bank');
    const sourceLabel = (s: string) => t(`fin.cf.source.${s}` as MessageKey);
    const reload = ['report'];

    function pickPeriod(range: { from: string; to: string }) {
        setPeriod(range);

        if (spanDays(range.from, range.to) >= REPORT_MAX_DAYS) {
            setTooLong(true);

            return;
        }

        setTooLong(false);
        router.get('/finance/cashflow', range, { preserveScroll: true, preserveState: true });
    }

    function openOpening() {
        action.clear();
        setBadField(null);
        setForm({
            cash: balances === null ? '' : minorToMajorText(balances.cash.opening_minor, currency),
            bank: balances === null ? '' : minorToMajorText(balances.bank.opening_minor, currency),
            date: balances?.opening_date ?? report.today,
            reason: '',
        });
    }

    async function save() {
        if (form === null) return;
        const cash = parseSignedMajorToMinor(form.cash, currency);
        const bank = parseSignedMajorToMinor(form.bank, currency);

        setBadField(cash === null ? 'cash' : bank === null ? 'bank' : null);
        if (cash === null || bank === null) return;
        const done = await action.run('/finance/cashflow/opening', {
            body: {
                cash_opening_minor: cash, bank_opening_minor: bank, opening_date: form.date, reason: form.reason.trim(),
                ...(balances === null ? {} : { lock_version: balances.lock_version }),
            },
            reload,
        });
        if (done !== null) setForm(null);
    }

    const receipts: Line[] = report.receipts.map((r: CashReceipt, i) => ({ id: `r${i}`, ...r }));
    const payments: Line[] = report.payments.map((p: CashPayment, i) => ({ id: `p${i}`, ...p }));

    const lineColumns = (lines: Line[], total: number, withReturns: boolean): DataGridColumn<Line>[] => [
        { id: 'source', label: t('fin.cf.colSource'), value: (l) => l.source, filter: 'select', filterLabel: sourceLabel, cell: (l) => sourceLabel(l.source), rowHeader: true },
        { id: 'method', label: t('fin.col.method'), value: (l) => methodLabel(l.source, l.method) },
        { id: 'group', label: t('fin.cf.colGroup'), value: (l) => l.group, filter: 'select', filterLabel: groupLabel, cell: (l) => groupLabel(l.group) },
        ...(withReturns ? [
            { id: 'received', label: t('fin.rev.colReceived'), align: 'right' as const, value: (l: Line) => l.received_minor ?? 0, cell: (l: Line) => money(l.received_minor ?? 0), hidden: true },
            { id: 'paidBack', label: t('fin.rev.colPaidBack'), align: 'right' as const, value: (l: Line) => l.paid_back_minor ?? 0, cell: (l: Line) => money(l.paid_back_minor ?? 0), hidden: true },
        ] : []),
        { id: 'amount', label: t('fin.col.amount'), align: 'right', value: (l) => l.amount_minor, cell: (l) => signed(l.amount_minor), footer: lines.length === 0 ? undefined : money(total) },
    ];

    const splitRows: { group: CashGroup; in: number; out: number; net: number; opening: number | null; closing: number | null }[] = GROUPS.map((g) => ({
        group: g, in: report.totals.in[g], out: report.totals.out[g], net: report.net[g], opening: balances?.[g].opening_minor ?? null, closing: balances?.[g].closing_minor ?? null,
    }));

    return (
        <FinanceShell
            actions={report.may.manage ? <Button onClick={openOpening} type="button" variant="outline">{t('fin.cf.openingButton')}</Button> : undefined}
            description={t('fin.cf.description')}
            title={t('fin.cf.title')}
            wide
        >
            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
                <FormField error={tooLong ? t('fin.pnl.tooLong') : undefined} label={t('fin.rev.period')}>
                    <DateRangePicker onChange={pickPeriod} value={period} />
                </FormField>
            </div>

            <p className="text-sm text-muted-foreground">{t('fin.cf.explain')}</p>

            <section aria-label={t('fin.cf.title')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5" data-testid="cashflow-kpis">
                <Metric label={t('fin.cf.in')} value={money(report.totals.in.total)} />
                <Metric label={t('fin.cf.out')} value={money(report.totals.out.total)} />
                <Metric label={t('fin.cf.net')} value={signed(report.net.total)} />
                {GROUPS.map((g) => (
                    <Metric
                        detail={balances === null ? t('fin.cf.notSetShort') : !balances.reached ? t('fin.cf.notReachedShort') : t('fin.cf.closingAt', { date: format.date(report.to) })}
                        key={g}
                        label={t(g === 'cash' ? 'fin.cf.closingCash' : 'fin.cf.closingBank')}
                        value={balances?.[g].closing_minor == null ? '—' : signed(balances[g].closing_minor)}
                    />
                ))}
            </section>

            <section aria-labelledby="fin-cf-split-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-cf-split-h">{t('fin.cf.split')}</h2>
                <Table data-testid="cashflow-split">
                    <caption className="sr-only">{t('fin.cf.split')}</caption>
                    <TableHeader>
                        <TableRow>
                            <TableHead scope="col">{t('fin.cf.colGroup')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('fin.cf.in')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('fin.cf.out')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('fin.cf.net')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('fin.cf.opening')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('fin.cf.closing')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {splitRows.map((r) => (
                            <TableRow key={r.group}>
                                <TableHead scope="row">{groupLabel(r.group)}</TableHead>
                                <TableCell className="text-right tabular-nums">{money(r.in)}</TableCell>
                                <TableCell className="text-right tabular-nums">{money(r.out)}</TableCell>
                                <TableCell className="text-right tabular-nums">{signed(r.net)}</TableCell>
                                <TableCell className="text-right tabular-nums">{r.opening === null ? '—' : signed(r.opening)}</TableCell>
                                <TableCell className="text-right tabular-nums">{r.closing === null ? '—' : signed(r.closing)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableHead scope="row">{t('fin.age.total')}</TableHead>
                            <TableCell className="text-right tabular-nums">{money(report.totals.in.total)}</TableCell>
                            <TableCell className="text-right tabular-nums">{money(report.totals.out.total)}</TableCell>
                            <TableCell className="text-right tabular-nums">{signed(report.net.total)}</TableCell>
                            <TableCell />
                            <TableCell />
                        </TableRow>
                    </TableFooter>
                </Table>
            </section>

            {balances === null ? (
                <Alert actions={report.may.manage ? <Button onClick={openOpening} size="sm" type="button" variant="outline">{t('fin.cf.openingButton')}</Button> : undefined} title={t('fin.cf.notSet')} tone="info">
                    {t('fin.cf.notSetHint')}
                </Alert>
            ) : !balances.reached ? (
                <Alert title={t('fin.cf.notReached', { date: format.date(balances.opening_date) })} tone="warning">{t('fin.cf.notReachedHint', { to: format.date(report.to) })}</Alert>
            ) : (
                <p className="text-sm text-muted-foreground">{t('fin.cf.openingFrom', { date: format.date(balances.opening_date) })}</p>
            )}

            <section aria-labelledby="fin-cf-in-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-cf-in-h">{t('fin.cf.receipts')}</h2>
                <DataGrid
                    caption={t('fin.cf.receipts')} columns={lineColumns(receipts, report.totals.in.total, true)} empty={<EmptyState title={t('fin.cf.emptyReceipts')} />}
                    footerLabel={t('fin.age.total')} getRowId={(l) => l.id} id="fin.cashflow.receipts" rows={receipts} testId="cashflow-receipts"
                />
            </section>

            <section aria-labelledby="fin-cf-out-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-cf-out-h">{t('fin.cf.payments')}</h2>
                <DataGrid
                    caption={t('fin.cf.payments')} columns={lineColumns(payments, report.totals.out.total, false)} empty={<EmptyState title={t('fin.cf.emptyPayments')} />}
                    footerLabel={t('fin.age.total')} getRowId={(l) => l.id} id="fin.cashflow.payments" rows={payments} testId="cashflow-payments"
                />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('fin.cf.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.cf.openingTitle')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.cf.openingHint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={badField === 'cash' ? t('fin.cf.badAmount') : action.fieldError('cash_opening_minor')} field="cash_opening_minor" label={t('fin.cf.cashOpening', { currency })}>
                                <Input inputMode="decimal" onChange={(e) => setForm({ ...form, cash: e.target.value })} value={form.cash} />
                            </FormField>
                            <FormField error={badField === 'bank' ? t('fin.cf.badAmount') : action.fieldError('bank_opening_minor')} field="bank_opening_minor" label={t('fin.cf.bankOpening', { currency })}>
                                <Input inputMode="decimal" onChange={(e) => setForm({ ...form, bank: e.target.value })} value={form.bank} />
                            </FormField>
                        </div>
                        <FormField error={action.fieldError('opening_date')} field="opening_date" hint={t('fin.cf.dateHint')} label={t('fin.cf.openingDate')}>
                            <DatePicker max={report.today} onChange={(e) => setForm({ ...form, date: e.target.value })} value={form.date} />
                        </FormField>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fin.cf.reason')}>
                            <Textarea maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
