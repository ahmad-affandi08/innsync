import { useState, type FormEvent } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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

    return (
        <HousekeepingShell description={t('hk.linen.description')} title={t('hk.linen.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <section aria-labelledby="linen-position" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="linen-position">{t('hk.linen.position')}</h2>
                {linen.items.length === 0 ? <EmptyState title={t('hk.linen.noItems')} /> : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm" data-testid="linen-position">
                            <thead><tr className="border-b border-border text-left">
                                <th className="py-2 pr-3">{t('hk.linen.item')}</th>
                                {linen.locations.map((p) => <th className="px-2 py-2 text-right" key={p}>{place(p)}</th>)}
                                <th className="px-2 py-2 text-right">{t('hk.linen.inTransit')}</th>
                                <th className="px-2 py-2 text-right">{t('hk.linen.lost')}</th>
                                <th className="px-2 py-2 text-right">{t('hk.linen.damaged')}</th>
                                {linen.may.manage ? <th className="py-2 pl-2" /> : null}
                            </tr></thead>
                            <tbody>
                                {linen.items.map((row) => (
                                    <tr className="border-b border-border" key={row.id}>
                                        <td className="py-2 pr-3"><span className="font-medium">{row.name}</span> <span className="text-xs text-muted-foreground">{row.code} · {row.unit}</span>{row.is_active ? null : <> <StatusBadge label={t('hk.linen.inactive')} tone="neutral" /></>}</td>
                                        {linen.locations.map((p) => <td className="px-2 py-2 text-right tabular-nums" key={p}>{format.number(row.locations[p])}</td>)}
                                        <td className="px-2 py-2 text-right tabular-nums">{format.number(row.in_transit)}</td>
                                        <td className="px-2 py-2 text-right tabular-nums">{format.number(row.lost)}</td>
                                        <td className="px-2 py-2 text-right tabular-nums">{format.number(row.damaged)}</td>
                                        {linen.may.manage ? <td className="py-2 pl-2 text-right"><Button disabled={action.busy} onClick={() => void toggle(row)} size="sm" type="button" variant="outline">{row.is_active ? t('hk.linen.deactivate') : t('hk.linen.activate')}</Button></td> : null}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
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
                {usage !== null && (usage.length === 0 ? <p className="text-sm text-muted-foreground">{t('hk.linen.noUsage')}</p> : (
                    <table className="w-full text-sm" data-testid="linen-usage">
                        <thead><tr className="border-b border-border text-left"><th className="py-2">{t('hk.linen.date')}</th><th>{t('hk.linen.room')}</th><th>{t('hk.linen.item')}</th><th className="text-right">{t('hk.linen.quantity')}</th></tr></thead>
                        <tbody>{usage.map((u) => <tr className="border-b border-border" key={`${u.usage_date}-${u.room}-${u.item}`}><td className="py-2">{u.usage_date}</td><td>{u.room}</td><td>{u.item_name}</td><td className="text-right tabular-nums">{format.number(u.quantity)}</td></tr>)}</tbody>
                    </table>
                ))}
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
