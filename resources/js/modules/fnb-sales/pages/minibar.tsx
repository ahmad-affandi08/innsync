import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { minorToMajorText, parseMajorToMinor } from '@/shared/money/money';

type Item = { id: string; code: string; name: string; price_minor: number; par_qty: number; active: boolean; lock_version: number };
type Scanned = { room: { id: string; number: string }; guest_name: string | null; in_house: boolean; items: (Item & { held: number })[] };
type Check = {
    id: string; number: string; room: { id: string; number: string }; guest_name: string | null; checked_by_name: string; checked_at: string; business_date: string; consumed_minor: number; charged_total_minor: number | null; posted: boolean;
    lines: { code: string; name: string; unit_price_minor: number; consumed: number; refilled: number; stock_after: number }[];
};
type Refill = { rooms: { room: { id: string; number: string }; items: { item_id: string; code: string; name: string; quantity: number }[] }[]; totals: { item_id: string; code: string; name: string; quantity: number }[] };
type Overview = { currency: string; may: { operate: boolean; manage: boolean }; items: Item[]; today: string };

/** The mini bars of the rooms: scan a room, enter what was consumed and put back, see what to refill, and the history of every check. */
export default function MinibarPage({ history, overview, refill, scan_error: scanError, scanned }: { history: { checks: Check[] } | null; overview: Overview; refill: Refill | null; scan_error: string | null; scanned: Scanned | null }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [code, setCode] = useState(scanned?.room.number ?? '');
    const [qty, setQty] = useState<Record<string, { consumed: string; refilled: string }>>({});
    const [done, setDone] = useState<Check | null>(null);
    const [item, setItem] = useState<{ id: string; code: string; name: string; price: string; par: string; lock: number } | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const money = (minor: number) => format.money(minor, overview.currency);
    const reload = ['overview', 'refill', 'history', 'scanned'];
    const n = (v: string | undefined) => (v === undefined || v === '' ? 0 : Number(v));

    function find() {
        setDone(null);
        setQty({});
        router.get('/fnb/minibar', { code: code.trim() }, { preserveScroll: true });
    }

    async function save() {
        if (scanned === null) return;
        const lines = scanned.items.map((i) => ({ item_id: i.id, consumed: n(qty[i.id]?.consumed), refilled: n(qty[i.id]?.refilled) })).filter((l) => l.consumed > 0 || l.refilled > 0);
        const result = await action.run<Check>('/fnb/minibar/checks', { idempotencyKey: newIdempotencyKey(), body: { room_code: scanned.room.number, lines }, reload });

        if (result !== null) {
            setDone(result as unknown as Check);
            setQty({});
        }
    }

    async function saveItem() {
        if (item === null) return;
        const price = parseMajorToMinor(item.price, overview.currency) ?? 0;
        const result = item.id === ''
            ? await action.run('/fnb/minibar/items', { body: { code: item.code.trim(), name: item.name.trim(), price_minor: price, par_qty: Number(item.par) }, reload })
            : await action.run(`/fnb/minibar/items/${item.id}`, { body: { name: item.name.trim(), price_minor: price, par_qty: Number(item.par), lock_version: item.lock }, reload });

        if (result !== null) setItem(null);
    }

    const historyColumns: DataGridColumn<Check>[] = [
        { id: 'at', label: t('fnb.mini.when'), value: (c) => c.checked_at, rowHeader: true, cell: (c) => <span>{format.instant(c.checked_at)}<span className="block text-xs text-muted-foreground">{c.number}</span></span> },
        { id: 'room', label: t('fnb.mini.room'), value: (c) => c.room.number, cell: (c) => <span>{c.room.number}<span className="block text-xs text-muted-foreground">{c.guest_name ?? t('fnb.mini.nobody')}</span></span> },
        { id: 'by', label: t('fnb.mini.by'), value: (c) => c.checked_by_name },
        { id: 'lines', label: t('fnb.mini.lines'), value: (c) => c.lines.length, sortable: false, cell: (c) => c.lines.map((l) => `${l.name}: −${l.consumed} +${l.refilled}`).join(' · ') },
        { id: 'charged', label: t('fnb.mini.charged'), align: 'right', value: (c) => c.charged_total_minor ?? 0, cell: (c) => (c.posted ? money(c.charged_total_minor ?? 0) : '—') },
    ];
    const itemColumns: DataGridColumn<Item>[] = [
        { id: 'code', label: t('hr.shift.code'), value: (i) => i.code, rowHeader: true },
        { id: 'name', label: t('hr.shift.name'), value: (i) => i.name },
        { id: 'price', label: t('fnb.mini.price'), align: 'right', value: (i) => i.price_minor, cell: (i) => money(i.price_minor) },
        { id: 'par', label: t('fnb.mini.par'), align: 'right', value: (i) => i.par_qty },
        { id: 'status', label: t('hr.col.status'), value: (i) => (i.active ? 'active' : 'retired'), cell: (i) => <StatusBadge label={i.active ? t('hr.shift.active') : t('hr.shift.retired')} tone={i.active ? 'success' : 'neutral'} /> },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (i) => (
                <span className="flex gap-2">
                    <Button onClick={() => { action.clear(); setItem({ id: i.id, code: i.code, name: i.name, price: minorToMajorText(i.price_minor, overview.currency), par: String(i.par_qty), lock: i.lock_version }); }} size="sm" type="button" variant="outline">{t('hr.edit')}</Button>
                    <Button disabled={action.busy} onClick={() => void action.run(`/fnb/minibar/items/${i.id}/active`, { body: { active: !i.active, lock_version: i.lock_version }, reload })} size="sm" type="button" variant="outline">{i.active ? t('hr.shift.retire') : t('hr.shift.resume')}</Button>
                </span>
            ),
        },
    ];

    return (
        <FnbShell description={t('fnb.mini.description')} title={t('fnb.mini.title')} wide>
            {action.error !== null && item === null ? failure : null}
            <Tabs defaultValue={overview.may.operate ? 'check' : 'items'}>
                <TabsList aria-label={t('fnb.mini.title')}>
                    {overview.may.operate ? <TabsTrigger value="check">{t('fnb.mini.checkTab')}</TabsTrigger> : null}
                    {overview.may.operate ? <TabsTrigger value="refill">{t('fnb.mini.refillTab')}</TabsTrigger> : null}
                    {overview.may.operate ? <TabsTrigger value="history">{t('fnb.mini.historyTab')}</TabsTrigger> : null}
                    {overview.may.manage ? <TabsTrigger value="items">{t('fnb.mini.itemsTab')}</TabsTrigger> : null}
                </TabsList>
                {overview.may.operate ? (
                    <TabsContent className="flex flex-col gap-3" value="check">
                        <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); find(); }}>
                            <FormField hint={t('fnb.mini.scanHint')} label={t('fnb.mini.scan')}><Input autoFocus onChange={(e) => setCode(e.target.value)} value={code} /></FormField>
                            <Button disabled={code.trim() === ''} type="submit">{t('fnb.mini.find')}</Button>
                        </form>
                        {scanError !== null ? <Alert title={scanError} tone="warning" /> : null}
                        {done !== null ? <Alert title={t('fnb.mini.done', { number: done.number, room: done.room.number, charged: done.posted ? money(done.charged_total_minor ?? 0) : '—' })} tone="success" /> : null}
                        {scanned !== null ? (
                            <section className="flex flex-col gap-3" data-testid="minibar-check">
                                <h2 className="text-base font-semibold">{t('fnb.mini.roomTitle', { room: scanned.room.number })}</h2>
                                {scanned.in_house ? <p className="text-sm">{t('fnb.mini.guest', { name: scanned.guest_name ?? '' })}</p> : <Alert title={t('fnb.mini.closedFolio')} tone="warning" />}
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead><tr className="text-left text-xs text-muted-foreground"><th scope="col">{t('hr.shift.name')}</th><th className="text-right" scope="col">{t('fnb.mini.price')}</th><th className="text-right" scope="col">{t('fnb.mini.held')}</th><th scope="col">{t('fnb.mini.consumed')}</th><th scope="col">{t('fnb.mini.refilled')}</th></tr></thead>
                                        <tbody>
                                            {scanned.items.map((i) => (
                                                <tr key={i.id}>
                                                    <th className="text-left font-normal" scope="row">{i.name}</th>
                                                    <td className="text-right tabular-nums">{money(i.price_minor)}</td>
                                                    <td className="text-right tabular-nums">{i.held} / {i.par_qty}</td>
                                                    <td><Input aria-label={`${i.name}: ${t('fnb.mini.consumed')}`} className="w-20" inputMode="numeric" onChange={(e) => setQty({ ...qty, [i.id]: { consumed: e.target.value.replace(/\D/g, ''), refilled: qty[i.id]?.refilled ?? '' } })} value={qty[i.id]?.consumed ?? ''} /></td>
                                                    <td><Input aria-label={`${i.name}: ${t('fnb.mini.refilled')}`} className="w-20" inputMode="numeric" onChange={(e) => setQty({ ...qty, [i.id]: { consumed: qty[i.id]?.consumed ?? '', refilled: e.target.value.replace(/\D/g, '') } })} value={qty[i.id]?.refilled ?? ''} /></td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <p className="text-sm">{t('fnb.mini.total', { amount: money(scanned.items.reduce((sum, i) => sum + n(qty[i.id]?.consumed) * i.price_minor, 0)) })}</p>
                                <div><Button disabled={action.busy || scanned.items.every((i) => n(qty[i.id]?.consumed) === 0 && n(qty[i.id]?.refilled) === 0)} loading={action.busy} onClick={() => void save()} type="button">{t('fnb.mini.save')}</Button></div>
                            </section>
                        ) : null}
                    </TabsContent>
                ) : null}
                {overview.may.operate ? (
                    <TabsContent className="flex flex-col gap-3" value="refill">
                        {(refill?.rooms ?? []).length === 0 ? <EmptyState illustration="checklist" title={t('fnb.mini.nothingToRefill')} /> : (
                            <>
                                <p className="text-sm font-semibold" data-testid="refill-totals">{(refill?.totals ?? []).map((x) => `${x.name} ${x.quantity}`).join(' · ')}</p>
                                <ul className="flex flex-col gap-1 text-sm" data-testid="refill-rooms">{(refill?.rooms ?? []).map((r) => <li key={r.room.id}><strong>{t('fnb.mini.roomTitle', { room: r.room.number })}</strong>: {r.items.map((x) => `${x.name} ${x.quantity}`).join(', ')}</li>)}</ul>
                            </>
                        )}
                    </TabsContent>
                ) : null}
                {overview.may.operate ? (
                    <TabsContent value="history">
                        <DataGrid caption={t('fnb.mini.historyTab')} columns={historyColumns} empty={<EmptyState illustration="checklist" title={t('fnb.mini.noHistory')} />} getRowId={(c) => c.id} id="fnb.mini.history" rows={history?.checks ?? []} testId="fnb-mini-history" />
                    </TabsContent>
                ) : null}
                {overview.may.manage ? (
                    <TabsContent className="flex flex-col gap-3" value="items">
                        <div className="flex gap-2"><Button onClick={() => { action.clear(); setItem({ id: '', code: '', name: '', price: '', par: '2', lock: 0 }); }} type="button">{t('fnb.mini.addItem')}</Button></div>
                        <DataGrid caption={t('fnb.mini.itemsTab')} columns={itemColumns} empty={<EmptyState illustration="checklist" title={t('fnb.mini.noItems')} />} getRowId={(i) => i.id} id="fnb.mini.items" rows={overview.items} testId="fnb-mini-items" />
                    </TabsContent>
                ) : null}
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setItem(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={item === null || item.name.trim() === '' || parseMajorToMinor(item.price, overview.currency) === null || (item.id === '' && item.code.trim() === '')} loading={action.busy} onClick={() => void saveItem()} type="button">{t('fnb.mini.saveItem')}</Button></>}
                onClose={() => setItem(null)}
                open={item !== null}
                title={item?.id === '' ? t('fnb.mini.addItem') : t('hr.edit')}
            >
                {item !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('code')} field="code" label={t('hr.shift.code')}><Input disabled={item.id !== ''} maxLength={12} onChange={(e) => setItem({ ...item, code: e.target.value })} value={item.code} /></FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('hr.shift.name')}><Input maxLength={80} onChange={(e) => setItem({ ...item, name: e.target.value })} value={item.name} /></FormField>
                        <FormField error={action.fieldError('price_minor')} field="price_minor" hint={t('fnb.mini.priceHint')} label={t('fnb.mini.price')}><MoneyInput onChange={(e) => setItem({ ...item, price: e.target.value })} value={item.price} /></FormField>
                        <FormField error={action.fieldError('par_qty')} field="par_qty" hint={t('fnb.mini.parHint')} label={t('fnb.mini.par')}><Input inputMode="numeric" onChange={(e) => setItem({ ...item, par: e.target.value.replace(/\D/g, '') })} value={item.par} /></FormField>
                    </div>
                )}
            </Dialog>
        </FnbShell>
    );
}
