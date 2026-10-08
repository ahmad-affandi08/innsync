import { Head, Link, router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { GuestShell } from '@/modules/guest/components/guest-shell';
import { qrLabel } from '@/modules/guest/lib/qr';
import type { GuestItem, GuestMenu, GuestOrder } from '@/modules/guest/lib/guest';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Line = { key: string; item: GuestItem; variant: string | null; modifiers: string[]; quantity: number; note: string };
type Pick = { item: GuestItem; variant: string; modifiers: string[]; quantity: number; note: string };

/** The menu a guest reaches with a QR code: choose, prove the stay when it is a room, say how to pay, and send the order to the kitchen. */
export default function MenuPage({ view: first }: { view: GuestMenu }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const action = useServerAction();
    const [view, setView] = useState(first);
    const [cart, setCart] = useState<Line[]>([]);
    const [pick, setPick] = useState<Pick | null>(null);
    const [pickError, setPickError] = useState<string | null>(null);
    const [payment, setPayment] = useState<'qris' | 'room' | 'later'>('later');
    const [note, setNote] = useState('');
    const [room, setRoom] = useState('');
    const [surname, setSurname] = useState('');
    const [proofError, setProofError] = useState(false);
    const [placed, setPlaced] = useState<GuestOrder | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [key, setKey] = useState(() => newIdempotencyKey());
    const money = (minor: number) => format.money(minor, view.currency);
    const unit = (l: Line) => (l.variant === null ? l.item.price_minor : (l.item.variants.find((v) => v.id === l.variant)?.price_minor ?? l.item.price_minor)) + l.item.groups.flatMap((g) => g.modifiers).filter((m) => l.modifiers.includes(m.id)).reduce((sum, m) => sum + m.price_delta_minor, 0);
    const total = cart.reduce((sum, l) => sum + unit(l) * l.quantity, 0);
    const needsProof = (view.kind === 'room' && !view.verified) || (payment === 'room' && !view.verified);

    function add(item: GuestItem, variant: string | null, modifiers: string[], quantity: number, noteText: string) {
        const lineKey = [item.id, variant ?? '', [...modifiers].sort().join(','), noteText.trim()].join('|');
        const existing = cart.find((l) => l.key === lineKey);

        setPlaced(null);
        setKey(newIdempotencyKey());
        setCart(existing === undefined ? [...cart, { key: lineKey, item, variant, modifiers, quantity, note: noteText.trim() }] : cart.map((l) => (l.key === lineKey ? { ...l, quantity: Math.min(20, l.quantity + quantity) } : l)));
    }

    function choose(item: GuestItem) {
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
                setPickError(t('guest.menu.chooseGroup', { group: g.name, min: g.min_select, max: g.max_select }));

                return;
            }
        }

        add(pick.item, pick.item.variants.length > 0 ? pick.variant : null, pick.modifiers, pick.quantity, pick.note);
        setPick(null);
    }

    function step(lineKey: string, by: number) {
        setKey(newIdempotencyKey());
        setCart(cart.flatMap((l) => (l.key !== lineKey ? [l] : l.quantity + by < 1 ? [] : [{ ...l, quantity: Math.min(20, l.quantity + by) }])));
    }

    async function prove() {
        setProofError(false);
        const done = await action.run<GuestMenu>('/g/verify', { body: { room_number: room.trim(), surname: surname.trim() } });

        if (done === null) {
            setProofError(true);

            return;
        }

        setView(done as unknown as GuestMenu);
    }

    async function send() {
        setError(null);
        const order = await action.run<GuestOrder>('/g/order', {
            body: {
                client_key: key, note: note.trim() === '' ? null : note.trim(), payment,
                lines: cart.map((l) => ({ item_id: l.item.id, variant_id: l.variant, modifier_ids: l.modifiers, quantity: l.quantity, note: l.note === '' ? null : l.note })),
            },
        });

        if (order === null) {
            setError(t('guest.menu.notSent'));

            return;
        }

        setPlaced(order as unknown as GuestOrder);
        setCart([]);
        setNote('');
        setKey(newIdempotencyKey());
    }

    return (
        <>
            <Head title={qrLabel(view.label, t)} />
            <GuestShell hotel={view.hotel} subtitle={view.guest_name !== null ? t('guest.menu.hello', { name: view.guest_name }) : undefined} title={qrLabel(view.label, t)}>
                {!view.available ? <Alert title={t('guest.menu.unavailable')} tone="warning" /> : null}
                {placed !== null ? (
                    <Alert actions={<Button asChild size="sm" variant="outline"><Link href="/g/orders">{t('guest.menu.follow')}</Link></Button>} title={t('guest.menu.placed', { number: placed.bill_number })} tone="success">
                        <p>{placed.room_charge === 'pending' ? t('guest.menu.roomPending') : placed.payment === 'qris' ? t('guest.menu.qrisAtCashier') : t('guest.menu.placedHint')}</p>
                    </Alert>
                ) : null}

                {view.needs_proof ? <Alert title={t('guest.proof.needed')} tone="info" /> : null}
                {view.menu.length === 0 && view.available ? <EmptyState illustration="coffee" title={t('guest.menu.empty')} /> : null}
                {view.menu.map((c) => (
                    <section aria-label={c.name} className="flex flex-col gap-2" key={c.id}>
                        <h2 className="text-sm font-semibold text-muted-foreground">{c.name}</h2>
                        <ul className="flex flex-col divide-y divide-border border border-border bg-surface">
                            {c.items.map((i) => (
                                <li key={i.id}>
                                    <button className="flex w-full items-start justify-between gap-3 px-3 py-3 text-left disabled:opacity-60" disabled={!i.is_available || !view.available} onClick={() => choose(i)} type="button">
                                        <span>
                                            <span className="block font-medium">{i.name}</span>
                                            {i.description !== null ? <span className="block text-xs text-muted-foreground">{i.description}</span> : null}
                                            {!i.is_available ? <span className="mt-1 block text-xs font-semibold text-danger">{t('guest.menu.soldOut')}</span> : null}
                                        </span>
                                        <span className="whitespace-nowrap tabular-nums">{i.variants.length > 0 ? t('guest.menu.from', { price: money(Math.min(...i.variants.map((v) => v.price_minor))) }) : money(i.price_minor)}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}

                {cart.length > 0 ? (
                    <section aria-labelledby="guest-cart-h" className="flex flex-col gap-3 border border-border bg-surface p-3" data-testid="guest-cart">
                        <h2 className="font-semibold" id="guest-cart-h">{t('guest.cart.title')}</h2>
                        <ul className="flex flex-col divide-y divide-border">
                            {cart.map((l) => (
                                <li className="flex items-center justify-between gap-2 py-2 text-sm" key={l.key}>
                                    <span>{l.item.name}{l.variant !== null ? ` (${l.item.variants.find((v) => v.id === l.variant)?.name ?? ''})` : ''}{l.modifiers.length > 0 ? <span className="block text-xs text-muted-foreground">{l.item.groups.flatMap((g) => g.modifiers).filter((m) => l.modifiers.includes(m.id)).map((m) => m.name).join(', ')}</span> : null}</span>
                                    <span className="flex items-center gap-1">
                                        <Button aria-label={t('guest.cart.less')} onClick={() => step(l.key, -1)} size="icon" type="button" variant="outline"><Minus aria-hidden="true" className="size-4" /></Button>
                                        <span className="w-6 text-center tabular-nums">{l.quantity}</span>
                                        <Button aria-label={t('guest.cart.more')} onClick={() => step(l.key, 1)} size="icon" type="button" variant="outline"><Plus aria-hidden="true" className="size-4" /></Button>
                                        <span className="w-24 text-right tabular-nums">{money(unit(l) * l.quantity)}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="flex justify-between font-semibold"><span>{t('guest.cart.subtotal')}</span><span className="tabular-nums">{money(total)}</span></p>
                        <p className="text-xs text-muted-foreground">{t('guest.cart.chargesHint')}</p>
                        <FormField label={t('guest.cart.note')}><Input maxLength={200} onChange={(e) => setNote(e.target.value)} value={note} /></FormField>
                        <fieldset className="flex flex-col gap-2">
                            <legend className="text-sm font-medium">{t('guest.pay.title')}</legend>
                            {view.payments.map((p) => (
                                <label className="flex items-start gap-2 text-sm" key={p}>
                                    <input checked={payment === p} name="payment" onChange={() => setPayment(p)} type="radio" />
                                    <span>{t(`guest.pay.${p}` as MessageKey)}<span className="block text-xs text-muted-foreground">{t(`guest.pay.${p}.hint` as MessageKey)}</span></span>
                                </label>
                            ))}
                        </fieldset>
                        {needsProof ? (
                            <div className="flex flex-col gap-3 border border-border p-3" data-testid="guest-proof">
                                <p className="text-sm font-medium">{t('guest.proof.title')}</p>
                                <p className="text-xs text-muted-foreground">{t('guest.proof.hint')}</p>
                                {proofError ? <Alert title={view.locked ? t('guest.proof.locked') : t('guest.proof.failed')} tone="warning" /> : null}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField label={t('guest.proof.room')}><Input autoComplete="off" inputMode="text" onChange={(e) => setRoom(e.target.value)} value={room} /></FormField>
                                    <FormField label={t('guest.proof.name')}><Input autoComplete="off" onChange={(e) => setSurname(e.target.value)} value={surname} /></FormField>
                                </div>
                                <div><Button disabled={room.trim() === '' || surname.trim() === ''} loading={action.busy} onClick={() => void prove()} type="button" variant="outline">{t('guest.proof.confirm')}</Button></div>
                            </div>
                        ) : null}
                        {error !== null ? <Alert title={error} tone="warning" /> : null}
                        <Button disabled={needsProof || !view.can_order && !(view.kind === 'table')} loading={action.busy} onClick={() => void send()} type="button">{t('guest.cart.send')}</Button>
                    </section>
                ) : null}
                {cart.length === 0 ? <p className="text-center text-sm"><Link className="underline" href="/g/orders">{t('guest.menu.myOrders')}</Link></p> : null}
            </GuestShell>

            <Dialog
                footer={<><Button onClick={() => setPick(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button onClick={confirmPick} type="button">{t('guest.menu.add')}</Button></>}
                onClose={() => setPick(null)}
                open={pick !== null}
                title={pick?.item.name ?? ''}
            >
                {pick !== null && (
                    <div className="flex flex-col gap-4">
                        {pickError !== null ? <Alert title={pickError} tone="warning" /> : null}
                        {pick.item.variants.length > 0 ? (
                            <fieldset className="flex flex-col gap-2">
                                <legend className="text-sm font-medium">{t('guest.menu.variant')}</legend>
                                {pick.item.variants.map((v) => (
                                    <label className="flex items-center justify-between gap-2 border border-border px-3 py-2 text-sm" key={v.id}>
                                        <span className="flex items-center gap-2"><input checked={pick.variant === v.id} name="variant" onChange={() => setPick({ ...pick, variant: v.id })} type="radio" />{v.name}</span>
                                        <span className="tabular-nums">{money(v.price_minor)}</span>
                                    </label>
                                ))}
                            </fieldset>
                        ) : null}
                        {pick.item.groups.map((g) => (
                            <fieldset className="flex flex-col gap-2" key={g.id}>
                                <legend className="text-sm font-medium">{g.name} <span className="font-normal text-muted-foreground">({t('guest.menu.groupRule', { min: g.min_select, max: g.max_select })})</span></legend>
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

                                                        setPick({ ...pick, modifiers: [...others, ...(single ? [m.id] : on ? mine.filter((x) => x !== m.id) : [...mine, m.id])] });
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
                        <div className="flex items-center gap-2">
                            <Button aria-label={t('guest.cart.less')} onClick={() => setPick({ ...pick, quantity: Math.max(1, pick.quantity - 1) })} size="icon" type="button" variant="outline"><Minus aria-hidden="true" className="size-4" /></Button>
                            <span className="w-8 text-center text-lg font-semibold tabular-nums">{pick.quantity}</span>
                            <Button aria-label={t('guest.cart.more')} onClick={() => setPick({ ...pick, quantity: Math.min(20, pick.quantity + 1) })} size="icon" type="button" variant="outline"><Plus aria-hidden="true" className="size-4" /></Button>
                        </div>
                        <FormField label={t('guest.cart.itemNote')}><Input maxLength={120} onChange={(e) => setPick({ ...pick, note: e.target.value })} value={pick.note} /></FormField>
                    </div>
                )}
            </Dialog>
        </>
    );
}
