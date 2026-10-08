import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli, plainMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Return = {
    id: string; number: string; reason: string; receipt: { id: string; number: string }; supplier: { id: string; code: string; name: string };
    value_minor: number; returned_by_name: string | null; business_date: string; created_at: string;
};
type ReturnableLine = { id: string; item_code: string; item_name: string; unit: string; accepted_milli: number; returnable_milli: number; unit_price_minor: number };
type Returnable = { id: string; number: string; supplier_name: string; location_name: string; received_on: string; lines: ReturnableLine[] };
type Overview = { currency: string; returns: Return[]; returnable: Returnable[]; reasons: string[]; may: { post: boolean } };
type Form = { receipt_id: string; reason: string; note: string; credit_note_number: string; credit_tax: string; quantities: Record<string, string> };

/** Goods returned to a supplier, always against the receipt they came on (FR-INV-011). */
export default function ReturnsPage({ overview }: { overview: Overview }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const [taxError, setTaxError] = useState(false);
    const [noLines, setNoLines] = useState(false);
    const money = (minor: number) => format.money(minor, overview.currency);
    const qty = (n: number) => formatMilli(n, locale);
    const reasonLabel = (r: string) => t(`inv.ret.reason.${r}` as MessageKey);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(form)]);
    const receipt = form === null ? null : overview.returnable.find((r) => r.id === form.receipt_id) ?? null;

    function openNew() {
        action.clear();
        setTaxError(false);
        setNoLines(false);
        setForm({ receipt_id: overview.returnable[0]?.id ?? '', reason: overview.reasons[0] ?? 'damaged', note: '', credit_note_number: '', credit_tax: '', quantities: {} });
    }

    async function post() {
        if (form === null) return;
        const tax = form.credit_tax.trim() === '' ? null : parseMajorToMinor(form.credit_tax, overview.currency);
        const lines = (receipt?.lines ?? []).filter((l) => (form.quantities[l.id] ?? '').trim() !== '').map((l) => ({ receipt_line_id: l.id, quantity: (form.quantities[l.id] ?? '').trim() }));
        setTaxError(form.credit_tax.trim() !== '' && tax === null);
        setNoLines(lines.length === 0);
        if ((form.credit_tax.trim() !== '' && tax === null) || lines.length === 0) return;
        const done = await action.run<{ return: { id: string } }>('/inventory/returns', {
            body: { receipt_id: form.receipt_id, reason: form.reason, note: form.note || null, credit_note_number: form.credit_note_number || null, credit_tax_minor: tax, lines },
            idempotencyKey: intent,
        });
        if (done !== null) router.visit(`/inventory/returns/${done.return.id}`);
    }

    const columns: DataGridColumn<Return>[] = [
        { id: 'number', label: t('inv.col.number'), value: (r) => r.number, rowHeader: true },
        { id: 'receipt', label: t('inv.ret.col.receipt'), value: (r) => r.receipt.number },
        { id: 'supplier', label: t('inv.po.supplier'), value: (r) => r.supplier.code, searchText: (r) => `${r.supplier.code} ${r.supplier.name}`, filter: 'select', cell: (r) => r.supplier.name },
        { id: 'reason', label: t('inv.ret.col.reason'), value: (r) => r.reason, filter: 'select', filterLabel: reasonLabel, cell: (r) => reasonLabel(r.reason) },
        { id: 'date', label: t('inv.col.date'), value: (r) => r.business_date, cell: (r) => format.date(r.business_date) },
        { id: 'value', label: t('inv.col.value'), align: 'right', value: (r) => r.value_minor, cell: (r) => <span className="font-medium">{money(r.value_minor)}</span> },
        { id: 'by', label: t('inv.ret.col.returnedBy'), value: (r) => r.returned_by_name ?? '', cell: (r) => r.returned_by_name ?? '—', hidden: true },
        { id: 'actions', label: t('inv.col.actions'), cell: (r) => <Button onClick={() => router.visit(`/inventory/returns/${r.id}`)} size="sm" type="button" variant="outline">{t('inv.ret.view')}</Button> },
    ];

    return (
        <InventoryShell actions={overview.may.post ? <Button onClick={openNew} type="button">{t('inv.ret.new')}</Button> : undefined} description={t('inv.ret.description')} title={t('inv.ret.title')} wide>
            <DataGrid caption={t('inv.ret.title')} columns={columns} empty={<EmptyState title={t('inv.ret.empty')} />} getRowId={(r) => r.id} id="inv.returns" rowTestId={(r) => `return-${r.number}`} rows={overview.returns} testId="returns" />

            <Dialog
                className="w-[min(52rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button disabled={overview.returnable.length === 0} loading={action.busy} onClick={() => void post()} type="button">{t('inv.ret.submit')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.ret.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{overview.returnable.length === 0 ? t('inv.ret.none') : t('inv.ret.hint')}</p>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('receipt_id')} field="receipt_id" label={t('inv.ret.f.receipt')}>
                            <Select onChange={(e) => setForm({ ...form, receipt_id: e.target.value, quantities: {} })} value={form.receipt_id}>
                                {overview.returnable.map((r) => <option key={r.id} value={r.id}>{r.number} · {r.supplier_name} · {format.date(r.received_on)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('inv.ret.f.reason')}>
                            <Select onChange={(e) => setForm({ ...form, reason: e.target.value })} searchable={false} value={form.reason}>
                                {overview.reasons.map((r) => <option key={r} value={r}>{reasonLabel(r)}</option>)}
                            </Select>
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('note')} field="note" hint={t('inv.ret.f.noteHint')} label={t('inv.ret.f.note')} required={form.reason === 'other'}>
                                <Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                            </FormField>
                        </div>
                        <div className="flex flex-col gap-1.5 sm:col-span-2">
                            <p className="text-sm font-medium">{t('inv.ret.lines')}</p>
                            {receipt === null ? null : (
                                <div className="border border-border">
                                    <Table data-testid="return-lines">
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead scope="col">{t('inv.col.item')}</TableHead>
                                                <TableHead className="text-right" scope="col">{t('inv.ret.col.accepted')}</TableHead>
                                                <TableHead className="text-right" scope="col">{t('inv.ret.col.room')}</TableHead>
                                                <TableHead className="text-right" scope="col">{t('inv.po.price')}</TableHead>
                                                <TableHead scope="col">{t('inv.ret.col.quantity')}</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {receipt.lines.map((l) => (
                                                <TableRow key={l.id}>
                                                    <TableCell><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span></TableCell>
                                                    <TableCell className="text-right">{qty(l.accepted_milli)} {l.unit}</TableCell>
                                                    <TableCell className="text-right">{qty(l.returnable_milli)} {l.unit}</TableCell>
                                                    <TableCell className="text-right">{money(l.unit_price_minor)}</TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center gap-2">
                                                            <Input aria-label={`${t('inv.ret.col.quantity')} ${l.item_code}`} className="w-28" inputMode="decimal" onChange={(e) => setForm({ ...form, quantities: { ...form.quantities, [l.id]: e.target.value } })} value={form.quantities[l.id] ?? ''} />
                                                            <Button onClick={() => setForm({ ...form, quantities: { ...form.quantities, [l.id]: plainMilli(l.returnable_milli) } })} size="sm" type="button" variant="outline">{t('inv.ret.fillAll')}</Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                            {noLines ? <p className="text-sm text-danger">{t('inv.ret.noLines')}</p> : null}
                            {action.fieldError('lines') !== undefined ? <p className="text-sm text-danger">{action.fieldError('lines')}</p> : null}
                        </div>
                        <p className="text-xs text-muted-foreground sm:col-span-2">{t('inv.ret.f.creditHint')}</p>
                        <FormField error={action.fieldError('credit_note_number')} field="credit_note_number" label={t('inv.ret.f.creditNote')} required={form.credit_tax.trim() !== ''}>
                            <Input maxLength={40} onChange={(e) => setForm({ ...form, credit_note_number: e.target.value })} value={form.credit_note_number} />
                        </FormField>
                        <FormField error={taxError ? t('fo.folio.invalidAmount') : action.fieldError('credit_tax_minor')} field="credit_tax_minor" label={t('inv.ret.f.creditTax', { currency: overview.currency })}>
                            <MoneyInput onChange={(e) => setForm({ ...form, credit_tax: e.target.value })} value={form.credit_tax} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
