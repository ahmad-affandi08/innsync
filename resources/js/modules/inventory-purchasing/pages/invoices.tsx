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
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { minorToInput } from '@/modules/inventory-purchasing/lib/amounts';
import { formatMilli, plainMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Invoice = {
    id: string; number: string; invoice_number: string; status: string; invoice_date: string; due_date: string; order: { id: string; number: string }; supplier: { id: string; code: string; name: string };
    total_minor: number; variance_count: number; entered_by_name: string | null; lock_version: number;
};
type InvoiceableLine = { id: string; item_code: string; item_name: string; unit: string; open_milli: number; unit_price_minor: number };
type Invoiceable = { id: string; number: string; supplier_name: string; tax_bp: number; payment_terms_days: number; lines: InvoiceableLine[] };
type Overview = { currency: string; invoices: Invoice[]; invoiceable: Invoiceable[]; tolerances: { price_bp: number; qty_bp: number }; may: { record: boolean; resolve: boolean } };
type LineEntry = { quantity: string; price: string };
type Form = { orderId: string; invoiceNumber: string; invoiceDate: string; taxNumber: string; note: string; tax: string; total: string; lines: Record<string, LineEntry> };

const INVOICE_TONE: Record<string, StatusTone> = { matched: 'success', variance: 'warning', approved: 'success', rejected: 'danger' };
const STATUSES = ['matched', 'variance', 'approved', 'rejected'] as const;
const CELL = '[&_[data-required]]:hidden';
const emptyToNull = (s: string) => (s.trim() === '' ? null : s.trim());

export default function InvoicesPage({ overview, status }: { overview: Overview; status: string }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const [amountError, setAmountError] = useState(false);
    const money = (minor: number) => format.money(minor, overview.currency);
    const qty = (n: number) => formatMilli(n, locale);
    const label = (s: string) => t(`inv.inv.status.${s}` as MessageKey);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(form)]);
    const chosen = form === null ? null : overview.invoiceable.find((o) => o.id === form.orderId) ?? null;

    function chooseOrder(orderId: string, base: Form | null) {
        const next = overview.invoiceable.find((o) => o.id === orderId);
        setAmountError(false);
        setForm({
            orderId, invoiceNumber: base?.invoiceNumber ?? '', invoiceDate: base?.invoiceDate ?? '', taxNumber: base?.taxNumber ?? '', note: base?.note ?? '', tax: base?.tax ?? '', total: base?.total ?? '',
            lines: Object.fromEntries((next?.lines ?? []).map((l) => [l.id, { quantity: plainMilli(l.open_milli), price: minorToInput(l.unit_price_minor, overview.currency) }])),
        });
    }

    function openNew() {
        action.clear();
        chooseOrder(overview.invoiceable[0]?.id ?? '', null);
    }

    function setLine(id: string, patch: Partial<LineEntry>) {
        if (form === null) return;
        setForm({ ...form, lines: { ...form.lines, [id]: { ...(form.lines[id] ?? { quantity: '', price: '' }), ...patch } } });
    }

    async function save() {
        if (form === null || chosen === null) return;
        const tax = parseMajorToMinor(form.tax, overview.currency);
        const total = parseMajorToMinor(form.total, overview.currency);
        const entered = chosen.lines.filter((l) => (form.lines[l.id]?.quantity.trim() ?? '') !== '');
        const prices = entered.map((l) => parseMajorToMinor(form.lines[l.id]?.price ?? '', overview.currency));
        if (tax === null || total === null || prices.some((p) => p === null)) { setAmountError(true); return; }
        setAmountError(false);
        const lines = entered.map((l, i) => ({ order_line_id: l.id, quantity: (form.lines[l.id]?.quantity ?? '').trim(), unit_price_minor: prices[i] as number }));
        const done = await action.run<{ invoice: { id: string } }>('/inventory/invoices', {
            body: { order_id: form.orderId, invoice_number: form.invoiceNumber.trim(), invoice_date: form.invoiceDate, tax_number: emptyToNull(form.taxNumber), tax_minor: tax, total_minor: total, note: emptyToNull(form.note), lines },
            idempotencyKey: intent,
        });
        if (done !== null) router.visit(`/inventory/invoices/${done.invoice.id}`);
    }

    const columns: DataGridColumn<Invoice>[] = [
        { id: 'number', label: t('inv.col.number'), value: (i) => i.number, rowHeader: true },
        { id: 'supplierNumber', label: t('inv.inv.col.supplierNumber'), value: (i) => i.invoice_number },
        { id: 'supplier', label: t('inv.rcv.col.supplier'), value: (i) => i.supplier.name, searchText: (i) => `${i.supplier.code} ${i.supplier.name}`, filter: 'select' },
        { id: 'order', label: t('inv.rcv.col.order'), value: (i) => i.order.number },
        { id: 'date', label: t('inv.inv.col.invoiceDate'), value: (i) => i.invoice_date, cell: (i) => format.date(i.invoice_date) },
        { id: 'due', label: t('inv.inv.col.dueDate'), value: (i) => i.due_date, cell: (i) => format.date(i.due_date) },
        { id: 'total', label: t('inv.inv.col.total'), align: 'right', value: (i) => i.total_minor, cell: (i) => money(i.total_minor) },
        { id: 'state', label: t('inv.col.status'), value: (i) => i.status, filter: 'select', filterLabel: label, cell: (i) => <StatusBadge label={label(i.status)} tone={INVOICE_TONE[i.status] ?? 'neutral'} /> },
        { id: 'differences', label: t('inv.inv.col.differences'), align: 'right', value: (i) => i.variance_count },
        { id: 'by', label: t('inv.inv.col.enteredBy'), value: (i) => i.entered_by_name ?? '', cell: (i) => i.entered_by_name ?? '—', hidden: true },
        { id: 'actions', label: t('inv.col.actions'), cell: (i) => <Button onClick={() => router.visit(`/inventory/invoices/${i.id}`)} size="sm" type="button" variant="outline">{t('inv.inv.open')}</Button> },
    ];

    return (
        <InventoryShell actions={overview.may.record ? <Button onClick={openNew} type="button">{t('inv.inv.new')}</Button> : undefined} description={t('inv.inv.description')} title={t('inv.inv.title')} wide>
            <div className="max-w-xs">
                <Select aria-label={t('inv.col.status')} onChange={(e) => router.get('/inventory/invoices', e.target.value ? { status: e.target.value } : {}, { preserveScroll: true })} searchable={false} value={status}>
                    <option value="">{t('inv.inv.allStatuses')}</option>
                    {STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                </Select>
            </div>

            <DataGrid caption={t('inv.inv.title')} columns={columns} empty={<EmptyState title={t('inv.inv.empty')} />} getRowId={(i) => i.id} id="inv.invoices" rows={overview.invoices} testId="invoices" />

            <Dialog
                className="w-[min(56rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button disabled={chosen === null} loading={action.busy} onClick={() => void save()} type="button">{t('inv.inv.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.inv.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('inv.inv.hint')}</p>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        {overview.invoiceable.length === 0 ? <div className="sm:col-span-2"><Alert title={t('inv.inv.nothingToInvoice')} tone="info" /></div> : null}
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('order_id')} field="order_id" label={t('inv.rcv.col.order')}>
                                <Select onChange={(e) => chooseOrder(e.target.value, form)} value={form.orderId}>
                                    {overview.invoiceable.map((o) => <option key={o.id} value={o.id}>{o.number} · {o.supplier_name}</option>)}
                                </Select>
                            </FormField>
                        </div>
                        <FormField error={action.fieldError('invoice_number')} field="invoice_number" label={t('inv.inv.col.supplierNumber')}>
                            <Input maxLength={40} onChange={(e) => setForm({ ...form, invoiceNumber: e.target.value })} value={form.invoiceNumber} />
                        </FormField>
                        <FormField error={action.fieldError('invoice_date')} field="invoice_date" label={t('inv.inv.col.invoiceDate')}>
                            <DatePicker onChange={(e) => setForm({ ...form, invoiceDate: e.target.value })} value={form.invoiceDate} />
                        </FormField>
                        <FormField error={action.fieldError('tax_number')} field="tax_number" hint={t('inv.inv.taxNumberHint')} label={t('inv.inv.taxNumber')}>
                            <Input inputMode="numeric" maxLength={24} onChange={(e) => setForm({ ...form, taxNumber: e.target.value })} value={form.taxNumber} />
                        </FormField>
                        <FormField error={action.fieldError('note')} field="note" label={t('inv.col.note')}>
                            <Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                        </FormField>

                        {chosen !== null && (
                            <>
                                <div className="flex flex-col gap-2 sm:col-span-2">
                                    {action.fieldError('lines') !== undefined ? <p className="text-sm text-danger">{action.fieldError('lines')}</p> : null}
                                    <div className="overflow-x-auto border border-border bg-surface">
                                        <Table data-testid="invoice-lines">
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead>{t('inv.col.item')}</TableHead>
                                                    <TableHead className="text-right">{t('inv.inv.openQty')}</TableHead>
                                                    <TableHead className="text-right">{t('inv.inv.orderPrice')}</TableHead>
                                                    <TableHead>{t('inv.inv.invoiceQty')} *</TableHead>
                                                    <TableHead>{t('inv.inv.invoicePrice')} *</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {chosen.lines.map((l) => {
                                                    const e = form.lines[l.id] ?? { quantity: '', price: '' };

                                                    return (
                                                        <TableRow data-testid={`invoice-line-${l.item_code}`} key={l.id}>
                                                            <TableCell><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span></TableCell>
                                                            <TableCell className="text-right">{qty(l.open_milli)} {l.unit}</TableCell>
                                                            <TableCell className="text-right">{money(l.unit_price_minor)}</TableCell>
                                                            <TableCell>
                                                                <FormField className={CELL} field="lines.*.quantity" label={<span className="sr-only">{t('inv.inv.invoiceQty')} {l.item_code}</span>}>
                                                                    <Input className="w-28" inputMode="decimal" onChange={(ev) => setLine(l.id, { quantity: ev.target.value })} value={e.quantity} />
                                                                </FormField>
                                                            </TableCell>
                                                            <TableCell>
                                                                <FormField className={CELL} field="lines.*.unit_price_minor" label={<span className="sr-only">{t('inv.inv.invoicePrice')} {l.item_code}</span>}>
                                                                    <MoneyInput className="w-32" onChange={(ev) => setLine(l.id, { price: ev.target.value })} value={e.price} />
                                                                </FormField>
                                                            </TableCell>
                                                        </TableRow>
                                                    );
                                                })}
                                            </TableBody>
                                        </Table>
                                    </div>
                                    <p className="text-xs text-muted-foreground">{t('inv.inv.linesHint')}</p>
                                </div>
                                <FormField error={action.fieldError('tax_minor')} field="tax_minor" hint={t('inv.inv.taxHint', { percent: format.number(chosen.tax_bp / 100) })} label={t('inv.inv.taxAmount')}>
                                    <MoneyInput onChange={(e) => setForm({ ...form, tax: e.target.value })} value={form.tax} />
                                </FormField>
                                <FormField error={action.fieldError('total_minor')} field="total_minor" hint={t('inv.inv.totalHint')} label={t('inv.inv.statedTotal')}>
                                    <MoneyInput onChange={(e) => setForm({ ...form, total: e.target.value })} value={form.total} />
                                </FormField>
                                {amountError ? <p className="text-sm text-danger sm:col-span-2" role="alert">{t('fo.folio.invalidAmount')}</p> : null}
                            </>
                        )}
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
