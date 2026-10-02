import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ApprovalProgress } from '@/modules/inventory-purchasing/components/approval-progress';
import { BudgetWarning } from '@/modules/inventory-purchasing/components/budget-note';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { orderBody, orderToForm, OrderHeaderFields, OrderLineFields, priceOf, type OrderFormState, type OrderHeaderState, type OrderLineForm, type Party } from '@/modules/inventory-purchasing/components/order-form';
import { bpToInput, minorToInput, parsePercentToBp } from '@/modules/inventory-purchasing/lib/amounts';
import { nextLineKey, ORDER_TONE, type ApprovalSummary, type ItemChoice, type Standing } from '@/modules/inventory-purchasing/lib/purchasing';
import { formatMilli, plainMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Line = {
    id: string; line_no: number; item_id: string; item_code: string; item_name: string; unit: string; qty_milli: number; unit_price_minor: number; line_total_minor: number;
    department: string | null; request_line_id: string | null; received_qty_milli: number; rejected_qty_milli: number; is_active: boolean;
};
type Revision = { revision: number; reason: string; needed_approval: boolean; created_by_name: string | null; created_at: string | null; total_minor: number };
type Order = {
    id: string; number: string; revision: number; status: string; order_date: string; expected_date: string | null; supplier: Party; location: Party; payment_terms_days: number; tax_bp: number;
    subtotal_minor: number; tax_minor: number; total_minor: number; note: string | null; lock_version: number;
    currency: string; created_by_name: string | null; issued_at: string | null; close_reason: string | null; departments: string[]; items: ItemChoice[]; locations: Party[]; suppliers: Party[];
    lines: Line[]; revisions: Revision[]; pending_revision: { reason: string; total_minor: number } | null; approval: ApprovalSummary | null;
    budgets: (Standing & { this_order_minor: number })[];
    may_edit: boolean; may_submit: boolean; may_release: boolean; may_issue: boolean; may_revise: boolean; may_cancel: boolean; may_close: boolean;
};
type KeptLine = { key: string; line_id: string; item_code: string; item_name: string; unit: string; received: number; quantity: string; price: string; removed: boolean };
type Revise = { header: OrderHeaderState; reason: string; kept: KeptLine[]; added: OrderLineForm[] };

const reload = ['order'];

export default function OrderPage({ order }: { order: Order }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [edit, setEdit] = useState<OrderFormState | null>(null);
    const [revise, setRevise] = useState<Revise | null>(null);
    const [ending, setEnding] = useState<{ kind: 'cancel' | 'close'; reason: string } | null>(null);
    const [badPrices, setBadPrices] = useState<string[]>([]);
    const [badTax, setBadTax] = useState(false);
    const [warnings, setWarnings] = useState<Standing[]>([]);
    const money = (minor: number) => format.money(minor, order.currency);
    const qty = (milli: number) => formatMilli(milli, locale);
    const label = (s: string) => t(`inv.po.status.${s}` as MessageKey);
    const dept = (d: string) => t(`inv.dept.${d}` as MessageKey);
    const active = order.lines.filter((l) => l.is_active);
    const number = order.revision > 0 ? `${order.number} ${t('inv.po.rev', { revision: order.revision })}` : order.number;
    const dialogOpen = edit !== null || revise !== null || ending !== null;

    function reset() {
        action.clear();
        setWarnings([]);
        setBadPrices([]);
        setBadTax(false);
    }

    function openEdit() {
        reset();
        setEdit(orderToForm(order, order.currency));
    }

    function openRevise() {
        reset();
        setRevise({
            header: { supplier_id: order.supplier.id, location_id: order.location.id, expected_date: order.expected_date ?? '', tax_percent: bpToInput(order.tax_bp), note: order.note ?? '' },
            reason: '',
            kept: active.map((l) => ({ key: nextLineKey(), line_id: l.id, item_code: l.item_code, item_name: l.item_name, unit: l.unit, received: l.received_qty_milli, quantity: plainMilli(l.qty_milli), price: minorToInput(l.unit_price_minor, order.currency), removed: false })),
            added: [],
        });
    }

    async function save() {
        if (edit === null) return;
        const { bad, lines, taxBp } = orderBody(edit, order.currency);

        setBadPrices(bad);
        setBadTax(taxBp === null);
        if (bad.length > 0 || taxBp === null) return;
        const done = await action.run(`/inventory/orders/${order.id}`, {
            body: { supplier_id: edit.supplier_id, location_id: edit.location_id, expected_date: edit.expected_date || null, tax_bp: taxBp, note: edit.note || null, lines, lock_version: order.lock_version },
            reload,
        });
        if (done !== null) setEdit(null);
    }

    async function submit() {
        reset();
        const done = await action.run<{ order: { budget_warnings: Standing[] } }>(`/inventory/orders/${order.id}/submit`, { body: { lock_version: order.lock_version }, reload });
        if (done !== null) setWarnings(done.order.budget_warnings ?? []);
    }

    async function release() {
        reset();
        await action.run(`/inventory/orders/${order.id}/release`, { reload });
    }

    async function issue() {
        reset();
        await action.run(`/inventory/orders/${order.id}/issue`, { body: { lock_version: order.lock_version }, reload });
    }

    async function saveRevision() {
        if (revise === null) return;
        const bad: string[] = [];
        const kept = revise.kept.filter((k) => !k.removed).map((k) => {
            const price = priceOf(k.price, order.currency);

            if (price.bad) bad.push(k.key);

            return { line_id: k.line_id, quantity: k.quantity, unit_price_minor: price.minor };
        });
        const added = orderBody({ ...revise.header, lines: revise.added }, order.currency);
        const taxBp = parsePercentToBp(revise.header.tax_percent);

        setBadPrices([...bad, ...added.bad]);
        setBadTax(taxBp === null);
        if (bad.length > 0 || added.bad.length > 0 || taxBp === null) return;
        const done = await action.run(`/inventory/orders/${order.id}/revise`, {
            body: {
                supplier_id: revise.header.supplier_id, location_id: revise.header.location_id, expected_date: revise.header.expected_date || null, tax_bp: taxBp, note: revise.header.note || null,
                reason: revise.reason, lock_version: order.lock_version, lines: [...kept, ...added.lines],
            },
            reload,
        });
        if (done !== null) setRevise(null);
    }

    async function finish() {
        if (ending === null) return;
        const body = { reason: ending.reason, lock_version: order.lock_version };
        const done = await action.run(ending.kind === 'cancel' ? `/inventory/orders/${order.id}/cancel` : `/inventory/orders/${order.id}/close`, { body, reload });
        if (done !== null) setEnding(null);
    }

    const setKept = (key: string, patch: Partial<KeptLine>) => revise !== null && setRevise({ ...revise, kept: revise.kept.map((k) => (k.key === key ? { ...k, ...patch } : k)) });
    const facts: [string, string][] = [
        [t('inv.po.supplier'), `${order.supplier.code} · ${order.supplier.name}`],
        [t('inv.col.location'), `${order.location.code} · ${order.location.name}`],
        [t('inv.po.paymentTerms'), t('inv.sup.days', { count: order.payment_terms_days })],
        [t('inv.po.orderDate'), format.date(order.order_date)],
        [t('inv.po.expected'), order.expected_date === null ? '—' : format.date(order.expected_date)],
        [t('inv.po.taxRate'), `${bpToInput(order.tax_bp)}%`],
        [t('inv.po.createdBy'), order.created_by_name ?? '—'],
        [t('inv.po.issuedAt'), order.issued_at === null ? '—' : format.instant(order.issued_at)],
        [t('inv.col.note'), order.note ?? '—'],
    ];

    return (
        <InventoryShell
            actions={<>
                <Button asChild variant="outline"><Link href="/inventory/orders">{t('inv.po.back')}</Link></Button>
                <Button onClick={() => window.print()} type="button" variant="outline">{t('inv.po.print')}</Button>
                {order.may_edit ? <Button onClick={openEdit} type="button" variant="outline">{t('inv.po.edit')}</Button> : null}
                {order.may_revise ? <Button onClick={openRevise} type="button" variant="outline">{t('inv.po.revise')}</Button> : null}
                {order.may_release ? <Button disabled={action.busy} onClick={() => void release()} type="button" variant="outline">{t('inv.req.release')}</Button> : null}
                {order.may_close ? <Button onClick={() => { reset(); setEnding({ kind: 'close', reason: '' }); }} type="button" variant="outline">{t('inv.po.close')}</Button> : null}
                {order.may_cancel ? <Button onClick={() => { reset(); setEnding({ kind: 'cancel', reason: '' }); }} type="button" variant="outline">{t('inv.po.cancel')}</Button> : null}
                {order.may_submit ? <Button loading={action.busy} onClick={() => void submit()} type="button">{t('inv.po.submit')}</Button> : null}
                {order.may_issue ? <Button loading={action.busy} onClick={() => void issue()} type="button">{t('inv.po.issue')}</Button> : null}
            </>}
            description={t('inv.po.detailDescription')}
            title={t('inv.po.detailTitle', { number })}
            wide
        >
            {action.error !== null && !dialogOpen ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {warnings.map((w) => <BudgetWarning currency={order.currency} key={w.department} standing={w} />)}
            {order.pending_revision !== null ? (
                <Alert title={t('inv.po.pendingRevision')} tone="warning">
                    {t('inv.po.pendingRevisionDetail', { reason: order.pending_revision.reason, total: money(order.pending_revision.total_minor) })}
                </Alert>
            ) : null}
            {order.close_reason && (order.status === 'cancelled' || order.status === 'closed') ? <Alert title={t(order.status === 'cancelled' ? 'inv.po.cancelledNote' : 'inv.po.closedNote')} tone="info">{order.close_reason}</Alert> : null}

            <section aria-labelledby="po-details-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="po-details-h">{t('inv.po.details')}</h2>
                    <StatusBadge label={label(order.status)} tone={ORDER_TONE[order.status] ?? 'neutral'} />
                </div>
                <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-3" data-testid="order-details">
                    {facts.map(([name, value]) => (
                        <div key={name}>
                            <dt className="text-xs text-muted-foreground">{name}</dt>
                            <dd className="break-words">{value}</dd>
                        </div>
                    ))}
                </dl>
            </section>

            <section aria-labelledby="po-lines-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="po-lines-h">{t('inv.po.linesHeading')}</h2>
                {active.length === 0 ? <EmptyState title={t('inv.req.noLines')} /> : (
                    <div className="border border-border bg-surface">
                        <Table data-testid="order-lines">
                            <TableHeader>
                                <TableRow>
                                    <TableHead scope="col">#</TableHead>
                                    <TableHead scope="col">{t('inv.col.item')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.opening.quantity')}</TableHead>
                                    <TableHead scope="col">{t('inv.col.unit')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.po.price')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.req.lineTotal')}</TableHead>
                                    <TableHead scope="col">{t('inv.col.department')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.po.received')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.po.rejected')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.po.remaining')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {active.map((l) => (
                                    <TableRow key={l.id}>
                                        <TableCell>{l.line_no}</TableCell>
                                        <TableCell><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span></TableCell>
                                        <TableCell className="text-right">{qty(l.qty_milli)}</TableCell>
                                        <TableCell>{l.unit}</TableCell>
                                        <TableCell className="text-right">{money(l.unit_price_minor)}</TableCell>
                                        <TableCell className="text-right">{money(l.line_total_minor)}</TableCell>
                                        <TableCell>{l.department === null ? '—' : dept(l.department)}</TableCell>
                                        <TableCell className="text-right">{qty(l.received_qty_milli)}</TableCell>
                                        <TableCell className="text-right">{qty(l.rejected_qty_milli)}</TableCell>
                                        <TableCell className="text-right">{qty(Math.max(0, l.qty_milli - l.received_qty_milli))}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell className="text-right" colSpan={5}>{t('inv.po.subtotal')}</TableCell>
                                    <TableCell className="text-right" data-testid="order-subtotal">{money(order.subtotal_minor)}</TableCell>
                                    <TableCell colSpan={4} />
                                </TableRow>
                                <TableRow>
                                    <TableCell className="text-right" colSpan={5}>{t('inv.po.tax', { rate: bpToInput(order.tax_bp) })}</TableCell>
                                    <TableCell className="text-right" data-testid="order-tax">{money(order.tax_minor)}</TableCell>
                                    <TableCell colSpan={4} />
                                </TableRow>
                                <TableRow>
                                    <TableCell className="text-right font-semibold" colSpan={5}>{t('inv.req.total')}</TableCell>
                                    <TableCell className="text-right font-semibold" data-testid="order-total">{money(order.total_minor)}</TableCell>
                                    <TableCell colSpan={4} />
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                )}
            </section>

            {order.approval !== null ? (
                <section aria-labelledby="po-approval-h" className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold" id="po-approval-h">{t('inv.po.apr.heading')}</h2>
                    <ApprovalProgress approval={order.approval} />
                </section>
            ) : null}

            {order.budgets.length > 0 ? (
                <section aria-labelledby="po-budget-h" className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold" id="po-budget-h">{t('inv.po.bud.heading')}</h2>
                    <div className="border border-border bg-surface">
                        <Table data-testid="order-budgets">
                            <TableHeader>
                                <TableRow>
                                    <TableHead scope="col">{t('inv.col.department')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.po.bud.budget')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.po.bud.committed')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.po.bud.thisOrder')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.po.bud.after')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {order.budgets.map((b) => {
                                    const after = b.remaining_minor === null ? null : b.remaining_minor - b.this_order_minor;

                                    return (
                                        <TableRow key={b.department}>
                                            <TableCell>{dept(b.department)} <span className="text-muted-foreground">· {b.period}</span></TableCell>
                                            <TableCell className="text-right">{b.budget_minor === null ? t('inv.po.bud.none') : money(b.budget_minor)}</TableCell>
                                            <TableCell className="text-right">{money(b.committed_minor)}</TableCell>
                                            <TableCell className="text-right">{money(b.this_order_minor)}</TableCell>
                                            <TableCell className={after !== null && after < 0 ? 'text-right font-semibold text-danger' : 'text-right'}>{after === null ? '—' : money(after)}</TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            ) : null}

            <section aria-labelledby="po-revisions-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="po-revisions-h">{t('inv.po.revisions')}</h2>
                {order.revisions.length === 0 ? <EmptyState title={t('inv.po.noRevisions')} /> : (
                    <div className="border border-border bg-surface">
                        <Table data-testid="order-revisions">
                            <TableHeader>
                                <TableRow>
                                    <TableHead scope="col">{t('inv.po.revision')}</TableHead>
                                    <TableHead scope="col">{t('inv.col.reason')}</TableHead>
                                    <TableHead scope="col">{t('inv.po.by')}</TableHead>
                                    <TableHead scope="col">{t('inv.sup.col.when')}</TableHead>
                                    <TableHead className="text-right" scope="col">{t('inv.req.total')}</TableHead>
                                    <TableHead scope="col">{t('inv.po.neededApproval')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {order.revisions.map((r) => (
                                    <TableRow key={r.revision}>
                                        <TableCell>{r.revision}</TableCell>
                                        <TableCell>{r.reason}</TableCell>
                                        <TableCell>{r.created_by_name ?? '—'}</TableCell>
                                        <TableCell>{r.created_at === null ? '—' : format.instant(r.created_at)}</TableCell>
                                        <TableCell className="text-right">{money(r.total_minor)}</TableCell>
                                        <TableCell>{t(r.needed_approval ? 'inv.po.yes' : 'inv.po.no')}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </section>

            <Dialog
                className="w-[min(64rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={() => setEdit(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setEdit(null)}
                open={edit !== null}
                title={t('inv.po.edit')}
            >
                {edit !== null && (
                    <div className="flex flex-col gap-4">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <OrderHeaderFields badTax={badTax} fieldError={action.fieldError} form={edit} locations={order.locations} onChange={(patch) => setEdit({ ...edit, ...patch })} suppliers={order.suppliers} />
                        <OrderLineFields badPrices={badPrices} currency={order.currency} departments={order.departments} fieldError={action.fieldError} form={edit} items={order.items} onChange={setEdit} requestLines={[]} />
                    </div>
                )}
            </Dialog>

            <Dialog
                className="w-[min(64rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={() => setRevise(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void saveRevision()} type="button">{t('inv.po.reviseConfirm')}</Button>
                </>}
                onClose={() => setRevise(null)}
                open={revise !== null}
                title={t('inv.po.revise')}
            >
                {revise !== null && (
                    <div className="flex flex-col gap-4">
                        <p className="text-sm text-muted-foreground">{t('inv.po.reviseHint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('reason')} field="reason" label={t('inv.po.reviseReason')}>
                            <Input maxLength={200} onChange={(e) => setRevise({ ...revise, reason: e.target.value })} value={revise.reason} />
                        </FormField>
                        <OrderHeaderFields badTax={badTax} fieldError={action.fieldError} form={revise.header} locations={order.locations} onChange={(patch) => setRevise({ ...revise, header: { ...revise.header, ...patch } })} suppliers={order.suppliers} />
                        <div className="flex flex-col gap-3">
                            <h3 className="text-sm font-semibold">{t('inv.po.currentLines')}</h3>
                            {action.fieldError('lines') ? <p className="text-sm text-danger">{action.fieldError('lines')}</p> : null}
                            {revise.kept.map((k) => (
                                <div className={k.removed ? 'grid gap-3 border-t border-border pt-3 opacity-60 sm:grid-cols-[minmax(0,2fr)_8rem_10rem_auto]' : 'grid gap-3 border-t border-border pt-3 sm:grid-cols-[minmax(0,2fr)_8rem_10rem_auto]'} data-testid="revise-line" key={k.key}>
                                    <div className="self-end pb-2 text-sm">
                                        <span className="font-medium">{k.item_code}</span> <span className="text-muted-foreground">{k.item_name}</span>
                                        {k.received > 0 ? <span className="block text-xs text-muted-foreground">{t('inv.po.alreadyReceived', { quantity: qty(k.received), unit: k.unit })}</span> : null}
                                    </div>
                                    <FormField field="lines.*.quantity" label={t('inv.po.quantityIn', { unit: k.unit })} required>
                                        <Input disabled={k.removed} inputMode="decimal" onChange={(e) => setKept(k.key, { quantity: e.target.value })} value={k.quantity} />
                                    </FormField>
                                    <FormField error={badPrices.includes(k.key) ? t('fo.folio.invalidAmount') : undefined} field="lines.*.unit_price_minor" label={t('inv.po.unitPrice', { currency: order.currency })}>
                                        <Input disabled={k.removed} inputMode="decimal" onChange={(e) => setKept(k.key, { price: e.target.value })} value={k.price} />
                                    </FormField>
                                    <div className="flex items-end">
                                        <Button disabled={k.received > 0} onClick={() => setKept(k.key, { removed: !k.removed })} size="sm" type="button" variant="outline">{t(k.removed ? 'inv.po.keepLine' : 'inv.po.dropLine')}</Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <OrderLineFields
                            badPrices={badPrices}
                            currency={order.currency}
                            departments={order.departments}
                            fieldError={() => undefined}
                            form={{ ...revise.header, lines: revise.added }}
                            heading={t('inv.po.newLines')}
                            hint={t('inv.po.newLinesHint')}
                            items={order.items}
                            minLines={0}
                            onChange={(next) => setRevise({ ...revise, added: next.lines })}
                            requestLines={[]}
                        />
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setEnding(null)} type="button" variant="outline">{t('inv.trf.back')}</Button>
                    <Button loading={action.busy} onClick={() => void finish()} type="button">{ending?.kind === 'close' ? t('inv.po.closeConfirm') : t('inv.po.cancelConfirm')}</Button>
                </>}
                onClose={() => setEnding(null)}
                open={ending !== null}
                title={ending?.kind === 'close' ? t('inv.po.close') : t('inv.po.cancel')}
            >
                {ending !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t(ending.kind === 'close' ? 'inv.po.closeHint' : 'inv.po.cancelHint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('reason')} field="reason" label={t('inv.col.reason')}>
                            <Input maxLength={200} onChange={(e) => setEnding({ ...ending, reason: e.target.value })} value={ending.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
