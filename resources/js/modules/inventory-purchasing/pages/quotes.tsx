import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Option = {
    supplier: { id: string; code: string; name: string; payment_terms_days: number; rating_avg: number | null };
    unit: string; unit_price_minor: number; source: 'price_list' | 'quote'; valid_until: string | null; lead_time_days: number | null; min_qty_milli: number;
    base_price_minor: number; cheapest: boolean; over_cheapest_bp: number;
};
type Compare = { item: { id: string; code: string; name: string; base_unit: string }; options: Option[] };
type Quote = {
    id: string; supplier_name: string; item_code: string; item_name: string; unit: string; unit_price_minor: number; min_qty_milli: number; quoted_on: string; valid_until: string;
    lead_time_days: number; reference: string | null; note: string | null; created_by_name: string | null; valid: boolean;
};
type Choice = { id: string; code: string; name: string; base_unit: string; units: string[] };
type Overview = {
    currency: string; today: string; compare: Compare[]; quotes: Quote[]; suppliers: { id: string; code: string; name: string }[]; items: Choice[]; selected: string[]; may: { record: boolean };
};
type Form = { supplier_id: string; item_id: string; unit: string; amount: string; valid_until: string; lead_time_days: string; min_quantity: string; reference: string; note: string };

/** Supplier prices side by side for the items about to be bought (FR-PUR-005). */
export default function QuotesPage({ overview }: { overview: Overview }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const [amountError, setAmountError] = useState(false);
    const money = (minor: number) => format.money(minor, overview.currency);
    const itemOf = (id: string) => overview.items.find((i) => i.id === id);
    const formItem = form === null ? null : itemOf(form.item_id) ?? null;
    const available = overview.items.filter((i) => !overview.selected.includes(i.id));

    function compare(ids: string[]) {
        router.get('/inventory/quotes', ids.length === 0 ? {} : { items: ids }, { preserveScroll: true });
    }

    function openNew() {
        action.clear();
        setAmountError(false);
        const item = itemOf(overview.selected[0] ?? '') ?? overview.items[0];
        setForm({ supplier_id: overview.suppliers[0]?.id ?? '', item_id: item?.id ?? '', unit: item?.base_unit ?? '', amount: '', valid_until: '', lead_time_days: '', min_quantity: '', reference: '', note: '' });
    }

    async function record() {
        if (form === null) return;
        const minor = parseMajorToMinor(form.amount, overview.currency);
        setAmountError(minor === null);
        if (minor === null) return;
        const done = await action.run<{ overview: Overview }>('/inventory/quotes', {
            body: {
                supplier_id: form.supplier_id, item_id: form.item_id, unit: form.unit, unit_price_minor: minor, valid_until: form.valid_until, lead_time_days: form.lead_time_days === '' ? null : form.lead_time_days,
                min_quantity: form.min_quantity || null, reference: form.reference || null, note: form.note || null,
            },
        });
        if (done !== null) {
            setForm(null);
            router.get('/inventory/quotes', { items: [form.item_id] }, { preserveScroll: true });
        }
    }

    const quoteColumns: DataGridColumn<Quote>[] = [
        { id: 'supplier', label: t('inv.po.supplier'), value: (q) => q.supplier_name, filter: 'select', rowHeader: true },
        { id: 'item', label: t('inv.col.item'), value: (q) => q.item_code, searchText: (q) => `${q.item_code} ${q.item_name}`, cell: (q) => <><span className="font-medium">{q.item_code}</span> <span className="text-muted-foreground">{q.item_name}</span></> },
        { id: 'price', label: t('inv.quo.col.price'), align: 'right', value: (q) => q.unit_price_minor, cell: (q) => `${money(q.unit_price_minor)} / ${q.unit}` },
        { id: 'minQty', label: t('inv.quo.col.minQty'), align: 'right', value: (q) => q.min_qty_milli, cell: (q) => (q.min_qty_milli === 0 ? '—' : `${formatMilli(q.min_qty_milli, locale)} ${q.unit}`) },
        { id: 'quoted', label: t('inv.quo.col.quotedOn'), value: (q) => q.quoted_on, cell: (q) => format.date(q.quoted_on) },
        { id: 'until', label: t('inv.quo.col.validUntil'), value: (q) => q.valid_until, cell: (q) => format.date(q.valid_until) },
        { id: 'lead', label: t('inv.quo.col.lead'), align: 'right', value: (q) => q.lead_time_days, cell: (q) => t('inv.sup.days', { count: q.lead_time_days }) },
        { id: 'reference', label: t('inv.quo.f.reference'), value: (q) => q.reference ?? '', cell: (q) => q.reference ?? '—', hidden: true },
        { id: 'by', label: t('inv.sup.col.addedBy'), value: (q) => q.created_by_name ?? '', cell: (q) => q.created_by_name ?? '—', hidden: true },
        {
            id: 'state', label: t('inv.quo.col.state'), value: (q) => (q.valid ? 'valid' : 'expired'), filter: 'select', filterLabel: (v) => t(v === 'valid' ? 'inv.quo.valid' : 'inv.quo.expired'),
            cell: (q) => <StatusBadge label={t(q.valid ? 'inv.quo.valid' : 'inv.quo.expired')} tone={q.valid ? 'success' : 'neutral'} />,
        },
    ];

    return (
        <InventoryShell actions={overview.may.record ? <Button onClick={openNew} type="button">{t('inv.quo.record')}</Button> : undefined} description={t('inv.quo.description')} title={t('inv.quo.title')} wide>
            <section aria-labelledby="quo-compare-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="quo-compare-h">{t('inv.quo.compareHeading')}</h2>
                <div className="flex flex-wrap items-end gap-3 border border-border bg-surface p-4">
                    <div className="w-full max-w-sm">
                        <FormField label={t('inv.quo.addItem')}>
                            <Select onChange={(e) => { if (e.target.value !== '') compare([...overview.selected, e.target.value]); }} value="">
                                <option value="">{t('inv.quo.pickItem')}</option>
                                {available.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name}</option>)}
                            </Select>
                        </FormField>
                    </div>
                    <ul aria-label={t('inv.quo.selected')} className="flex flex-wrap gap-2" data-testid="quote-selected">
                        {overview.selected.map((id) => {
                            const item = itemOf(id);
                            const name = item === undefined ? id : `${item.code} · ${item.name}`;

                            return (
                                <li className="flex items-center gap-1 border border-border bg-muted px-2 py-1 text-sm" key={id}>
                                    {name}
                                    <button aria-label={t('inv.quo.removeItem', { item: name })} className="px-1 text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" onClick={() => compare(overview.selected.filter((x) => x !== id))} type="button">×</button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
                {overview.compare.length === 0 ? <EmptyState title={t('inv.quo.noCompare')} /> : overview.compare.map((c) => (
                    <div className="flex flex-col gap-2" data-testid={`compare-${c.item.code}`} key={c.item.id}>
                        <h3 className="text-base font-semibold"><span>{c.item.code}</span> <span className="font-normal text-muted-foreground">{c.item.name}</span></h3>
                        {c.options.length === 0 ? <EmptyState title={t('inv.quo.noOptions')} /> : (
                            <div className="border border-border bg-surface">
                                <Table>
                                    <caption className="sr-only">{c.item.code} {c.item.name}</caption>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead scope="col">{t('inv.po.supplier')}</TableHead>
                                            <TableHead scope="col">{t('inv.quo.col.source')}</TableHead>
                                            <TableHead className="text-right" scope="col">{t('inv.quo.col.price')}</TableHead>
                                            <TableHead className="text-right" scope="col">{t('inv.quo.col.basePrice', { unit: c.item.base_unit })}</TableHead>
                                            <TableHead className="text-right" scope="col">{t('inv.quo.col.over')}</TableHead>
                                            <TableHead scope="col">{t('inv.quo.col.validUntil')}</TableHead>
                                            <TableHead className="text-right" scope="col">{t('inv.quo.col.lead')}</TableHead>
                                            <TableHead className="text-right" scope="col">{t('inv.quo.col.minQty')}</TableHead>
                                            <TableHead scope="col"><span className="sr-only">{t('inv.col.actions')}</span></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {c.options.map((o) => (
                                            <TableRow data-cheapest={o.cheapest ? 'true' : undefined} key={`${o.supplier.id}:${o.source}:${o.unit}`}>
                                                <TableCell>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">{o.supplier.name}</span>
                                                        {o.cheapest ? <StatusBadge label={t('inv.quo.cheapest')} tone="success" /> : null}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {o.supplier.rating_avg === null ? t('inv.sup.noRating') : `★ ${format.number(o.supplier.rating_avg)} / 5`} · {t('inv.sup.days', { count: o.supplier.payment_terms_days })}
                                                    </div>
                                                </TableCell>
                                                <TableCell><StatusBadge label={t(`inv.quo.source.${o.source}` as MessageKey)} tone={o.source === 'quote' ? 'info' : 'neutral'} /></TableCell>
                                                <TableCell className="text-right">{money(o.unit_price_minor)} / {o.unit}</TableCell>
                                                <TableCell className="text-right font-medium">{money(o.base_price_minor)} / {c.item.base_unit}</TableCell>
                                                <TableCell className="text-right">{o.cheapest ? '—' : `+${format.number(o.over_cheapest_bp / 100)} %`}</TableCell>
                                                <TableCell>{o.valid_until === null ? '—' : format.date(o.valid_until)}</TableCell>
                                                <TableCell className="text-right">{o.lead_time_days === null ? '—' : t('inv.sup.days', { count: o.lead_time_days })}</TableCell>
                                                <TableCell className="text-right">{o.min_qty_milli === 0 ? '—' : `${formatMilli(o.min_qty_milli, locale)} ${o.unit}`}</TableCell>
                                                <TableCell><Button asChild size="sm" variant="outline"><Link href="/inventory/orders">{t('inv.quo.order')}</Link></Button></TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </div>
                ))}
            </section>

            <section aria-labelledby="quo-recorded-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="quo-recorded-h">{t('inv.quo.recorded')}</h2>
                <DataGrid caption={t('inv.quo.recorded')} columns={quoteColumns} empty={<EmptyState title={t('inv.quo.empty')} />} getRowId={(q) => q.id} id="inv.quotes" rows={overview.quotes} testId="quotes" />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void record()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.quo.record')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('supplier_id')} field="supplier_id" label={t('inv.quo.f.supplier')}>
                            <Select onChange={(e) => setForm({ ...form, supplier_id: e.target.value })} value={form.supplier_id}>
                                {overview.suppliers.map((s) => <option key={s.id} value={s.id}>{s.code} · {s.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('item_id')} field="item_id" label={t('inv.quo.f.item')}>
                            <Select onChange={(e) => setForm({ ...form, item_id: e.target.value, unit: itemOf(e.target.value)?.base_unit ?? '' })} value={form.item_id}>
                                {overview.items.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('unit')} field="unit" label={t('inv.quo.f.unit')}>
                            <Select onChange={(e) => setForm({ ...form, unit: e.target.value })} searchable={false} value={form.unit}>
                                {(formItem?.units ?? []).map((u) => <option key={u} value={u}>{u}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={amountError ? t('fo.folio.invalidAmount') : action.fieldError('unit_price_minor')} field="unit_price_minor" label={t('inv.quo.f.price', { unit: form.unit, currency: overview.currency })}>
                            <Input inputMode="decimal" onChange={(e) => setForm({ ...form, amount: e.target.value })} value={form.amount} />
                        </FormField>
                        <FormField error={action.fieldError('valid_until')} field="valid_until" label={t('inv.quo.f.validUntil')}>
                            <DatePicker onChange={(e) => setForm({ ...form, valid_until: e.target.value })} value={form.valid_until} />
                        </FormField>
                        <FormField error={action.fieldError('lead_time_days')} field="lead_time_days" label={t('inv.quo.f.lead')}>
                            <Input inputMode="numeric" onChange={(e) => setForm({ ...form, lead_time_days: e.target.value })} value={form.lead_time_days} />
                        </FormField>
                        <FormField error={action.fieldError('min_quantity')} field="min_quantity" label={t('inv.quo.f.minQty')}>
                            <Input inputMode="decimal" onChange={(e) => setForm({ ...form, min_quantity: e.target.value })} value={form.min_quantity} />
                        </FormField>
                        <FormField error={action.fieldError('reference')} field="reference" label={t('inv.quo.f.reference')}>
                            <Input maxLength={40} onChange={(e) => setForm({ ...form, reference: e.target.value })} value={form.reference} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('note')} field="note" label={t('inv.quo.f.note')}>
                                <Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
