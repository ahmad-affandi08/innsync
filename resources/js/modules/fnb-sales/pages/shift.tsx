import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Metric } from '@/components/ui/metric';
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import type { ShiftOverview, ShiftView } from '@/modules/fnb-sales/lib/fnb';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

/** The cashier's shift: opened with a float, and closed by counting the cash against what the payments say it should be. */
export default function ShiftPage({ overview }: { overview: ShiftOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [outlet, setOutlet] = useState(overview.outlets[0]?.id ?? '');
    const [float, setFloat] = useState('');
    const [counted, setCounted] = useState('');
    const [reason, setReason] = useState('');
    const [bad, setBad] = useState<'float' | 'counted' | null>(null);
    const [closed, setClosed] = useState<ShiftView | null>(null);
    const currency = overview.currency;
    const money = (minor: number) => format.money(minor, currency);
    const shift = overview.shift;
    const method = (m: string) => t(`fnb.pay.method.${m}` as MessageKey);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    async function open() {
        const minor = parseMajorToMinor(float === '' ? '0' : float, currency);

        setBad(minor === null ? 'float' : null);
        if (minor === null) return;
        const done = await action.run('/fnb/shift', { idempotencyKey: newIdempotencyKey(), body: { outlet_id: outlet, opening_float_minor: minor }, reload: ['overview'] });
        if (done !== null) setFloat('');
    }

    async function close() {
        if (shift === null) return;
        const minor = parseMajorToMinor(counted, currency);

        setBad(minor === null ? 'counted' : null);
        if (minor === null) return;
        const done = await action.run<{ shift: ShiftView }>(`/fnb/shift/${shift.id}/close`, { idempotencyKey: newIdempotencyKey(), body: { counted_cash_minor: minor, reason: reason.trim() === '' ? null : reason.trim(), lock_version: shift.lock_version }, reload: ['overview'] });

        if (done !== null) {
            setClosed(done.shift);
            setCounted('');
            setReason('');
        }
    }

    const recentColumns: DataGridColumn<ShiftView>[] = [
        { id: 'number', label: t('fnb.shift.colShift'), value: (s) => s.number, rowHeader: true },
        { id: 'outlet', label: t('fnb.shift.outlet'), value: (s) => s.outlet ?? '—', filter: 'select' },
        { id: 'cashier', label: t('fnb.shift.colCashier'), value: (s) => s.cashier ?? '—', filter: 'select' },
        { id: 'opened', label: t('fnb.shift.colOpened'), value: (s) => s.opened_at, cell: (s) => format.instant(s.opened_at) },
        { id: 'status', label: t('fnb.shift.colStatus'), value: (s) => s.status, filter: 'select', filterLabel: (v) => t(`fnb.shift.status.${v}` as MessageKey), cell: (s) => <StatusBadge label={t(`fnb.shift.status.${s.status}` as MessageKey)} tone={s.status === 'open' ? 'info' : 'neutral'} /> },
        { id: 'expected', label: t('fnb.shift.colExpected'), align: 'right', value: (s) => s.expected_cash_minor ?? 0, cell: (s) => (s.expected_cash_minor === null ? '—' : money(s.expected_cash_minor)) },
        { id: 'counted', label: t('fnb.shift.colCounted'), align: 'right', value: (s) => s.counted_cash_minor ?? 0, cell: (s) => (s.counted_cash_minor === null ? '—' : money(s.counted_cash_minor)) },
        { id: 'variance', label: t('fnb.shift.colVariance'), align: 'right', value: (s) => s.variance_minor ?? 0, cell: (s) => (s.variance_minor === null ? '—' : <span className={s.variance_minor < 0 ? 'text-danger' : ''}>{money(s.variance_minor)}</span>) },
    ];

    const cashierPanel = !overview.may.operate ? null : shift === null ? (
        <section aria-labelledby="fnb-shift-open-h" className="flex max-w-xl flex-col gap-3 border border-border bg-surface p-4">
            <h2 className="text-lg font-semibold" id="fnb-shift-open-h">{t('fnb.shift.openTitle')}</h2>
            <p className="text-sm text-muted-foreground">{t('fnb.shift.noneOpen')}</p>
            {failure}
            <FormField label={t('fnb.shift.outlet')}>
                <Select onChange={(e) => setOutlet(e.target.value)} value={outlet}>{overview.outlets.map((o) => <option key={o.id} value={o.id}>{o.name} ({o.code})</option>)}</Select>
            </FormField>
            <FormField error={bad === 'float' ? t('fnb.shift.badAmount') : action.fieldError('opening_float_minor')} field="opening_float_minor" hint={t('fnb.shift.floatHint')} label={t('fnb.shift.float', { currency })}>
                <Input inputMode="decimal" onChange={(e) => setFloat(e.target.value)} value={float} />
            </FormField>
            <div><Button disabled={outlet === ''} loading={action.busy} onClick={() => void open()} type="button">{t('fnb.shift.open')}</Button></div>
        </section>
    ) : (
        <section aria-labelledby="fnb-shift-h" className="flex flex-col gap-4">
            <div>
                <h2 className="text-lg font-semibold" id="fnb-shift-h">{t('fnb.shift.current', { number: shift.number })}</h2>
                <p className="text-sm text-muted-foreground">{t('fnb.shift.since', { time: format.instant(shift.opened_at), outlet: shift.outlet ?? '' })}</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="shift-kpis">
                <Metric label={t('fnb.shift.openingFloat')} value={money(shift.opening_float_minor)} />
                <Metric label={t('fnb.shift.cashTaken')} value={money(shift.cash_taken_minor ?? 0)} />
                <Metric label={t('fnb.shift.expected')} value={money(shift.expected_now_minor ?? shift.opening_float_minor)} />
                <Metric detail={(shift.open_minor ?? 0) > 0 ? t('fnb.shift.undecidedHint') : undefined} label={t('fnb.shift.undecided')} value={money(shift.open_minor ?? 0)} />
            </div>
            {(shift.by_method ?? []).length === 0 ? <p className="text-sm text-muted-foreground">{t('fnb.shift.noPayments')}</p> : (
                <ul className="flex flex-col gap-1 text-sm" data-testid="shift-methods">
                    {(shift.by_method ?? []).map((m) => <li key={m.method}>{method(m.method)}: {money(m.amount_minor)} ({m.count})</li>)}
                </ul>
            )}
            <div className="flex max-w-xl flex-col gap-3 border border-border bg-surface p-4">
                <h3 className="font-semibold">{t('fnb.shift.closeTitle')}</h3>
                {failure}
                <FormField error={bad === 'counted' ? t('fnb.shift.badAmount') : action.fieldError('counted_cash_minor')} field="counted_cash_minor" label={t('fnb.shift.counted', { currency })}>
                    <Input inputMode="decimal" onChange={(e) => setCounted(e.target.value)} value={counted} />
                </FormField>
                <FormField error={action.fieldError('reason')} field="reason" hint={t('fnb.shift.reasonHint')} label={t('fnb.shift.reason')}>
                    <Input maxLength={200} onChange={(e) => setReason(e.target.value)} value={reason} />
                </FormField>
                <div><Button disabled={counted === ''} loading={action.busy} onClick={() => void close()} type="button">{t('fnb.shift.close')}</Button></div>
            </div>
        </section>
    );

    return (
        <FnbShell description={t('fnb.shift.description')} title={t('fnb.shift.title')} wide>
            {closed !== null ? <Alert title={t('fnb.shift.closedOk', { number: closed.number, variance: money(closed.variance_minor ?? 0) })} tone={closed.variance_minor === 0 ? 'success' : 'warning'} /> : null}
            {overview.may.manage ? (
                <Tabs defaultValue={overview.may.operate ? 'mine' : 'recent'}>
                    <TabsList aria-label={t('fnb.shift.title')}>
                        {overview.may.operate ? <TabsTrigger value="mine">{t('fnb.shift.title')}</TabsTrigger> : null}
                        <TabsTrigger value="recent">{t('fnb.shift.recent')}</TabsTrigger>
                    </TabsList>
                    {overview.may.operate ? <TabsContent className="flex flex-col gap-4" value="mine">{cashierPanel}</TabsContent> : null}
                    <TabsContent className="flex flex-col gap-3" value="recent">
                        <DataGrid caption={t('fnb.shift.recent')} columns={recentColumns} empty={<EmptyState illustration="bell" title={t('fnb.shift.noPayments')} />} getRowId={(s) => s.id} id="fnb.shift.recent" rows={overview.recent} testId="shift-recent" />
                    </TabsContent>
                </Tabs>
            ) : cashierPanel}
        </FnbShell>
    );
}
