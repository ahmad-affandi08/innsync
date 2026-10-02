import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli, plainMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Receipt = {
    id: string; number: string; order: { id: string; number: string; status: string }; supplier: { id: string; code: string; name: string }; location: { id: string; code: string; name: string };
    delivery_note: string | null; received_on: string; value_minor: number; received_by_name: string | null; created_at: string;
};
type OrderLine = { id: string; item_code: string; item_name: string; unit: string; qty_milli: number; received_qty_milli: number; rejected_qty_milli: number; remaining_milli: number; unit_price_minor: number };
type Receivable = { id: string; number: string; revision: number; supplier_name: string; location_id: string; expected_date: string | null; lines: OrderLine[] };
type Overview = {
    currency: string; receipts: Receipt[]; receivable: Receivable[]; locations: { id: string; code: string; name: string }[]; conditions: string[]; rejection_reasons: string[]; over_receipt_bp: number; may: { post: boolean };
};
type LineEntry = { accepted: string; rejected: string; reason: string; condition: string; note: string; expires: string };
type Form = { orderId: string; locationId: string; deliveryNote: string; note: string; lines: Record<string, LineEntry> };

const blankEntry = (): LineEntry => ({ accepted: '', rejected: '', reason: '', condition: 'good', note: '', expires: '' });
const emptyToNull = (s: string) => (s.trim() === '' ? null : s.trim());

