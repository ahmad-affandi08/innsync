import { useState, type FormEvent } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Place = 'store' | 'floor' | 'laundry' | 'discard';
type Item = {
    id: string; code: string; name: string; kind: string; unit: string; is_active: boolean; lock_version: number;
    locations: Record<Place, number>; in_transit: number; lost: number; damaged: number;
};
type Transfer = {
    id: string; number: string; item_code: string; item_name: string; from: string; to: string; quantity_sent: number; status: string; note: string | null; sent_at: string;
    quantity_received: number | null; variance_kind: string | null; variance_note: string | null; lock_version: number;
    sent_by_name?: string | null; may_receive?: boolean; may_cancel?: boolean;
};
type Linen = {
    items: Item[]; pending: Transfer[]; recent: Transfer[]; locations: Place[]; kinds: string[]; rooms: { id: string; number: string }[]; business_date: string;
    may: { manage: boolean; laundry: boolean };
};
type UsageRow = { room: string; item: string; item_name: string; usage_date: string; quantity: number };

const RELOAD = ['linen'];

/** Linen and amenities: where everything is, what is on its way, and what was used in the rooms. Moving linen is two people: one sends a count, another counts what arrived. */
export default function LinenPage({ linen }: { linen: Linen }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const [send, setSend] = useState({ itemId: '', from: 'store', to: 'floor', quantity: '', note: '' });
    const [item, setItem] = useState({ code: '', name: '', kind: 'linen', unit: 'pcs' });
    const [use, setUse] = useState({ roomId: '', itemId: '', quantity: '', note: '' });
    const [count, setCount] = useState<Record<string, { received: string; kind: string; note: string }>>({});
    const [usage, setUsage] = useState<UsageRow[] | null>(null);
    const [recorded, setRecorded] = useState(false);
    const active = linen.items.filter((i) => i.is_active);
    const canSend = linen.may.manage || linen.may.laundry;
    const sources = linen.may.manage ? ['external', 'store', 'floor', 'laundry'] : ['laundry'];
    const place = (code: string) => t(`hk.linen.place.${code}` as 'hk.linen.place.store');

    async function sendTransfer(event: FormEvent) {
        event.preventDefault();
        const done = await action.run('/housekeeping/linen/transfers', {
            idempotencyKey: intent, body: { item_id: send.itemId, from: send.from, to: send.to, quantity: Number(send.quantity), note: send.note.trim() || null }, reload: RELOAD,
        });
        if (done !== null) { setSend({ ...send, quantity: '', note: '' }); setIntent(newIdempotencyKey()); }
    }

    async function receive(transfer: Transfer) {
        const c = count[transfer.id] ?? { received: String(transfer.quantity_sent), kind: 'loss', note: '' };
        const received = Number(c.received);
        await action.run(`/housekeeping/linen/transfers/${transfer.id}/receive`, {
            body: { quantity_received: received, variance_kind: received < transfer.quantity_sent ? c.kind : null, variance_note: received < transfer.quantity_sent ? c.note.trim() || null : null, lock_version: transfer.lock_version }, reload: RELOAD,
        });
    }

    async function cancel(transfer: Transfer) {
        await action.run(`/housekeeping/linen/transfers/${transfer.id}/cancel`, { body: { lock_version: transfer.lock_version }, reload: RELOAD });
    }

    async function addItem(event: FormEvent) {
        event.preventDefault();
        const done = await action.run('/housekeeping/linen/items', { body: { code: item.code.trim(), name: item.name.trim(), kind: item.kind, unit: item.unit.trim() }, reload: RELOAD });
        if (done !== null) setItem({ ...item, code: '', name: '' });
    }

    async function toggle(row: Item) {
        await action.run(`/housekeeping/linen/items/${row.id}/active`, { body: { active: !row.is_active, lock_version: row.lock_version }, reload: RELOAD });
    }

    async function recordUsage(event: FormEvent) {
        event.preventDefault();
        setRecorded(false);
        const done = await action.run('/housekeeping/linen/usage', { body: { room_id: use.roomId, item_id: use.itemId, quantity: Number(use.quantity), note: use.note.trim() || null } });
        if (done !== null) { setRecorded(true); setUse({ ...use, quantity: '', note: '' }); await loadUsage(); }
    }

    async function loadUsage() {
        const result = await action.run<{ usage: { rows: UsageRow[] } }>('/housekeeping/linen/usage', { method: 'GET' });
        if (result !== null) setUsage(result.usage.rows);
    }

    const positionColumns: DataGridColumn<Item>[] = [
        {
            id: 'item', label: t('hk.linen.item'), value: (row) => row.name, searchText: (row) => `${row.name} ${row.code} ${row.unit}`, rowHeader: true,
            cell: (row) => <><span className="font-medium">{row.name}</span> <span className="text-xs font-normal text-muted-foreground">{row.code} · {row.unit}</span>{row.is_active ? null : <> <StatusBadge label={t('hk.linen.inactive')} tone="neutral" /></>}</>,
        },
        { id: 'kind', label: t('hk.linen.kind'), value: (row) => row.kind, filter: 'select', filterLabel: (v) => t(`hk.linen.kind.${v}` as 'hk.linen.kind.linen'), cell: (row) => t(`hk.linen.kind.${row.kind}` as 'hk.linen.kind.linen'), hidden: true },
        ...linen.locations.map((p): DataGridColumn<Item> => ({ id: `place-${p}`, label: place(p), align: 'right', value: (row) => row.locations[p], cell: (row) => format.number(row.locations[p]) })),
        { id: 'in_transit', label: t('hk.linen.inTransit'), align: 'right', value: (row) => row.in_transit, cell: (row) => format.number(row.in_transit) },
        { id: 'lost', label: t('hk.linen.lost'), align: 'right', value: (row) => row.lost, cell: (row) => format.number(row.lost) },
        { id: 'damaged', label: t('hk.linen.damaged'), align: 'right', value: (row) => row.damaged, cell: (row) => format.number(row.damaged) },
        ...(linen.may.manage ? [{
            id: 'actions', label: t('hk.board.actions'), align: 'right' as const,
            cell: (row: Item) => <Button disabled={action.busy} onClick={() => void toggle(row)} size="sm" type="button" variant="outline">{row.is_active ? t('hk.linen.deactivate') : t('hk.linen.activate')}</Button>,
        }] : []),
    ];
    const usageColumns: DataGridColumn<UsageRow>[] = [
        { id: 'date', label: t('hk.linen.date'), value: (u) => u.usage_date },
        { id: 'room', label: t('hk.linen.room'), value: (u) => u.room, filter: 'select', rowHeader: true },
        { id: 'item', label: t('hk.linen.item'), value: (u) => u.item_name, searchText: (u) => `${u.item_name} ${u.item}`, filter: 'select' },
        { id: 'quantity', label: t('hk.linen.quantity'), align: 'right', value: (u) => u.quantity, cell: (u) => format.number(u.quantity) },
    ];

    return (
        <HousekeepingShell description={t('hk.linen.description')} title={t('hk.linen.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <section aria-labelledby="linen-position" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="linen-position">{t('hk.linen.position')}</h2>
                <DataGrid
                    caption={t('hk.linen.position')}
                    columns={positionColumns}
                    empty={<EmptyState title={t('hk.linen.noItems')} />}
                    getRowId={(row) => row.id}
                    id="hk.linen.stock"
                    rows={linen.items}
                    testId="linen-position"
                />
            </section>

            <section aria-labelledby="linen-pending" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="linen-pending">{t('hk.linen.pending')}</h2>
                {linen.pending.length === 0 ? <p className="text-sm text-muted-foreground">{t('hk.linen.noPending')}</p> : (
                    <ul className="flex flex-col gap-3" data-testid="linen-pending">
                        {linen.pending.map((tr) => {
                            const c = count[tr.id] ?? { received: String(tr.quantity_sent), kind: 'loss', note: '' };
                            const short = Number(c.received) < tr.quantity_sent;
                            const set = (patch: Partial<typeof c>) => setCount({ ...count, [tr.id]: { ...c, ...patch } });

                            return (
                                <li className="flex flex-col gap-2 border border-border p-3" key={tr.id}>
                                    <p className="text-sm"><span className="font-medium">{tr.number}</span> · {tr.item_name}: {format.number(tr.quantity_sent)} · {place(tr.from)} → {place(tr.to)} · {t('hk.linen.sentBy', { name: tr.sent_by_name ?? '—', time: format.instant(tr.sent_at) })}</p>
                                    {tr.note !== null ? <p className="text-xs text-muted-foreground">{tr.note}</p> : null}
                                    {tr.may_receive === true && (
                                        <div className="grid gap-2 sm:grid-cols-4">
                                            <FormField label={t('hk.linen.counted')}><Input inputMode="numeric" max={tr.quantity_sent} min={0} onChange={(e) => set({ received: e.target.value })} type="number" value={c.received} /></FormField>
                                            {short ? <FormField error={action.fieldError('variance_kind')} label={t('hk.linen.variance')}><Select onChange={(e) => set({ kind: e.target.value })} value={c.kind}><option value="loss">{t('hk.linen.variance.loss')}</option><option value="damage">{t('hk.linen.variance.damage')}</option></Select></FormField> : null}
                                            {short ? <FormField error={action.fieldError('variance_note')} label={t('hk.linen.varianceNote')}><Input maxLength={200} onChange={(e) => set({ note: e.target.value })} value={c.note} /></FormField> : null}
                                            <div className="flex items-end"><Button disabled={action.busy} onClick={() => void receive(tr)} type="button">{t('hk.linen.receive')}</Button></div>
                                        </div>
                                    )}
                                    {tr.may_cancel === true ? <div><Button disabled={action.busy} onClick={() => void cancel(tr)} size="sm" type="button" variant="outline">{t('hk.linen.cancel')}</Button></div> : null}
                                    {tr.may_receive !== true && tr.may_cancel !== true ? <p className="text-xs text-muted-foreground">{t('hk.linen.waiting')}</p> : null}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            {canSend && (
                <section aria-labelledby="linen-send" className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold" id="linen-send">{t('hk.linen.send')}</h2>
                    <form className="grid gap-3 sm:grid-cols-5" onSubmit={(e) => void sendTransfer(e)}>
                        <FormField error={action.fieldError('item_id')} label={t('hk.linen.item')}><Select onChange={(e) => setSend({ ...send, itemId: e.target.value })} required value={send.itemId}><option value="">{t('hk.linen.choose')}</option>{active.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('from')} label={t('hk.linen.from')}><Select onChange={(e) => setSend({ ...send, from: e.target.value, to: e.target.value === 'external' ? 'store' : send.to })} value={send.from}>{sources.map((p) => <option key={p} value={p}>{p === 'external' ? t('hk.linen.place.external') : place(p)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('to')} label={t('hk.linen.to')}><Select onChange={(e) => setSend({ ...send, to: e.target.value })} value={send.to}>{linen.locations.filter((p) => send.from !== 'external' || p === 'store').map((p) => <option key={p} value={p}>{place(p)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('quantity')} label={t('hk.linen.quantity')}><Input inputMode="numeric" min={1} onChange={(e) => setSend({ ...send, quantity: e.target.value })} required type="number" value={send.quantity} /></FormField>
                        <FormField error={action.fieldError('note')} label={t('hk.linen.note')}><Input maxLength={200} onChange={(e) => setSend({ ...send, note: e.target.value })} value={send.note} /></FormField>
                        <div className="sm:col-span-5"><Button loading={action.busy} type="submit">{t('hk.linen.sendAction')}</Button></div>
                    </form>
                </section>
            )}

            {linen.may.manage && (
                <section aria-labelledby="linen-new" className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold" id="linen-new">{t('hk.linen.newItem')}</h2>
                    <form className="grid gap-3 sm:grid-cols-5" onSubmit={(e) => void addItem(e)}>
                        <FormField error={action.fieldError('code')} hint={t('hk.linen.codeHint')} label={t('hk.linen.code')}><Input maxLength={20} onChange={(e) => setItem({ ...item, code: e.target.value.toUpperCase() })} required value={item.code} /></FormField>
                        <FormField error={action.fieldError('name')} label={t('hk.linen.name')}><Input maxLength={80} onChange={(e) => setItem({ ...item, name: e.target.value })} required value={item.name} /></FormField>
                        <FormField error={action.fieldError('kind')} label={t('hk.linen.kind')}><Select onChange={(e) => setItem({ ...item, kind: e.target.value })} value={item.kind}>{linen.kinds.map((k) => <option key={k} value={k}>{t(`hk.linen.kind.${k}` as 'hk.linen.kind.linen')}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('unit')} label={t('hk.linen.unit')}><Input maxLength={12} onChange={(e) => setItem({ ...item, unit: e.target.value })} required value={item.unit} /></FormField>
                        <div className="flex items-end"><Button loading={action.busy} type="submit">{t('hk.linen.addItem')}</Button></div>
                    </form>
                </section>
            )}

            <section aria-labelledby="linen-usage" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="linen-usage">{t('hk.linen.usage')}</h2>
                <form className="grid gap-3 sm:grid-cols-5" onSubmit={(e) => void recordUsage(e)}>
                    <FormField error={action.fieldError('room_id')} label={t('hk.linen.room')}><Select onChange={(e) => setUse({ ...use, roomId: e.target.value })} required value={use.roomId}><option value="">{t('hk.linen.choose')}</option>{linen.rooms.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}</Select></FormField>
                    <FormField error={action.fieldError('item_id')} label={t('hk.linen.item')}><Select onChange={(e) => setUse({ ...use, itemId: e.target.value })} required value={use.itemId}><option value="">{t('hk.linen.choose')}</option>{active.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}</Select></FormField>
                    <FormField error={action.fieldError('quantity')} label={t('hk.linen.quantity')}><Input inputMode="numeric" min={1} onChange={(e) => setUse({ ...use, quantity: e.target.value })} required type="number" value={use.quantity} /></FormField>
                    <FormField error={action.fieldError('note')} label={t('hk.linen.note')}><Input maxLength={200} onChange={(e) => setUse({ ...use, note: e.target.value })} value={use.note} /></FormField>
                    <div className="flex flex-wrap items-end gap-2"><Button loading={action.busy} type="submit">{t('hk.linen.record')}</Button><Button disabled={action.busy} onClick={() => void loadUsage()} type="button" variant="outline">{t('hk.linen.showUsage')}</Button></div>
                </form>
                {recorded ? <Alert title={t('hk.linen.recorded')} tone="success" /> : null}
                {usage !== null && (
                    <DataGrid
                        caption={t('hk.linen.usage')}
                        columns={usageColumns}
                        empty={<p className="text-sm text-muted-foreground">{t('hk.linen.noUsage')}</p>}
                        getRowId={(u) => `${u.usage_date}-${u.room}-${u.item}`}
                        id="hk.linen.usage"
                        rows={usage}
                        testId="linen-usage"
                    />
                )}
            </section>

            <section aria-labelledby="linen-recent" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="linen-recent">{t('hk.linen.recent')}</h2>
                {linen.recent.length === 0 ? <p className="text-sm text-muted-foreground">{t('hk.linen.noRecent')}</p> : (
                    <ul className="flex flex-col gap-1 text-sm" data-testid="linen-recent">
                        {linen.recent.map((tr) => (
                            <li className="flex flex-wrap items-center gap-2" key={tr.id}>
                                <span className="font-medium">{tr.number}</span> {tr.item_name}: {format.number(tr.quantity_received ?? tr.quantity_sent)}/{format.number(tr.quantity_sent)} · {place(tr.from)} → {place(tr.to)}
                                <StatusBadge label={t(`hk.linen.status.${tr.status}` as 'hk.linen.status.received')} tone={tr.status === 'cancelled' ? 'neutral' : 'success'} />
                                {tr.variance_kind !== null ? <StatusBadge label={`${t(`hk.linen.variance.${tr.variance_kind}` as 'hk.linen.variance.loss')}: ${tr.variance_note ?? ''}`} tone="danger" /> : null}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </HousekeepingShell>
    );
}
