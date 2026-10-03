import { Link, router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import type { BillApproval, BillLine, BillPayment, BillView, OrderItem } from '@/modules/fnb-sales/lib/fnb';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { minorToMajorText, parseMajorToMinor } from '@/shared/money/money';
import { Select } from '@/components/ui/select';
import type { MessageKey } from '@/locales/en/index';

type Pick = { item: OrderItem; variant: string; modifiers: string[]; quantity: number; note: string };
type PayForm = { method: 'cash' | 'card' | 'qris' | 'room'; amount: string; tendered: string; reference: string; room: string; guest: string };
type QrisForm = { payment: BillPayment; status: string; reference: string; reason: string };
type Confirm = { kind: 'void'; line: BillLine } | { kind: 'cancel' };

const PREP_TONE = { new: 'neutral', preparing: 'info', ready: 'success', served: 'neutral' } as const;
const LINE_TONE = { pending: 'pending', sent: 'success', voided: 'neutral', removed: 'neutral' } as const;

/** One bill: the menu to order from, what was ordered with its state, what it comes to, and sending, voiding and cancelling. */
export default function BillPage({ view }: { view: BillView }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [pick, setPick] = useState<Pick | null>(null);
    const [confirm, setConfirm] = useState<Confirm | null>(null);
    const [reason, setReason] = useState('');
    const [needsApproval, setNeedsApproval] = useState(false);
    const [requested, setRequested] = useState(false);
    const [pickError, setPickError] = useState<string | null>(null);
    const [pay, setPay] = useState<PayForm | null>(null);
    const [payError, setPayError] = useState<string | null>(null);
    const [qris, setQris] = useState<QrisForm | null>(null);
    const { bill, outlet, totals } = view;
    const open = bill.status === 'open';
    const operate = view.may.operate;
    const money = (minor: number) => format.money(minor, view.currency);
    const reload = ['view'];
    const pending = bill.lines.filter((l) => l.status === 'pending');
    const place = bill.table !== null ? t('fnb.pos.tableLabel', { code: bill.table }) : bill.room !== null ? t('fnb.pos.roomLabel', { number: bill.room }) : t('fnb.pos.counter');
    const approvalFor = (ref: string, type: string, status: string): BillApproval | undefined => view.approvals.find((a) => a.subject_ref === ref && a.subject_type === type && a.status === status && !a.consumed);

    function onFailure(failure: { conflict: { reason: string } | null }) {
        if (failure.conflict?.reason === 'approval_required') setNeedsApproval(true);
    }

    async function add(item: OrderItem, variant: string | null, modifiers: string[], quantity: number, note: string) {
        const done = await action.run(`/fnb/bills/${bill.id}/lines`, {
            idempotencyKey: newIdempotencyKey(), reload,
            body: { lock_version: bill.lock_version, item_id: item.id, variant_id: variant, modifier_ids: modifiers, quantity, note: note.trim() === '' ? null : note.trim() },
        });
        if (done !== null) setPick(null);
    }

    function choose(item: OrderItem) {
        if (!operate || !item.is_available) return;
        action.clear();
        setPickError(null);
        if (item.variants.length === 0 && item.groups.length === 0) {
            void add(item, null, [], 1, '');

            return;
        }

        setPick({ item, variant: item.variants[0]?.id ?? '', modifiers: [], quantity: 1, note: '' });
    }

    function confirmPick() {
        if (pick === null) return;
        const { item } = pick;

        if (item.variants.length > 0 && pick.variant === '') {
            setPickError(t('fnb.bill.chooseVariant'));

            return;
        }

        for (const g of item.groups) {
            const n = g.modifiers.filter((m) => pick.modifiers.includes(m.id)).length;

            if (n < g.min_select || n > g.max_select) {
                setPickError(t('fnb.bill.chooseGroup', { group: g.name, min: g.min_select, max: g.max_select }));

                return;
            }
        }

        setPickError(null);
        void add(item, item.variants.length > 0 ? pick.variant : null, pick.modifiers, pick.quantity, pick.note);
    }

    const pickUnit = (p: Pick): number => {
        const variant = p.item.variants.find((v) => v.id === p.variant);
        const extra = p.item.groups.flatMap((g) => g.modifiers).filter((m) => p.modifiers.includes(m.id)).reduce((sum, m) => sum + m.price_delta_minor, 0);

        return (variant?.price_minor ?? p.item.price_minor) + extra;
    };

    async function remove(line: BillLine) {
        await action.run(`/fnb/bills/${bill.id}/lines/${line.id}/remove`, { body: { lock_version: bill.lock_version }, reload });
    }

    async function send() {
        await action.run(`/fnb/bills/${bill.id}/send`, { idempotencyKey: newIdempotencyKey(), body: { lock_version: bill.lock_version }, reload });
    }

    function openConfirm(next: Confirm) {
        action.clear();
        setNeedsApproval(false);
        setRequested(false);
        setReason('');
        setConfirm(next);
    }

    const subject = confirm === null ? '' : confirm.kind === 'void' ? 'fnb.item.void' : 'fnb.bill.cancel';
    const ref = confirm === null ? '' : confirm.kind === 'void' ? confirm.line.id : bill.id;
    const ready = confirm === null ? undefined : approvalFor(ref, subject, 'approved');
    const waiting = confirm === null ? undefined : approvalFor(ref, subject, 'pending');

    async function submitConfirm() {
        if (confirm === null) return;
        const url = confirm.kind === 'void' ? `/fnb/bills/${bill.id}/lines/${confirm.line.id}/void` : `/fnb/bills/${bill.id}/cancel`;
        const done = await action.run(url, { idempotencyKey: newIdempotencyKey(), body: { lock_version: bill.lock_version, reason: reason.trim(), approval_id: ready?.id ?? null }, reload, onFailure });
        if (done !== null) setConfirm(null);
    }

    async function requestApproval() {
        if (confirm === null) return;
        const url = confirm.kind === 'void' ? `/fnb/bills/${bill.id}/lines/${confirm.line.id}/void-request` : `/fnb/bills/${bill.id}/cancel-request`;
        const done = await action.run(url, { idempotencyKey: newIdempotencyKey(), body: { reason: reason.trim() }, reload });
        if (done !== null) {
            setRequested(true);
            setNeedsApproval(false);
        }
    }

    function openPay() {
        action.clear();
        setPayError(null);
        setPay({ method: 'cash', amount: minorToMajorText(view.left_minor, view.currency), tendered: minorToMajorText(view.left_minor, view.currency), reference: '', room: bill.room_id ?? '', guest: '' });
    }

    async function submitPay() {
        if (pay === null) return;
        const amount = parseMajorToMinor(pay.amount, view.currency);
        const tendered = pay.method === 'cash' ? parseMajorToMinor(pay.tendered, view.currency) : null;

        if (amount === null || (pay.method === 'cash' && tendered === null)) {
            setPayError(t('fnb.pay.badAmount'));

            return;
        }

        if (pay.method === 'cash' && tendered !== null && tendered < amount) {
            setPayError(t('fnb.pay.shortCash'));

            return;
        }

        setPayError(null);
        const done = await action.run(`/fnb/bills/${bill.id}/payments`, {
            idempotencyKey: newIdempotencyKey(), reload,
            body: { lock_version: bill.lock_version, method: pay.method, amount_minor: amount, tendered_minor: tendered, reference: pay.reference.trim() === '' ? null : pay.reference.trim(), room_id: pay.method === 'room' && pay.room !== '' ? pay.room : null, guest_name: pay.method === 'room' ? pay.guest.trim() : null },
        });
        if (done !== null) setPay(null);
    }

    function openQris(payment: BillPayment, status: string) {
        action.clear();
        setQris({ payment, status, reference: payment.reference ?? '', reason: '' });
    }

    async function submitQris() {
        if (qris === null) return;
        const done = await action.run(`/fnb/bills/${bill.id}/payments/${qris.payment.id}/qris`, {
            idempotencyKey: newIdempotencyKey(), reload,
            body: { lock_version: bill.lock_version, status: qris.status, reference: qris.reference.trim() === '' ? null : qris.reference.trim(), reason: qris.reason.trim() === '' ? null : qris.reason.trim() },
        });
        if (done !== null) setQris(null);
    }

    const paymentLine = (p: BillPayment): string => (p.method === 'cash' ? t('fnb.pay.rowCash', { tendered: money(p.tendered_minor ?? p.amount_minor), change: money(p.change_minor) }) : p.method === 'room' ? t('fnb.pay.rowRoom', { name: p.guest_name ?? '' }) : p.reference ?? '');
    const payChange = pay !== null && pay.method === 'cash' ? (parseMajorToMinor(pay.tendered, view.currency) ?? 0) - (parseMajorToMinor(pay.amount, view.currency) ?? 0) : 0;
    const lineTitle = (l: BillLine) => `${l.quantity} × ${l.item_name}${l.variant_name !== null ? ` (${l.variant_name})` : ''}`;
    const failure = action.error !== null && !needsApproval ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => router.reload()} /> : null;

    return (
        <FnbShell
            actions={<Button asChild variant="outline"><Link href={`/fnb/pos?outlet=${outlet.id}`}>{t('fnb.bill.back')}</Link></Button>}
            description={t('fnb.bill.where', { outlet: outlet.name, place }) + ' · ' + t('fnb.bill.guests', { count: bill.covers }) + ' · ' + t('fnb.bill.dateNote', { date: format.date(bill.business_date) })}
            title={t('fnb.bill.title', { number: bill.number })}
            wide
        >
            {bill.status === 'cancelled' ? <Alert title={t('fnb.bill.cancelledNote', { reason: bill.cancel_reason ?? '' })} tone="warning" /> : null}
            {confirm === null && pick === null && pay === null && qris === null ? failure : null}

            <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
                {open ? (
                    <section aria-labelledby="fnb-menu-h" className="flex flex-col gap-3 print:hidden">
                        <h2 className="text-lg font-semibold" id="fnb-menu-h">{t('fnb.bill.menu')}</h2>
                        {!open ? null : view.menu.length === 0 ? <EmptyState illustration="coffee" title={t('fnb.bill.noMenu')} /> : (
                            <Tabs defaultValue={view.menu[0]?.id}>
                                <TabsList aria-label={t('fnb.bill.menu')}>
                                    {view.menu.map((c) => <TabsTrigger key={c.id} value={c.id}>{c.name}</TabsTrigger>)}
                                </TabsList>
                                {view.menu.map((c) => (
                                    <TabsContent key={c.id} value={c.id}>
                                        {c.items.length === 0 ? <EmptyState illustration="coffee" title={t('fnb.bill.noItems')} /> : (
                                            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="menu-buttons">
                                                {c.items.map((i) => (
                                                    <li key={i.id}>
                                                        <button
                                                            className="flex h-full min-h-24 w-full flex-col justify-between gap-1 border border-border bg-surface p-3 text-left transition-colors hover:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                                                            disabled={!operate || !i.is_available || action.busy}
                                                            onClick={() => choose(i)}
                                                            type="button"
                                                        >
                                                            <span className="font-medium">{i.name}</span>
                                                            {i.description !== null ? <span className="line-clamp-2 text-xs text-muted-foreground">{i.description}</span> : null}
                                                            <span className="flex items-center justify-between gap-2 text-sm">
                                                                <span>{i.variants.length > 0 ? t('fnb.bill.from', { price: money(Math.min(...i.variants.map((v) => v.price_minor))) }) : money(i.price_minor)}</span>
                                                                {!i.is_available ? <StatusBadge label={t('fnb.bill.soldOut')} tone="warning" /> : null}
                                                            </span>
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </TabsContent>
                                ))}
                            </Tabs>
                        )}
                    </section>
                ) : <div />}

                <section aria-labelledby="fnb-order-h" className="flex flex-col gap-3 border border-border bg-surface p-4">
                    <div className="flex items-center justify-between gap-2">
                        <h2 className="text-lg font-semibold" id="fnb-order-h">{t('fnb.bill.order')}</h2>
                        <StatusBadge label={t(`fnb.bill.status.${bill.status}` as MessageKey)} tone={open ? 'info' : 'neutral'} />
                    </div>

                    {bill.lines.length === 0 ? <EmptyState illustration="checklist" title={t('fnb.bill.empty')} /> : (
                        <ul className="flex flex-col divide-y divide-border" data-testid="bill-lines">
                            {bill.lines.map((l) => (
                                <li className={`flex flex-col gap-1 py-2 ${l.status === 'voided' || l.status === 'removed' ? 'text-muted-foreground line-through' : ''}`} key={l.id}>
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="font-medium">{lineTitle(l)}</span>
                                        <span className="whitespace-nowrap tabular-nums">{money(l.line_total_minor)}</span>
                                    </div>
                                    {l.modifiers.length > 0 ? <p className="text-xs text-muted-foreground">{l.modifiers.map((m) => m.name).join(', ')}</p> : null}
                                    {l.note !== null ? <p className="text-xs text-muted-foreground">“{l.note}”</p> : null}
                                    <div className="flex flex-wrap items-center gap-2">
                                        <StatusBadge label={t(`fnb.bill.line.${l.status}` as MessageKey)} tone={LINE_TONE[l.status]} />
                                        {l.status === 'sent' && l.station !== 'none' ? <StatusBadge label={t(`fnb.bill.prep.${l.prep_status}` as MessageKey)} tone={PREP_TONE[l.prep_status]} /> : null}
                                        {l.status === 'voided' && l.void_reason !== null ? <span className="text-xs">{t('fnb.bill.voidReason', { reason: l.void_reason })}</span> : null}
                                        {open && operate && l.status === 'pending' ? <Button disabled={action.busy} onClick={() => void remove(l)} size="sm" type="button" variant="outline">{t('fnb.bill.remove')}</Button> : null}
                                        {open && operate && l.status === 'sent' ? <Button disabled={action.busy} onClick={() => openConfirm({ kind: 'void', line: l })} size="sm" type="button" variant="outline">{t('fnb.bill.void')}</Button> : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    <dl className="flex flex-col gap-1 border-t border-border pt-3 text-sm" data-testid="bill-totals">
                        <div className="flex justify-between"><dt>{t('fnb.bill.subtotal')}</dt><dd className="tabular-nums">{money(totals.subtotal_minor)}</dd></div>
                        {totals.scheme_missing ? null : (
                            <>
                                <div className="flex justify-between"><dt>{t('fnb.bill.service')}</dt><dd className="tabular-nums">{money(totals.service_charge_minor)}</dd></div>
                                <div className="flex justify-between"><dt>{t('fnb.bill.tax')}</dt><dd className="tabular-nums">{money(totals.tax_minor)}</dd></div>
                            </>
                        )}
                        <div className="flex justify-between border-t border-border pt-2 text-base font-semibold"><dt>{t('fnb.bill.total')}</dt><dd className="tabular-nums">{money(totals.total_minor)}</dd></div>
                    </dl>
                    {outlet.prices_include_charges && totals.subtotal_minor > 0 ? <p className="text-xs text-muted-foreground">{t('fnb.bill.included')}</p> : null}
                    {totals.scheme_missing ? <Alert title={t('fnb.bill.schemeMissing')} tone="warning"><p>{t('fnb.bill.schemeMissingHint')}</p></Alert> : null}

                    {view.payments.length > 0 || view.may.cashier ? (
                        <div className="flex flex-col gap-2 border-t border-border pt-3" data-testid="bill-payments">
                            <h3 className="font-semibold">{t('fnb.pay.title')}</h3>
                            {view.payments.length === 0 ? <p className="text-sm text-muted-foreground">{t('fnb.pay.none')}</p> : (
                                <ul className="flex flex-col gap-2 text-sm">
                                    {view.payments.map((p) => (
                                        <li className="flex flex-col gap-1" key={p.id}>
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="flex items-center gap-2">{t(`fnb.pay.method.${p.method}` as MessageKey)} <StatusBadge label={t(`fnb.pay.status.${p.status}` as MessageKey)} tone={p.status === 'paid' ? 'success' : p.status === 'failed' || p.status === 'expired' ? 'neutral' : p.status === 'unknown' ? 'warning' : 'pending'} /></span>
                                                <span className="tabular-nums">{money(p.amount_minor)}</span>
                                            </div>
                                            {paymentLine(p) !== '' ? <p className="text-xs text-muted-foreground">{paymentLine(p)}</p> : null}
                                            {p.status_reason !== null ? <p className="text-xs text-muted-foreground">{p.status_reason}</p> : null}
                                            {p.method === 'qris' && open && view.may.cashier && ['initiated', 'pending', 'unknown'].includes(p.status) ? (
                                                <div className="flex flex-wrap gap-2">
                                                    {p.status === 'initiated' ? <Button disabled={action.busy} onClick={() => openQris(p, 'pending')} size="sm" type="button" variant="outline">{t('fnb.pay.markPending')}</Button> : null}
                                                    <Button disabled={action.busy} onClick={() => openQris(p, 'paid')} size="sm" type="button" variant="outline">{t('fnb.pay.markPaid')}</Button>
                                                    <Button disabled={action.busy} onClick={() => openQris(p, 'failed')} size="sm" type="button" variant="outline">{t('fnb.pay.markFailed')}</Button>
                                                    <Button disabled={action.busy} onClick={() => openQris(p, 'expired')} size="sm" type="button" variant="outline">{t('fnb.pay.markExpired')}</Button>
                                                    {p.status !== 'unknown' ? <Button disabled={action.busy} onClick={() => openQris(p, 'unknown')} size="sm" type="button" variant="outline">{t('fnb.pay.markUnknown')}</Button> : null}
                                                </div>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {open ? (
                                <dl className="flex flex-col gap-1 text-sm">
                                    <div className="flex justify-between"><dt>{t('fnb.pay.paid')}</dt><dd className="tabular-nums">{money(view.paid_minor)}</dd></div>
                                    {view.reserved_minor > 0 ? <div className="flex justify-between"><dt>{t('fnb.pay.reserved')}</dt><dd className="tabular-nums">{money(view.reserved_minor)}</dd></div> : null}
                                    <div className="flex justify-between font-semibold"><dt>{t('fnb.pay.left')}</dt><dd className="tabular-nums">{money(view.left_minor)}</dd></div>
                                </dl>
                            ) : null}
                            {open && view.may.cashier ? (
                                view.shift === null ? <Alert actions={<Button asChild size="sm" variant="outline"><Link href="/fnb/shift">{t('fnb.pos.toShift')}</Link></Button>} title={t('fnb.pay.needShift')} tone="info" />
                                    : pending.length > 0 ? <p className="text-xs text-muted-foreground">{t('fnb.pay.needSend')}</p>
                                    : totals.scheme_missing ? <p className="text-xs text-muted-foreground">{t('fnb.pay.schemeMissing')}</p>
                                    : view.left_minor > 0 ? <Button disabled={action.busy} onClick={openPay} type="button">{t('fnb.pay.take')}</Button> : null
                            ) : null}
                            {bill.status === 'settled' ? (
                                <div className="flex flex-col gap-2">
                                    <p className="text-sm font-medium">{t('fnb.pay.settledNote')} {bill.closed_at !== null ? t('fnb.pay.paidAt', { time: format.instant(bill.closed_at) }) : ''}</p>
                                    <Button className="print:hidden" onClick={() => window.print()} type="button" variant="outline">{t('fnb.pay.print')}</Button>
                                    <p className="hidden text-center text-sm print:block">{t('fnb.pay.receiptThanks')}</p>
                                </div>
                            ) : null}
                        </div>
                    ) : null}

                    {open && operate ? (
                        <div className="flex flex-col gap-2 border-t border-border pt-3">
                            <Button disabled={pending.length === 0} loading={action.busy} onClick={() => void send()} type="button">{t('fnb.bill.send')}</Button>
                            <p className="text-xs text-muted-foreground">{pending.length > 0 ? t('fnb.bill.sendHint', { count: pending.length }) : t('fnb.bill.nothingToSend')}</p>
                            <Button disabled={action.busy} onClick={() => openConfirm({ kind: 'cancel' })} type="button" variant="outline">{t('fnb.bill.cancelBill')}</Button>
                        </div>
                    ) : null}
                </section>
            </div>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setPick(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={confirmPick} type="button">{t('fnb.bill.add')}</Button>
                </>}
                onClose={() => setPick(null)}
                open={pick !== null}
                title={pick === null ? '' : t('fnb.bill.optionsTitle', { name: pick.item.name })}
            >
                {pick !== null && (
                    <div className="flex flex-col gap-4">
                        {failure}
                        {pickError !== null ? <Alert title={pickError} tone="warning" /> : null}
                        {pick.item.variants.length > 0 ? (
                            <fieldset className="flex flex-col gap-2">
                                <legend className="text-sm font-medium">{t('fnb.bill.variant')}</legend>
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
                                <legend className="text-sm font-medium">{g.name} <span className="font-normal text-muted-foreground">({g.min_select > 0 ? `${t('fnb.bill.groupRequired')}, ` : ''}{t('fnb.bill.groupRule', { min: g.min_select, max: g.max_select })})</span></legend>
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
                        <div className="flex items-end gap-3">
                            <FormField label={t('fnb.bill.quantity')}>
                                <div className="flex items-center gap-2">
                                    <Button aria-label="−" onClick={() => setPick({ ...pick, quantity: Math.max(1, pick.quantity - 1) })} size="icon" type="button" variant="outline"><Minus aria-hidden="true" className="size-4" /></Button>
                                    <span className="w-8 text-center text-lg font-semibold tabular-nums">{pick.quantity}</span>
                                    <Button aria-label="+" onClick={() => setPick({ ...pick, quantity: Math.min(99, pick.quantity + 1) })} size="icon" type="button" variant="outline"><Plus aria-hidden="true" className="size-4" /></Button>
                                </div>
                            </FormField>
                            <p className="pb-2 text-sm text-muted-foreground">{t('fnb.bill.addPrice', { price: money(pickUnit(pick)) })}</p>
                        </div>
                        <FormField error={action.fieldError('note')} field="note" label={t('fnb.bill.note')}>
                            <Input maxLength={120} onChange={(e) => setPick({ ...pick, note: e.target.value })} value={pick.note} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setConfirm(null)} type="button" variant="outline">{t('fnb.bill.close')}</Button>
                    {waiting === undefined || ready !== undefined ? <Button disabled={reason.trim() === ''} loading={action.busy} onClick={() => void submitConfirm()} type="button">{confirm?.kind === 'void' ? t('fnb.bill.confirmVoid') : t('fnb.bill.confirmCancel')}</Button> : null}
                </>}
                onClose={() => setConfirm(null)}
                open={confirm !== null}
                title={confirm === null ? '' : confirm.kind === 'void' ? t('fnb.bill.voidTitle', { item: lineTitle(confirm.line) }) : t('fnb.bill.cancelTitle', { number: bill.number })}
            >
                {confirm !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{confirm.kind === 'void' ? t('fnb.bill.voidHint') : t('fnb.bill.cancelHint')}</p>
                        {failure}
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fnb.bill.reason')}>
                            <Input maxLength={200} onChange={(e) => setReason(e.target.value)} value={reason} />
                        </FormField>
                        {ready !== undefined ? <StatusBadge label={t('fnb.bill.approvalReady')} tone="success" /> : null}
                        {waiting !== undefined && ready === undefined ? <StatusBadge label={t('fnb.bill.approvalPending')} tone="pending" /> : null}
                        {needsApproval ? <Alert actions={<Button disabled={reason.trim() === ''} loading={action.busy} onClick={() => void requestApproval()} size="sm" type="button">{t('fnb.bill.requestApproval')}</Button>} title={t('fnb.bill.approvalNeeded')} tone="warning" /> : null}
                        {requested ? <Alert title={t('fnb.bill.approvalRequested')} tone="info" /> : null}
                    </div>
                )}
            </Dialog>
            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setPay(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void submitPay()} type="button">{t('fnb.pay.record')}</Button>
                </>}
                onClose={() => setPay(null)}
                open={pay !== null}
                title={t('fnb.pay.title')}
            >
                {pay !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        {payError !== null ? <div className="sm:col-span-2"><Alert title={payError} tone="warning" /></div> : null}
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('method')} field="method" label={t('fnb.pay.method')}>
                                <Select onChange={(e) => setPay({ ...pay, method: e.target.value as PayForm['method'], amount: e.target.value === 'room' ? minorToMajorText(totals.total_minor, view.currency) : pay.amount })} value={pay.method}>
                                    {(['cash', 'card', 'qris', 'room'] as const).map((m) => <option key={m} value={m}>{t(`fnb.pay.method.${m}` as MessageKey)}</option>)}
                                </Select>
                            </FormField>
                        </div>
                        <FormField error={action.fieldError('amount_minor')} field="amount_minor" label={t('fnb.pay.amount', { currency: view.currency })}>
                            <Input inputMode="decimal" onChange={(e) => setPay({ ...pay, amount: e.target.value })} readOnly={pay.method === 'room'} value={pay.amount} />
                        </FormField>
                        {pay.method === 'cash' ? (
                            <FormField error={action.fieldError('tendered_minor')} field="tendered_minor" hint={payChange > 0 ? t('fnb.pay.change', { amount: money(payChange) }) : undefined} label={t('fnb.pay.tendered', { currency: view.currency })}>
                                <Input inputMode="decimal" onChange={(e) => setPay({ ...pay, tendered: e.target.value })} value={pay.tendered} />
                            </FormField>
                        ) : null}
                        {pay.method === 'card' ? (
                            <FormField error={action.fieldError('reference')} field="reference" label={t('fnb.pay.cardCode')}>
                                <Input maxLength={60} onChange={(e) => setPay({ ...pay, reference: e.target.value })} value={pay.reference} />
                            </FormField>
                        ) : null}
                        {pay.method === 'qris' ? <p className="text-sm text-muted-foreground sm:col-span-2">{t('fnb.pay.qrisNote')}</p> : null}
                        {pay.method === 'room' ? (
                            <>
                                <FormField error={action.fieldError('room_id')} field="room_id" hint={t('fnb.pay.roomHint')} label={t('fnb.pay.room')}>
                                    <Select onChange={(e) => setPay({ ...pay, room: e.target.value })} value={pay.room}>
                                        <option value="" />
                                        {view.rooms.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}
                                    </Select>
                                </FormField>
                                <FormField error={action.fieldError('guest_name')} field="guest_name" hint={t('fnb.pay.guestNameHint')} label={t('fnb.pay.guestName')}>
                                    <Input maxLength={80} onChange={(e) => setPay({ ...pay, guest: e.target.value })} value={pay.guest} />
                                </FormField>
                            </>
                        ) : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setQris(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void submitQris()} type="button">{t('fnb.pay.qrisConfirm')}</Button>
                </>}
                onClose={() => setQris(null)}
                open={qris !== null}
                title={qris === null ? '' : t('fnb.pay.qrisTitle', { amount: money(qris.payment.amount_minor) }) + ' · ' + t(`fnb.pay.status.${qris.status}` as MessageKey)}
            >
                {qris !== null && (
                    <div className="flex flex-col gap-3">
                        {qris.payment.status === 'unknown' || qris.status === 'unknown' ? <p className="text-sm text-muted-foreground">{t('fnb.pay.unknownHint')}</p> : null}
                        {failure}
                        {qris.status === 'paid' ? (
                            <FormField error={action.fieldError('reference')} field="reference" label={t('fnb.pay.qrisReference')}>
                                <Input maxLength={60} onChange={(e) => setQris({ ...qris, reference: e.target.value })} value={qris.reference} />
                            </FormField>
                        ) : null}
                        {qris.status !== 'pending' ? (
                            <FormField error={action.fieldError('reason')} field="reason" label={t('fnb.pay.qrisReason')}>
                                <Input maxLength={200} onChange={(e) => setQris({ ...qris, reason: e.target.value })} value={qris.reason} />
                            </FormField>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </FnbShell>
    );
}