export default function ReceiptsPage({ overview, order }: { overview: Overview; order: string }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const money = (minor: number) => format.money(minor, overview.currency);
    const qty = (n: number) => formatMilli(n, locale);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(form)]);
    const chosen = form === null ? null : overview.receivable.find((o) => o.id === form.orderId) ?? null;

    function chooseOrder(orderId: string, base: Form | null) {
        const next = overview.receivable.find((o) => o.id === orderId);
        setForm({
            orderId, locationId: next?.location_id ?? base?.locationId ?? overview.locations[0]?.id ?? '', deliveryNote: base?.deliveryNote ?? '', note: base?.note ?? '',
            lines: Object.fromEntries((next?.lines ?? []).map((l) => [l.id, blankEntry()])),
        });
    }

    function openNew() {
        action.clear();
        chooseOrder(overview.receivable[0]?.id ?? '', null);
    }

    function setLine(id: string, patch: Partial<LineEntry>) {
        if (form === null) return;
        setForm({ ...form, lines: { ...form.lines, [id]: { ...(form.lines[id] ?? blankEntry()), ...patch } } });
    }

    async function save() {
        if (form === null || chosen === null) return;
        const lines = chosen.lines
            .filter((l) => (form.lines[l.id]?.accepted.trim() ?? '') !== '' || (form.lines[l.id]?.rejected.trim() ?? '') !== '')
            .map((l) => {
                const e = form.lines[l.id] ?? blankEntry();

                return { order_line_id: l.id, accepted: emptyToNull(e.accepted), rejected: emptyToNull(e.rejected), condition: e.condition, rejection_reason: emptyToNull(e.reason), note: emptyToNull(e.note), expires_on: emptyToNull(e.expires) };
            });
        const done = await action.run<{ receipt: { id: string } }>('/inventory/receipts', {
            body: { order_id: form.orderId, location_id: form.locationId || null, delivery_note: emptyToNull(form.deliveryNote), note: emptyToNull(form.note), lines },
            idempotencyKey: intent,
        });
        if (done !== null) router.visit(`/inventory/receipts/${done.receipt.id}`);
    }

    const columns: DataGridColumn<Receipt>[] = [
        { id: 'number', label: t('inv.col.number'), value: (r) => r.number, rowHeader: true },
        { id: 'order', label: t('inv.rcv.col.order'), value: (r) => r.order.number },
        { id: 'supplier', label: t('inv.rcv.col.supplier'), value: (r) => r.supplier.name, searchText: (r) => `${r.supplier.code} ${r.supplier.name}`, filter: 'select' },
        { id: 'location', label: t('inv.col.location'), value: (r) => r.location.name, searchText: (r) => `${r.location.code} ${r.location.name}`, filter: 'select' },
        { id: 'note', label: t('inv.rcv.col.deliveryNote'), value: (r) => r.delivery_note ?? '', cell: (r) => r.delivery_note ?? '—' },
        { id: 'date', label: t('inv.col.date'), value: (r) => r.received_on, cell: (r) => format.date(r.received_on) },
        { id: 'value', label: t('inv.col.value'), align: 'right', value: (r) => r.value_minor, cell: (r) => money(r.value_minor) },
        { id: 'by', label: t('inv.rcv.col.receivedBy'), value: (r) => r.received_by_name ?? '', cell: (r) => r.received_by_name ?? '—', hidden: true },
        { id: 'actions', label: t('inv.col.actions'), cell: (r) => <Button onClick={() => router.visit(`/inventory/receipts/${r.id}`)} size="sm" type="button" variant="outline">{t('inv.rcv.open')}</Button> },
    ];

    return (
        <InventoryShell actions={overview.may.post ? <Button onClick={openNew} type="button">{t('inv.rcv.new')}</Button> : undefined} description={t('inv.rcv.description')} title={t('inv.rcv.title')} wide>
            {order !== '' ? <Alert actions={<Button onClick={() => router.get('/inventory/receipts')} size="sm" type="button" variant="outline">{t('inv.rcv.showAll')}</Button>} title={t('inv.rcv.filtered')} tone="info" /> : null}

            <DataGrid caption={t('inv.rcv.title')} columns={columns} empty={<EmptyState title={t('inv.rcv.empty')} />} getRowId={(r) => r.id} id="inv.receipts" rows={overview.receipts} testId="receipts" />

            <Dialog
                className="w-[min(64rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button disabled={chosen === null} loading={action.busy} onClick={() => void save()} type="button">{t('inv.rcv.post')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.rcv.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('inv.rcv.hint', { percent: format.number(overview.over_receipt_bp / 100) })}</p>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        {overview.receivable.length === 0 ? <div className="sm:col-span-2"><Alert title={t('inv.rcv.nothingToReceive')} tone="info" /></div> : null}
                        <FormField error={action.fieldError('order_id')} field="order_id" label={t('inv.rcv.col.order')}>
                            <Select onChange={(e) => chooseOrder(e.target.value, form)} value={form.orderId}>
                                {overview.receivable.map((o) => <option key={o.id} value={o.id}>{o.number} · {o.supplier_name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('location_id')} field="location_id" label={t('inv.col.location')}>
                            <Select onChange={(e) => setForm({ ...form, locationId: e.target.value })} value={form.locationId}>
                                {overview.locations.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('delivery_note')} field="delivery_note" label={t('inv.rcv.col.deliveryNote')}>
                            <Input maxLength={40} onChange={(e) => setForm({ ...form, deliveryNote: e.target.value })} value={form.deliveryNote} />
                        </FormField>
                        <FormField error={action.fieldError('note')} field="note" label={t('inv.col.note')}>
                            <Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                        </FormField>

                        {chosen !== null && (
                            <div className="flex flex-col gap-2 sm:col-span-2">
                                {action.fieldError('lines') !== undefined ? <p className="text-sm text-danger">{action.fieldError('lines')}</p> : null}
                                <div className="overflow-x-auto border border-border bg-surface">
                                    <Table data-testid="receive-lines">
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>{t('inv.col.item')}</TableHead>
                                                <TableHead className="text-right">{t('inv.rcv.ordered')}</TableHead>
                                                <TableHead className="text-right">{t('inv.rcv.receivedSoFar')}</TableHead>
                                                <TableHead className="text-right">{t('inv.rcv.remaining')}</TableHead>
                                                <TableHead>{t('inv.rcv.accepted')}</TableHead>
                                                <TableHead>{t('inv.rcv.refused')}</TableHead>
                                                <TableHead>{t('inv.rcv.refusalReason')}</TableHead>
                                                <TableHead>{t('inv.rcv.condition')}</TableHead>
                                                <TableHead>{t('inv.rcv.lineNote')}</TableHead>
                                                <TableHead>{t('inv.rcv.expiry')}</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {chosen.lines.map((l) => {
                                                const e = form.lines[l.id] ?? blankEntry();
                                                const refused = e.rejected.trim() !== '' && e.rejected.trim() !== '0';

                                                return (
                                                    <TableRow data-testid={`receive-line-${l.item_code}`} key={l.id}>
                                                        <TableCell><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span></TableCell>
                                                        <TableCell className="text-right">{qty(l.qty_milli)} {l.unit}</TableCell>
                                                        <TableCell className="text-right">{qty(l.received_qty_milli)} {l.unit}</TableCell>
                                                        <TableCell className="text-right">{qty(l.remaining_milli)} {l.unit}</TableCell>
                                                        <TableCell>
                                                            <div className="flex items-start gap-1">
                                                                <FormField field="lines.*.accepted" label={<span className="sr-only">{t('inv.rcv.accepted')} {l.item_code}</span>}>
                                                                    <Input className="w-24" inputMode="decimal" onChange={(ev) => setLine(l.id, { accepted: ev.target.value })} value={e.accepted} />
                                                                </FormField>
                                                                {l.remaining_milli > 0 ? <Button aria-label={`${t('inv.rcv.fillRemaining')} ${l.item_code}`} onClick={() => setLine(l.id, { accepted: plainMilli(l.remaining_milli) })} size="sm" title={t('inv.rcv.fillRemaining')} type="button" variant="outline">{t('inv.rcv.fill')}</Button> : null}
                                                            </div>
                                                        </TableCell>
                                                        <TableCell>
                                                            <FormField field="lines.*.rejected" label={<span className="sr-only">{t('inv.rcv.refused')} {l.item_code}</span>}>
                                                                <Input className="w-24" inputMode="decimal" onChange={(ev) => setLine(l.id, { rejected: ev.target.value })} value={e.rejected} />
                                                            </FormField>
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="w-40">
                                                                <FormField field="lines.*.rejection_reason" label={<span className="sr-only">{t('inv.rcv.refusalReason')} {l.item_code}</span>} required={refused}>
                                                                    <Select disabled={!refused} onChange={(ev) => setLine(l.id, { reason: ev.target.value })} searchable={false} value={e.reason}>
                                                                        <option value="">—</option>
                                                                        {overview.rejection_reasons.map((r) => <option key={r} value={r}>{t(`inv.rcv.reason.${r}` as MessageKey)}</option>)}
                                                                    </Select>
                                                                </FormField>
                                                            </div>
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="w-36">
                                                                <FormField field="lines.*.condition" label={<span className="sr-only">{t('inv.rcv.condition')} {l.item_code}</span>}>
                                                                    <Select onChange={(ev) => setLine(l.id, { condition: ev.target.value })} searchable={false} value={e.condition}>
                                                                        {overview.conditions.map((c) => <option key={c} value={c}>{t(`inv.rcv.condition.${c}` as MessageKey)}</option>)}
                                                                    </Select>
                                                                </FormField>
                                                            </div>
                                                        </TableCell>
                                                        <TableCell>
                                                            <FormField field="lines.*.note" label={<span className="sr-only">{t('inv.rcv.lineNote')} {l.item_code}</span>}>
                                                                <Input className="min-w-36" maxLength={200} onChange={(ev) => setLine(l.id, { note: ev.target.value })} value={e.note} />
                                                            </FormField>
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="w-44">
                                                                <FormField field="lines.*.expires_on" label={<span className="sr-only">{t('inv.rcv.expiry')} {l.item_code}</span>}>
                                                                    <DatePicker onChange={(ev) => setLine(l.id, { expires: ev.target.value })} value={e.expires} />
                                                                </FormField>
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>
                                <p className="text-xs text-muted-foreground">{t('inv.rcv.linesHint')}</p>
                            </div>
                        )}
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
