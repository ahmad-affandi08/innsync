import { router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { SyncPanel, SyncStatus } from '@/components/ui/sync-status';
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import type { OrderGroup } from '@/modules/fnb-sales/lib/fnb';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useOfflineQueue } from '@/shared/offline/offline-provider';
import { minorToMajorText, parseMajorToMinor } from '@/shared/money/money';

type Prices = { dine_in: number; takeaway: number };
type Item = { id: string; code: string; name: string; has_photo: boolean; is_available: boolean; groups: OrderGroup[]; prices: Prices; variants: { id: string; name: string; prices: Prices }[] };
type Register = {
    currency: string; outlets: { id: string; code: string; name: string }[]; outlet: { id: string; code: string; name: string } | null; tables: { id: string; code: string; area: string | null; seats: number }[];
    menu: { id: string; name: string; items: Item[] }[]; cashier: boolean; shift_open: boolean;
};
type Cart = { key: string; item: Item; variant: string | null; modifiers: string[]; quantity: number; note: string };
type Pick = { item: Item; variant: string; modifiers: string[]; quantity: number; note: string };

const SALE = 'fnb.pos.sale';

/**
 * The register for when the network is down (FR-FBS-010). Open it while the network is up: it keeps the tables and the menu with the price of each way of selling, and
 * sells from them. A sale is saved on this device, encrypted, with its own number, and sent as soon as the network allows; the server books it once however often it is
 * sent. Cash and card are taken here; QRIS and a room charge need the network, so they are done on the bill.
 */
