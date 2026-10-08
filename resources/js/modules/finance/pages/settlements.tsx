import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import type { SettlementOverview, SettlementRow } from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Form = { method: 'qris' | 'card'; provider: string; from: string; to: string; settledOn: string; gross: string; fee: string; net: string; reference: string; note: string };

/** QRIS and card receipts against what the provider settled to the bank: record each settlement and see at once what the books hold, the fee, and any difference. */
export default function SettlementsPage({ overview }: { overview: SettlementOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const money = (minor: number) => format.money(minor, overview.currency);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const gross = form === null ? null : parseMajorToMinor(form.gross, overview.currency);
    const fee = form === null ? null : parseMajorToMinor(form.fee === '' ? '0' : form.fee, overview.currency);
    const net = form === null ? null : parseMajorToMinor(form.net, overview.currency);
    const expected = form === null ? 0 : overview.pending.filter((p) => p.method === form.method && p.business_date >= form.from && p.business_date <= form.to).reduce((sum, p) => sum + p.net_minor, 0);

    function start() {
        action.clear();
        const method = overview.pending[0]?.method ?? 'qris';
        const days = overview.pending.filter((p) => p.method === method).map((p) => p.business_date);
        setForm({ method, provider: '', from: days[0] ?? overview.today, to: days[days.length - 1] ?? overview.today, settledOn: overview.today, gross: '', fee: '', net: '', reference: '', note: '' });
    }

    async function save() {
        if (form === null || gross === null || net === null || fee === null) return;
        const done = await action.run('/finance/settlements', { body: { method: form.method, provider: form.provider.trim(), covers_from: form.from, covers_to: form.to, settled_on: form.settledOn, gross_minor: gross, fee_minor: fee, net_minor: net, bank_reference: form.reference.trim(), note: form.note.trim() === '' ? null : form.note.trim() }, reload: ['overview'] });
        if (done !== null) setForm(null);
    }

    const methodLabel = (m: string) => t(`fin.set.method.${m}` as MessageKey);
    const columns: DataGridColumn<SettlementRow>[] = [
        { id: 'number', label: t('fin.set.colNumber'), value: (s) => s.number, rowHeader: true, cell: (s) => <span>{s.number}<span className="block text-xs text-muted-foreground">{s.bank_reference}</span></span> },
        { id: 'provider', label: t('fin.set.colProvider'), value: (s) => s.provider, cell: (s) => <span>{s.provider}<span className="block text-xs text-muted-foreground">{methodLabel(s.method)}</span></span> },
        { id: 'covers', label: t('fin.set.colCovers'), value: (s) => s.covers_from, cell: (s) => (s.covers_from === s.covers_to ? format.date(s.covers_from) : `${format.date(s.covers_from)} – ${format.date(s.covers_to)}`) },
        { id: 'settled', label: t('fin.set.colSettled'), value: (s) => s.settled_on, cell: (s) => format.date(s.settled_on) },
        { id: 'system', label: t('fin.set.colSystem'), align: 'right', value: (s) => s.system_minor, cell: (s) => money(s.system_minor) },
        { id: 'gross', label: t('fin.set.colGross'), align: 'right', value: (s) => s.gross_minor, cell: (s) => money(s.gross_minor) },
        { id: 'fee', label: t('fin.set.colFee'), align: 'right', value: (s) => s.fee_minor, cell: (s) => <span>{money(s.fee_minor)}<span className="block text-xs text-muted-foreground">{(s.fee_bp / 100).toFixed(2)}%{s.fee_high ? ` · ${t('fin.set.feeHigh')}` : ''}</span></span> },
        { id: 'net', label: t('fin.set.colNet'), align: 'right', value: (s) => s.net_minor, cell: (s) => money(s.net_minor) },
        {
            id: 'status', label: t('fin.set.colStatus'), value: (s) => (s.matched ? 'matched' : 'exception'),
            cell: (s) => (s.matched ? <StatusBadge label={t('fin.set.matched')} tone="success" /> : <span><StatusBadge label={t('fin.set.exception')} tone="danger" /><span className="block text-xs text-muted-foreground">{t('fin.set.diffs', { gross: money(s.gross_diff_minor), net: money(s.net_diff_minor) })}</span></span>),
        },
    ];

    return (
        <FinanceShell actions={overview.may.record ? <Button onClick={start} type="button">{t('fin.set.add')}</Button> : undefined} description={t('fin.set.description')} title={t('fin.set.title')} wide>
            {action.error !== null && form === null ? failure : null}
            <section aria-labelledby="fin-set-pending-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="fin-set-pending-h">{t('fin.set.pending')}</h2>
                {overview.pending.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.set.nonePending')}</p> : (
                    <ul className="grid gap-1 text-sm sm:grid-cols-2 lg:grid-cols-3" data-testid="settlement-pending">
                        {overview.pending.map((p) => <li className="flex justify-between border border-border bg-surface px-3 py-1" key={`${p.business_date}-${p.method}`}><span>{format.date(p.business_date)} · {methodLabel(p.method)}</span><span className="tabular-nums">{money(p.net_minor)}</span></li>)}
                    </ul>
                )}
            </section>
            <DataGrid caption={t('fin.set.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('fin.set.none')} />} getRowId={(s) => s.id} id="finance.settlements" rows={overview.settlements} testId="settlements" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form === null || gross === null || net === null || fee === null || form.provider.trim() === '' || form.reference.trim() === ''} loading={action.busy} onClick={() => void save()} type="button">{t('fin.set.save')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.set.add')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {failure}
                        <p className="text-sm text-muted-foreground">{t('fin.set.formHint')}</p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={action.fieldError('method')} field="method" label={t('fin.set.method')}>
                                <Select onChange={(e) => setForm({ ...form, method: e.target.value as Form['method'] })} value={form.method}>{overview.methods.map((m) => <option key={m} value={m}>{methodLabel(m)}</option>)}</Select>
                            </FormField>
                            <FormField error={action.fieldError('provider')} field="provider" label={t('fin.set.colProvider')}><Input maxLength={80} onChange={(e) => setForm({ ...form, provider: e.target.value })} value={form.provider} /></FormField>
                            <FormField error={action.fieldError('covers_from')} field="covers_from" label={t('fin.set.from')}><DatePicker onChange={(e) => setForm({ ...form, from: e.target.value })} value={form.from} /></FormField>
                            <FormField error={action.fieldError('covers_to')} field="covers_to" label={t('fin.set.to')}><DatePicker onChange={(e) => setForm({ ...form, to: e.target.value })} value={form.to} /></FormField>
                            <FormField error={action.fieldError('settled_on')} field="settled_on" label={t('fin.set.colSettled')}><DatePicker onChange={(e) => setForm({ ...form, settledOn: e.target.value })} value={form.settledOn} /></FormField>
                            <FormField error={action.fieldError('bank_reference')} field="bank_reference" hint={t('fin.set.referenceHint')} label={t('fin.set.reference')}><Input maxLength={60} onChange={(e) => setForm({ ...form, reference: e.target.value })} value={form.reference} /></FormField>
                            <FormField error={action.fieldError('gross_minor')} field="gross_minor" hint={t('fin.set.booksHold', { amount: money(expected) })} label={t('fin.set.colGross')}><MoneyInput onChange={(e) => setForm({ ...form, gross: e.target.value })} value={form.gross} /></FormField>
                            <FormField error={action.fieldError('fee_minor')} field="fee_minor" label={t('fin.set.colFee')}><MoneyInput onChange={(e) => setForm({ ...form, fee: e.target.value })} value={form.fee} /></FormField>
                            <FormField error={action.fieldError('net_minor')} field="net_minor" hint={gross !== null && fee !== null ? t('fin.set.netHint', { amount: money(gross - fee) }) : undefined} label={t('fin.set.colNet')}><MoneyInput onChange={(e) => setForm({ ...form, net: e.target.value })} value={form.net} /></FormField>
                            <FormField error={action.fieldError('note')} field="note" label={t('fin.set.note')}><Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} /></FormField>
                        </div>
                        {gross !== null && gross !== expected ? <Alert title={t('fin.set.willRaise', { diff: money(gross - expected) })} tone="warning" /> : null}
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
