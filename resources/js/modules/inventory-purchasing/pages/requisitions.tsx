import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Line = { id: string; item_code: string; item_name: string; unit: string; quantity_milli: number };
type Requisition = {
    id: string; number: string; status: 'requested' | 'fulfilled' | 'rejected' | 'cancelled'; note: string | null; decision_note: string | null; transfer_id: string | null; lock_version: number; requesting: string; supplying: string;
    requested_by: string | null; decided_by: string | null; created_at: string | null; decided_at: string | null; lines: Line[]; may: { fulfil: boolean; cancel: boolean };
};
type Overview = {
    requisitions: Requisition[]; locations: { id: string; code: string; name: string; kind: string }[]; items: { id: string; code: string; name: string; base_unit: string }[]; may: { request: boolean; fulfil: boolean };
};

const TONE: Record<Requisition['status'], StatusTone> = { requested: 'pending', fulfilled: 'success', rejected: 'danger', cancelled: 'neutral' };

/** What a department asks of the main store: the request, the answer (a transfer or a refusal) and who gave it (FR-FBS-030). */
export default function RequisitionsPage({ overview, status }: { overview: Overview; status: string }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ requesting: string; supplying: string; note: string; lines: { item_id: string; quantity: string }[] } | null>(null);
    const [openId, setOpenId] = useState<string | null>(null);
    const [sent, setSent] = useState<Record<string, string>>({});
    const [refusal, setRefusal] = useState<string | null>(null);
    const current = overview.requisitions.find((r) => r.id === openId) ?? null;
    const label = (s: string) => t(`inv.rq.status.${s}` as MessageKey);
    const qty = (n: number) => formatMilli(n, locale);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const main = overview.locations.find((l) => l.kind === 'main');

    function openNew() {
        action.clear();
        setForm({ requesting: overview.locations.find((l) => l.kind !== 'main')?.id ?? '', supplying: main?.id ?? '', note: '', lines: [{ item_id: overview.items[0]?.id ?? '', quantity: '' }] });
    }

    async function send() {
        if (form === null) return;
        const lines = form.lines.map((l) => ({ item_id: l.item_id, unit: overview.items.find((i) => i.id === l.item_id)?.base_unit ?? '', quantity: l.quantity }));
        const done = await action.run('/inventory/requisitions', { body: { requesting_location_id: form.requesting, supplying_location_id: form.supplying, note: form.note || null, lines }, reload: ['overview'] });
        if (done !== null) setForm(null);
    }

    async function fulfil() {
        if (current === null) return;
        const done = await action.run(`/inventory/requisitions/${current.id}/fulfil`, { body: { lock_version: current.lock_version, quantities: sent }, idempotencyKey: newIdempotencyKey(), reload: ['overview'] });
        if (done !== null) { setOpenId(null); setSent({}); }
    }

    async function reject() {
        if (current === null || refusal === null) return;
        const done = await action.run(`/inventory/requisitions/${current.id}/reject`, { body: { note: refusal, lock_version: current.lock_version }, reload: ['overview'] });
        if (done !== null) { setOpenId(null); setRefusal(null); }
    }

    async function cancel() {
        if (current === null) return;
        const done = await action.run(`/inventory/requisitions/${current.id}/cancel`, { body: { lock_version: current.lock_version }, reload: ['overview'] });
        if (done !== null) setOpenId(null);
    }

    const columns: DataGridColumn<Requisition>[] = [
        { id: 'number', label: t('inv.col.number'), value: (r) => r.number, rowHeader: true },
        { id: 'requesting', label: t('inv.rq.requesting'), value: (r) => r.requesting, filter: 'select' },
        { id: 'supplying', label: t('inv.rq.supplying'), value: (r) => r.supplying, filter: 'select' },
        { id: 'lines', label: t('inv.col.lines'), value: (r) => r.lines.map((l) => l.item_code).join(' '), cell: (r) => r.lines.map((l) => `${l.item_code} ${qty(l.quantity_milli)} ${l.unit}`).join(', ') },
        { id: 'by', label: t('inv.rq.by'), value: (r) => r.requested_by ?? '', hidden: true },
        { id: 'at', label: t('inv.col.date'), value: (r) => r.created_at ?? '', cell: (r) => (r.created_at === null ? '—' : format.instant(r.created_at)) },
        { id: 'state', label: t('inv.col.status'), value: (r) => r.status, filter: 'select', filterLabel: label, cell: (r) => <StatusBadge label={label(r.status)} tone={TONE[r.status]} /> },
        { id: 'open', label: t('inv.col.actions'), value: () => '', sortable: false, cell: (r) => <Button onClick={() => { action.clear(); setSent({}); setRefusal(null); setOpenId(r.id); }} size="sm" type="button" variant="outline">{t('inv.trf.view')}</Button> },
    ];

    return (
        <InventoryShell actions={overview.may.request ? <Button onClick={openNew} type="button">{t('inv.rq.new')}</Button> : undefined} description={t('inv.rq.description')} title={t('inv.rq.title')} wide>
            <div className="max-w-xs">
                <Select aria-label={t('inv.col.status')} onChange={(e) => router.get('/inventory/requisitions', e.target.value ? { status: e.target.value } : {}, { preserveScroll: true })} searchable={false} value={status}>
                    <option value="">{t('inv.rq.all')}</option>
                    {['requested', 'fulfilled', 'rejected', 'cancelled'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
                </Select>
            </div>
            <DataGrid caption={t('inv.rq.title')} columns={columns} empty={<EmptyState title={t('inv.rq.empty')} />} getRowId={(r) => r.id} id="inv.requisitions" rows={overview.requisitions} testId="requisitions" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void send()} type="button">{t('inv.rq.send')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.rq.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('inv.rq.hint')}</p>
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('requesting_location_id')} field="requesting_location_id" label={t('inv.rq.requesting')}><Select onChange={(e) => setForm({ ...form, requesting: e.target.value })} value={form.requesting}>{overview.locations.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('supplying_location_id')} field="supplying_location_id" label={t('inv.rq.supplying')}><Select onChange={(e) => setForm({ ...form, supplying: e.target.value })} value={form.supplying}>{overview.locations.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}</Select></FormField>
                        {form.lines.map((l, i) => (
                            <div className="grid gap-3 sm:col-span-2 sm:grid-cols-[1fr_8rem_auto]" key={i}>
                                <FormField error={i === 0 ? action.fieldError('lines') : undefined} field="lines" label={t('inv.col.item')}><Select onChange={(e) => setForm({ ...form, lines: form.lines.map((x, j) => (j === i ? { ...x, item_id: e.target.value } : x)) })} value={l.item_id}>{overview.items.map((it) => <option key={it.id} value={it.id}>{it.code} · {it.name} ({it.base_unit})</option>)}</Select></FormField>
                                <FormField label={t('inv.col.quantity')}><Input inputMode="decimal" onChange={(e) => setForm({ ...form, lines: form.lines.map((x, j) => (j === i ? { ...x, quantity: e.target.value } : x)) })} value={l.quantity} /></FormField>
                                <div className="self-end">{form.lines.length > 1 ? <Button onClick={() => setForm({ ...form, lines: form.lines.filter((_, j) => j !== i) })} size="sm" type="button" variant="outline">{t('inv.rq.removeLine')}</Button> : null}</div>
                            </div>
                        ))}
                        <div className="sm:col-span-2"><Button onClick={() => setForm({ ...form, lines: [...form.lines, { item_id: overview.items[0]?.id ?? '', quantity: '' }] })} size="sm" type="button" variant="outline">{t('inv.rq.addLine')}</Button></div>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('note')} field="note" label={t('inv.opening.note')}><Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={current === null ? <></> : <>
                    <Button disabled={action.busy} onClick={() => setOpenId(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    {current.may.cancel ? <Button disabled={action.busy} onClick={() => void cancel()} type="button" variant="outline">{t('inv.rq.withdraw')}</Button> : null}
                    {current.may.fulfil && refusal === null ? <Button disabled={action.busy} onClick={() => setRefusal('')} type="button" variant="outline">{t('inv.rq.refuse')}</Button> : null}
                    {current.may.fulfil && refusal !== null ? <Button disabled={refusal.trim() === ''} loading={action.busy} onClick={() => void reject()} type="button">{t('inv.rq.refuseDo')}</Button> : null}
                    {current.may.fulfil && refusal === null ? <Button loading={action.busy} onClick={() => void fulfil()} type="button">{t('inv.rq.fulfil')}</Button> : null}
                </>}
                onClose={() => setOpenId(null)}
                open={current !== null}
                title={current === null ? '' : `${current.number} · ${current.requesting}`}
            >
                {current !== null && (
                    <div className="flex flex-col gap-3">
                        {failure}
                        <p className="text-sm text-muted-foreground">{t('inv.rq.line', { from: current.requesting, to: current.supplying, by: current.requested_by ?? '—' })}</p>
                        <ul className="flex flex-col gap-2 text-sm">
                            {current.lines.map((l) => (
                                <li className="flex flex-wrap items-center justify-between gap-2" key={l.id}>
                                    <span>{l.item_code} · {l.item_name} · {qty(l.quantity_milli)} {l.unit}</span>
                                    {current.may.fulfil ? <Input aria-label={t('inv.rq.sendQty', { item: l.item_code })} className="w-28" inputMode="decimal" onChange={(e) => setSent({ ...sent, [l.id]: e.target.value })} placeholder={t('inv.rq.sendAll')} value={sent[l.id] ?? ''} /> : null}
                                </li>
                            ))}
                        </ul>
                        {current.note !== null ? <p className="text-sm">{current.note}</p> : null}
                        {current.decision_note !== null ? <p className="text-sm text-danger">{current.decision_note}</p> : null}
                        {current.may.fulfil && refusal !== null ? <FormField error={action.fieldError('note')} field="note" label={t('inv.rq.refuseWhy')}><Input maxLength={200} onChange={(e) => setRefusal(e.target.value)} value={refusal} /></FormField> : null}
                        {current.transfer_id !== null ? <Button asChild size="sm" variant="outline"><a href="/inventory/transfers">{t('inv.rq.transfer')}</a></Button> : null}
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