export default function RegisterPage({ register }: { register: Register }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const queue = useOfflineQueue();
    const [cart, setCart] = useState<Cart[]>([]);
    const [table, setTable] = useState('');
    const [covers, setCovers] = useState('1');
    const [method, setMethod] = useState<'' | 'cash' | 'card'>('');
    const [tendered, setTendered] = useState('');
    const [reference, setReference] = useState('');
    const [pick, setPick] = useState<Pick | null>(null);
    const [pickError, setPickError] = useState<string | null>(null);
    const [saved, setSaved] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const channel: keyof Prices = table === '' ? 'takeaway' : 'dine_in';
    const money = (minor: number) => format.money(minor, register.currency);
    const unitOf = (item: Item, variant: string | null) => (variant === null ? item.prices[channel] : (item.variants.find((v) => v.id === variant)?.prices[channel] ?? item.prices[channel]));
    const extraOf = (item: Item, modifiers: string[]) => item.groups.flatMap((g) => g.modifiers).filter((m) => modifiers.includes(m.id)).reduce((sum, m) => sum + m.price_delta_minor, 0);
    const subtotal = useMemo(() => cart.reduce((sum, c) => sum + (unitOf(c.item, c.variant) + extraOf(c.item, c.modifiers)) * c.quantity, 0), [cart, channel]); // eslint-disable-line react-hooks/exhaustive-deps
    const soldCount = queue.entries.filter((e) => e.type === SALE && e.state !== 'accepted').length;

    function add(item: Item, variant: string | null, modifiers: string[], quantity: number, note: string) {
        const key = [item.id, variant ?? '', [...modifiers].sort().join(','), note.trim()].join('|');
        const existing = cart.find((c) => c.key === key);

        setSaved(null);
        setCart(existing === undefined ? [...cart, { key, item, variant, modifiers, quantity, note: note.trim() }] : cart.map((c) => (c.key === key ? { ...c, quantity: Math.min(99, c.quantity + quantity) } : c)));
    }

    function choose(item: Item) {
        if (!item.is_available) return;
        setPickError(null);

        if (item.variants.length === 0 && item.groups.length === 0) {
            add(item, null, [], 1, '');

            return;
        }

        setPick({ item, variant: item.variants[0]?.id ?? '', modifiers: [], quantity: 1, note: '' });
    }

    function confirmPick() {
        if (pick === null) return;

        for (const g of pick.item.groups) {
            const n = g.modifiers.filter((m) => pick.modifiers.includes(m.id)).length;

            if (n < g.min_select || n > g.max_select) {
                setPickError(t('fnb.bill.chooseGroup', { group: g.name, min: g.min_select, max: g.max_select }));

                return;
            }
        }

        add(pick.item, pick.item.variants.length > 0 ? pick.variant : null, pick.modifiers, pick.quantity, pick.note);
        setPick(null);
    }

    function step(key: string, by: number) {
        setCart(cart.flatMap((c) => (c.key !== key ? [c] : c.quantity + by < 1 ? [] : [{ ...c, quantity: Math.min(99, c.quantity + by) }])));
    }

    async function record() {
        const handed = tendered.trim() === '' ? null : parseMajorToMinor(tendered, register.currency);

        if (cart.length === 0 || register.outlet === null) return;

        if (method === 'cash' && (handed === null || handed < subtotal)) {
            setError(t('fnb.reg.cashShort'));

            return;
        }

        if (method === 'card' && reference.trim().length < 4) {
            setError(t('fnb.reg.cardCode'));

            return;
        }

        setError(null);
        setBusy(true);

        try {
            const entry = await queue.enqueue({
                type: SALE,
                payload: {
                    outlet_id: register.outlet.id, table_id: table === '' ? null : table, covers: Number(covers) || 1, note: null,
                    lines: cart.map((c) => ({ item_id: c.item.id, variant_id: c.variant, modifier_ids: c.modifiers, quantity: c.quantity, note: c.note === '' ? null : c.note, unit_price_minor: unitOf(c.item, c.variant) })),
                    payment: method === '' ? null : { method, tendered_minor: method === 'cash' ? handed : null, reference: method === 'card' ? reference.trim() : null },
                },
            });

            setSaved(entry.operationId.slice(-6).toUpperCase());
            setCart([]);
            setTendered('');
            setReference('');
            setMethod('');
        } catch {
            setError(t('fnb.reg.notSaved'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <FnbShell description={t('fnb.reg.description')} title={t('fnb.reg.title')} wide>
            <SyncStatus />
            {register.outlets.length > 1 ? (
                <FormField label={t('fnb.px.outlet')}>
                    <Select onChange={(e) => router.get('/fnb/register', { outlet: e.target.value })} value={register.outlet?.id ?? ''}>
                        {register.outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </Select>
                </FormField>
            ) : null}
            {register.outlet === null ? <EmptyState illustration="checklist" title={t('fnb.px.noOutlet')} /> : (
                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <section aria-labelledby="reg-menu-h" className="flex flex-col gap-4">
                        <h2 className="text-lg font-semibold" id="reg-menu-h">{t('fnb.bill.menu')}</h2>
                        {register.menu.map((c) => (
                            <div className="flex flex-col gap-2" key={c.id}>
                                <h3 className="text-sm font-semibold text-muted-foreground">{c.name}</h3>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {c.items.map((i) => (
                                        <button className="flex flex-col items-start gap-1 border border-border bg-surface p-3 text-left text-sm disabled:opacity-50" disabled={!i.is_available} key={i.id} onClick={() => choose(i)} type="button">
                                            {i.has_photo ? <img alt="" className="h-20 w-full object-cover" loading="lazy" src={`/fnb/items/${i.id}/photo`} /> : null}
                                            <span className="font-medium">{i.name}</span>
                                            <span className="tabular-nums text-muted-foreground">{money(i.prices[channel])}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </section>
                    <section aria-labelledby="reg-sale-h" className="flex flex-col gap-3 border border-border bg-surface p-4">
                        <h2 className="text-lg font-semibold" id="reg-sale-h">{t('fnb.reg.sale')}</h2>
                        {saved !== null ? <Alert title={t('fnb.reg.saved', { ref: saved })} tone="success"><p>{t('fnb.reg.savedHint')}</p></Alert> : null}
                        {error !== null ? <Alert title={error} tone="warning" /> : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField label={t('fnb.rg.table')}>
                                <Select onChange={(e) => setTable(e.target.value)} value={table}>
                                    <option value="">{t('fnb.pos.counter')}</option>
                                    {register.tables.map((x) => <option key={x.id} value={x.id}>{x.code}</option>)}
                                </Select>
                            </FormField>
                            <FormField label={t('fnb.pos.covers')}><Input inputMode="numeric" onChange={(e) => setCovers(e.target.value.replace(/\D/g, ''))} value={covers} /></FormField>
                        </div>
                        {cart.length === 0 ? <p className="text-sm text-muted-foreground">{t('fnb.reg.empty')}</p> : (
                            <ul className="flex flex-col divide-y divide-border" data-testid="register-cart">
                                {cart.map((c) => (
                                    <li className="flex items-center justify-between gap-2 py-2 text-sm" key={c.key}>
                                        <span>{c.item.name}{c.variant !== null ? ` (${c.item.variants.find((v) => v.id === c.variant)?.name ?? ''})` : ''}{c.modifiers.length > 0 ? <span className="block text-xs text-muted-foreground">{c.item.groups.flatMap((g) => g.modifiers).filter((m) => c.modifiers.includes(m.id)).map((m) => m.name).join(', ')}</span> : null}</span>
                                        <span className="flex items-center gap-1">
                                            <Button aria-label="−" onClick={() => step(c.key, -1)} size="icon" type="button" variant="outline"><Minus aria-hidden="true" className="size-4" /></Button>
                                            <span className="w-6 text-center tabular-nums">{c.quantity}</span>
                                            <Button aria-label="+" onClick={() => step(c.key, 1)} size="icon" type="button" variant="outline"><Plus aria-hidden="true" className="size-4" /></Button>
                                            <span className="w-24 text-right tabular-nums">{money((unitOf(c.item, c.variant) + extraOf(c.item, c.modifiers)) * c.quantity)}</span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="flex justify-between border-t border-border pt-2 font-semibold"><span>{t('fnb.bill.subtotal')}</span><span className="tabular-nums" data-testid="register-subtotal">{money(subtotal)}</span></p>
                        <p className="text-xs text-muted-foreground">{t('fnb.reg.chargesHint')}</p>
                        {register.cashier ? (
                            <>
                                <FormField hint={register.shift_open ? undefined : t('fnb.reg.noShift')} label={t('fnb.pay.method')}>
                                    <Select onChange={(e) => setMethod(e.target.value as typeof method)} value={method}>
                                        <option value="">{t('fnb.reg.payLater')}</option>
                                        <option value="cash">{t('fnb.pay.method.cash')}</option>
                                        <option value="card">{t('fnb.pay.method.card')}</option>
                                    </Select>
                                </FormField>
                                {method === 'cash' ? <FormField label={t('fnb.pay.tendered', { currency: register.currency })}><Input inputMode="decimal" onChange={(e) => setTendered(e.target.value)} placeholder={minorToMajorText(subtotal, register.currency)} value={tendered} /></FormField> : null}
                                {method === 'card' ? <FormField label={t('fnb.pay.cardCode')}><Input maxLength={60} onChange={(e) => setReference(e.target.value)} value={reference} /></FormField> : null}
                            </>
                        ) : null}
                        <Button disabled={cart.length === 0 || !queue.ready} loading={busy} onClick={() => void record()} type="button">{t('fnb.reg.record')}</Button>
                        {!queue.ready ? <p className="text-xs text-muted-foreground">{t('offline.check.notReady')}</p> : null}
                        {soldCount > 0 ? <p className="text-xs text-muted-foreground" data-testid="register-waiting">{t('fnb.reg.waiting', { count: soldCount })}</p> : null}
                    </section>
                </div>
            )}
            <SyncPanel />

            <Dialog
                footer={<><Button onClick={() => setPick(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button onClick={confirmPick} type="button">{t('fnb.bill.add')}</Button></>}
                onClose={() => setPick(null)}
                open={pick !== null}
                title={pick === null ? '' : t('fnb.bill.optionsTitle', { name: pick.item.name })}
            >
                {pick !== null && (
                    <div className="flex flex-col gap-4">
                        {pickError !== null ? <Alert title={pickError} tone="warning" /> : null}
                        {pick.item.variants.length > 0 ? (
                            <fieldset className="flex flex-col gap-2">
                                <legend className="text-sm font-medium">{t('fnb.bill.variant')}</legend>
                                {pick.item.variants.map((v) => (
                                    <label className="flex items-center justify-between gap-2 border border-border px-3 py-2 text-sm" key={v.id}>
                                        <span className="flex items-center gap-2"><input checked={pick.variant === v.id} name="variant" onChange={() => setPick({ ...pick, variant: v.id })} type="radio" />{v.name}</span>
                                        <span className="tabular-nums">{money(v.prices[channel])}</span>
                                    </label>
                                ))}
                            </fieldset>
                        ) : null}
                        {pick.item.groups.map((g) => (
                            <fieldset className="flex flex-col gap-2" key={g.id}>
                                <legend className="text-sm font-medium">{g.name} <span className="font-normal text-muted-foreground">({t('fnb.bill.groupRule', { min: g.min_select, max: g.max_select })})</span></legend>
                                {g.modifiers.map((m) => {
                                    const on = pick.modifiers.includes(m.id);
                                    const single = g.max_select === 1;

                                    return (
                                        <label className="flex items-center justify-between gap-2 border border-border px-3 py-2 text-sm" key={m.id}>
                                            <span className="flex items-center gap-2">
                                                <input
                                                    checked={on}
                                                    name={single ? g.id : undefined}
                                                    onChange={() => {
                                                        const others = pick.modifiers.filter((x) => !g.modifiers.some((gm) => gm.id === x));
                                                        const mine = pick.modifiers.filter((x) => g.modifiers.some((gm) => gm.id === x));
                                                        const next = single ? [m.id] : on ? mine.filter((x) => x !== m.id) : [...mine, m.id];

                                                        setPick({ ...pick, modifiers: [...others, ...next] });
                                                    }}
                                                    type={single ? 'radio' : 'checkbox'}
                                                />
                                                {m.name}
                                            </span>
                                            {m.price_delta_minor > 0 ? <span className="tabular-nums">+{money(m.price_delta_minor)}</span> : null}
                                        </label>
                                    );
                                })}
                            </fieldset>
                        ))}
                        <FormField label={t('fnb.bill.note')}><Input maxLength={120} onChange={(e) => setPick({ ...pick, note: e.target.value })} value={pick.note} /></FormField>
                    </div>
                )}
            </Dialog>
        </FnbShell>
    );
}
