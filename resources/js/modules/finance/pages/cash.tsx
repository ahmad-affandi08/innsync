import { router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DateRangePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { EXCEPTION_TONE } from '@/modules/finance/lib/finance';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Deposit = {
    id: string; number: string; deposited_minor: number; variance_minor: number; reason: string | null; note: string | null; received_by: string | null; received_on: string; exception_status: string | null;
};
type Shift = {
    id: string; number: string; cashier_id: string; cashier_name: string | null; closed_on: string; currency: string; opening_float_minor: number; expected_cash_minor: number; counted_cash_minor: number;
    shift_variance_minor: number; drops_minor: number; cash_net_minor: number; declared_minor: number; received: boolean; deposit: Deposit | null; may_receive: boolean;
};
type Exception = {
    id: string; status: 'open' | 'explained' | 'recovered' | 'waived'; variance_minor: number; currency: string; deposit_number: string; shift_number: string; cashier_name: string | null; received_by: string | null;
    reason: string | null; closed_on: string; resolution: string | null; resolved_by: string | null; resolved_at: string | null; lock_version: number; may_settle: boolean;
};
type Overview = { shifts: Shift[]; exceptions: Exception[]; waiting: number; open_exceptions: number; settlements: string[]; may: { reconcile: boolean } };
type Filters = { from: string; to: string; only: string };
type ReceiveForm = { shift: Shift; amount: string; reason: string; note: string };
type SettleForm = { exception: Exception; status: string; resolution: string };

const ONLY = ['waiting', 'received'] as const;

/** The cash of closed cashier shifts against the cash finance received, and the differences left to settle. */
export default function CashPage({ filters, overview }: { filters: Filters; overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const receive = useServerAction();
    const settle = useServerAction();
    const url = usePage().url;
    const [tab, setTab] = useState(() => (new URLSearchParams(url.split('?')[1] ?? '').get('tab') === 'exceptions' ? 'exceptions' : 'shifts'));
    const [receiveForm, setReceiveForm] = useState<ReceiveForm | null>(null);
    const [settleForm, setSettleForm] = useState<SettleForm | null>(null);
    const [badAmount, setBadAmount] = useState(false);
    const reload = ['overview'];
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(receiveForm === null ? null : { id: receiveForm.shift.id, amount: receiveForm.amount, reason: receiveForm.reason })]);
    const excStatus = (s: string) => t(`fin.cash.excStatus.${s}` as MessageKey);
    const onlyLabel = (o: string) => (o === 'waiting' ? t('fin.cash.onlyWaiting') : o === 'received' ? t('fin.cash.onlyReceived') : t('fin.cash.allShifts'));
    const exceptions = useMemo(() => [...overview.exceptions].sort((a, b) => Number(b.status === 'open') - Number(a.status === 'open')), [overview.exceptions]);

    function go(next: Partial<Filters>) {
        const query = { ...filters, ...next };

        router.get('/finance/cash', Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '')), { preserveScroll: true, preserveState: true });
    }

    /** A signed difference as a badge: nothing, short or over. */
    function variance(minor: number, currency: string) {
        if (minor === 0) return <StatusBadge label={t('fin.cash.noVariance')} tone="success" />;

        return minor < 0
            ? <StatusBadge label={t('fin.cash.short', { amount: format.money(-minor, currency) })} tone="danger" />
            : <StatusBadge label={t('fin.cash.over', { amount: format.money(minor, currency) })} tone="warning" />;
    }

    function openReceive(shift: Shift) {
        receive.clear();
        setBadAmount(false);
        setReceiveForm({ shift, amount: '', reason: '', note: '' });
    }

    function openSettle(exception: Exception) {
        settle.clear();
        setSettleForm({ exception, status: overview.settlements[0] ?? '', resolution: '' });
    }

    async function saveReceive() {
        if (receiveForm === null) return;
        const minor = parseMajorToMinor(receiveForm.amount, receiveForm.shift.currency);

        setBadAmount(minor === null);
        if (minor === null) return;
        const done = await receive.run(`/finance/cash/shifts/${receiveForm.shift.id}/receive`, {
            body: { deposited_minor: minor, reason: receiveForm.reason.trim() || null, note: receiveForm.note.trim() || null },
            idempotencyKey: intent,
            reload,
        });
        if (done !== null) setReceiveForm(null);
    }

    async function saveSettle() {
        if (settleForm === null) return;
        const done = await settle.run(`/finance/cash/exceptions/${settleForm.exception.id}/settle`, {
            body: { status: settleForm.status, resolution: settleForm.resolution.trim(), lock_version: settleForm.exception.lock_version },
            reload,
        });
        if (done !== null) setSettleForm(null);
    }

    const counted = receiveForm === null ? null : parseMajorToMinor(receiveForm.amount, receiveForm.shift.currency);
    const difference = receiveForm === null || counted === null ? null : counted - receiveForm.shift.cash_net_minor;

    const shiftColumns: DataGridColumn<Shift>[] = [
        { id: 'number', label: t('fin.cash.colShift'), value: (s) => s.number, rowHeader: true },
        { id: 'cashier', label: t('fin.cash.colCashier'), value: (s) => s.cashier_name ?? '', cell: (s) => s.cashier_name ?? '—' },
        { id: 'closed', label: t('fin.cash.colClosed'), value: (s) => s.closed_on, cell: (s) => format.date(s.closed_on) },
        { id: 'float', label: t('fin.cash.colFloat'), align: 'right', value: (s) => s.opening_float_minor, cell: (s) => format.money(s.opening_float_minor, s.currency), hidden: true },
        { id: 'drops', label: t('fin.cash.colDrops'), align: 'right', value: (s) => s.drops_minor, cell: (s) => format.money(s.drops_minor, s.currency), hidden: true },
        { id: 'system', label: t('fin.cash.colSystem'), align: 'right', value: (s) => s.cash_net_minor, cell: (s) => format.money(s.cash_net_minor, s.currency) },
        { id: 'declared', label: t('fin.cash.colDeclared'), align: 'right', value: (s) => s.declared_minor, cell: (s) => format.money(s.declared_minor, s.currency) },
        { id: 'receipt', label: t('fin.cash.colReceipt'), value: (s) => s.deposit?.number ?? '', cell: (s) => s.deposit?.number ?? '—', hidden: true },
        { id: 'deposited', label: t('fin.cash.colDeposited'), align: 'right', value: (s) => s.deposit?.deposited_minor ?? -1, cell: (s) => (s.deposit === null ? '—' : format.money(s.deposit.deposited_minor, s.currency)) },
        { id: 'variance', label: t('fin.cash.colVariance'), value: (s) => s.deposit?.variance_minor ?? 0, cell: (s) => (s.deposit === null ? '—' : variance(s.deposit.variance_minor, s.currency)) },
        { id: 'by', label: t('fin.cash.colReceivedBy'), value: (s) => s.deposit?.received_by ?? '', cell: (s) => s.deposit?.received_by ?? '—', hidden: true },
        {
            id: 'state', label: t('fin.cash.colState'), value: (s) => (s.received ? 'received' : 'waiting'), filter: 'select', filterLabel: (v) => t(v === 'received' ? 'fin.cash.stateReceived' : 'fin.cash.stateWaiting'),
            cell: (s) => <StatusBadge label={t(s.received ? 'fin.cash.stateReceived' : 'fin.cash.stateWaiting')} tone={s.received ? 'success' : 'pending'} />,
        },
        ...(overview.may.reconcile ? [{
            id: 'actions', label: t('inv.col.actions'),
            cell: (s: Shift) => (s.may_receive ? <Button onClick={() => openReceive(s)} size="sm" type="button">{t('fin.cash.receive')}</Button> : null),
        }] : []),
    ];

    const exceptionColumns: DataGridColumn<Exception>[] = [
        { id: 'deposit', label: t('fin.cash.colReceipt'), value: (e) => e.deposit_number, rowHeader: true },
        { id: 'shift', label: t('fin.cash.colShift'), value: (e) => e.shift_number },
        { id: 'cashier', label: t('fin.cash.colCashier'), value: (e) => e.cashier_name ?? '', cell: (e) => e.cashier_name ?? '—' },
        { id: 'closed', label: t('fin.cash.colClosed'), value: (e) => e.closed_on, cell: (e) => format.date(e.closed_on) },
        { id: 'variance', label: t('fin.cash.colVariance'), value: (e) => e.variance_minor, cell: (e) => variance(e.variance_minor, e.currency) },
        { id: 'by', label: t('fin.cash.colReceivedBy'), value: (e) => e.received_by ?? '', cell: (e) => e.received_by ?? '—', hidden: true },
        { id: 'reason', label: t('fin.cash.colReason'), value: (e) => e.reason ?? '', cell: (e) => e.reason ?? '—' },
        { id: 'state', label: t('fin.cash.colState'), value: (e) => e.status, filter: 'select', filterLabel: excStatus, cell: (e) => <StatusBadge label={excStatus(e.status)} tone={EXCEPTION_TONE[e.status] ?? 'neutral'} /> },
        { id: 'resolution', label: t('fin.cash.colResolution'), value: (e) => e.resolution ?? '', cell: (e) => e.resolution ?? '—' },
        { id: 'resolvedBy', label: t('fin.cash.colResolvedBy'), value: (e) => e.resolved_by ?? '', cell: (e) => (e.resolved_by === null ? '—' : `${e.resolved_by}${e.resolved_at === null ? '' : ` · ${format.instant(e.resolved_at)}`}`), hidden: true },
        ...(overview.may.reconcile ? [{
            id: 'actions', label: t('inv.col.actions'),
            cell: (e: Exception) => (e.may_settle ? <Button onClick={() => openSettle(e)} size="sm" type="button" variant="outline">{t('fin.cash.settle')}</Button> : null),
        }] : []),
    ];

    const receiveCurrency = receiveForm?.shift.currency ?? 'IDR';

    return (
        <FinanceShell description={t('fin.cash.description')} title={t('fin.cash.title')} wide>
            <section aria-label={t('fin.cash.title')} className="grid gap-3 sm:grid-cols-2 lg:max-w-2xl" data-testid="cash-kpis">
                <Metric label={t('fin.cash.waiting')} value={format.number(overview.waiting)} />
                <Metric label={t('fin.cash.openExceptions')} value={format.number(overview.open_exceptions)} />
            </section>

            <Tabs onValueChange={setTab} value={tab}>
                <TabsList>
                    <TabsTrigger value="shifts">{t('fin.cash.tabShifts')}</TabsTrigger>
                    <TabsTrigger value="exceptions">{t('fin.cash.tabExceptions')}</TabsTrigger>
                </TabsList>

                <TabsContent className="flex flex-col gap-4" value="shifts">
                    <div className="flex flex-wrap items-end gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
                        <FormField className="w-full sm:w-64" label={t('fin.cash.show')}>
                            <Select onChange={(e) => go({ only: e.target.value })} searchable={false} value={filters.only}>
                                <option value="">{onlyLabel('')}</option>
                                {ONLY.map((o) => <option key={o} value={o}>{onlyLabel(o)}</option>)}
                            </Select>
                        </FormField>
                        <FormField label={t('fin.cash.period')}>
                            <DateRangePicker onChange={(range) => go(range)} value={{ from: filters.from, to: filters.to }} />
                        </FormField>
                        {filters.from !== '' || filters.to !== '' ? <Button onClick={() => go({ from: '', to: '' })} type="button" variant="ghost">{t('fin.cash.clearPeriod')}</Button> : null}
                    </div>

                    <DataGrid caption={t('fin.cash.tabShifts')} columns={shiftColumns} empty={<EmptyState title={t('fin.cash.emptyShifts')} />} getRowId={(s) => s.id} id="fin.cash.shifts" rows={overview.shifts} testId="cash-shifts" />
                </TabsContent>

                <TabsContent className="flex flex-col gap-3" value="exceptions">
                    <p className="text-sm text-muted-foreground">{t('fin.cash.openFirst')}</p>
                    <DataGrid caption={t('fin.cash.tabExceptions')} columns={exceptionColumns} empty={<EmptyState title={t('fin.cash.exceptionsEmpty')} />} getRowId={(e) => e.id} id="fin.cash.exceptions" rows={exceptions} testId="cash-exceptions" />
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<>
                    <Button disabled={receive.busy} onClick={() => setReceiveForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={receive.busy} onClick={() => void saveReceive()} type="button">{t('fin.cash.save')}</Button>
                </>}
                onClose={() => setReceiveForm(null)}
                open={receiveForm !== null}
                title={t('fin.cash.receiveTitle', { number: receiveForm?.shift.number ?? '' })}
            >
                {receiveForm !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.cash.receiveHint')}</p>
                        {receive.error !== null ? <ErrorState {...errorCopy} error={receive.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField
                            error={badAmount ? t('fin.cash.badAmount') : receive.fieldError('deposited_minor')}
                            field="deposited_minor"
                            hint={t('fin.cash.expected', { amount: format.money(receiveForm.shift.cash_net_minor, receiveCurrency) })}
                            label={t('fin.cash.counted', { currency: receiveCurrency })}
                        >
                            <Input inputMode="decimal" onChange={(e) => setReceiveForm({ ...receiveForm, amount: e.target.value })} value={receiveForm.amount} />
                        </FormField>
                        {difference !== null ? (
                            <p aria-live="polite" className="text-sm" data-testid="cash-live-variance">
                                {difference === 0 ? t('fin.cash.liveMatch') : t('fin.cash.liveVariance', { difference: `${difference > 0 ? '+' : '−'}${format.money(Math.abs(difference), receiveCurrency)}` })}
                            </p>
                        ) : null}
                        <FormField error={receive.fieldError('reason')} field="reason" hint={t('fin.cash.reasonHint')} label={t('fin.cash.reason')} required={difference !== null && difference !== 0}>
                            <Textarea maxLength={300} onChange={(e) => setReceiveForm({ ...receiveForm, reason: e.target.value })} value={receiveForm.reason} />
                        </FormField>
                        <FormField error={receive.fieldError('note')} field="note" label={t('fin.cash.note')}>
                            <Input maxLength={200} onChange={(e) => setReceiveForm({ ...receiveForm, note: e.target.value })} value={receiveForm.note} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={settle.busy} onClick={() => setSettleForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={settle.busy} onClick={() => void saveSettle()} type="button">{t('fin.cash.settleSave')}</Button>
                </>}
                onClose={() => setSettleForm(null)}
                open={settleForm !== null}
                title={t('fin.cash.settleTitle', { number: settleForm?.exception.deposit_number ?? '' })}
            >
                {settleForm !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.cash.settleHint')}</p>
                        {settle.error !== null ? <ErrorState {...errorCopy} error={settle.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={settle.fieldError('status')} field="status" label={t('fin.cash.settleStatus')}>
                            <Select onChange={(e) => setSettleForm({ ...settleForm, status: e.target.value })} searchable={false} value={settleForm.status}>
                                {overview.settlements.map((s) => <option key={s} value={s}>{excStatus(s)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={settle.fieldError('resolution')} field="resolution" label={t('fin.cash.resolution')}>
                            <Textarea maxLength={300} onChange={(e) => setSettleForm({ ...settleForm, resolution: e.target.value })} value={settleForm.resolution} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
