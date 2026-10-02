import { Link } from '@inertiajs/react';
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
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Price = { id: string; item_id: string; item_code: string; item_name: string; unit: string; unit_price_minor: number; valid_from: string; reason: string | null; created_by_name: string | null; in_force: boolean };
type Rating = { id: string; score: number; aspect: string; comment: string | null; ref_type: string | null; created_by_name: string | null; created_at: string };
type Choice = { id: string; code: string; name: string; base_unit: string; units: string[] };
type Supplier = {
    id: string; code: string; name: string; contact_name: string | null; phone: string | null; email: string | null; address: string | null; tax_id: string | null;
    payment_terms_days: number; note: string | null; is_active: boolean; lock_version: number; rating_avg: number | null; rating_count: number;
    currency: string; aspects: string[]; items: Choice[]; prices: Price[]; ratings: Rating[]; may: { manage: boolean; rate: boolean };
};
type Edit = { name: string; contact_name: string; phone: string; email: string; address: string; tax_id: string; payment_terms_days: string; note: string; active: boolean };
type PriceForm = { item_id: string; unit: string; amount: string; valid_from: string; reason: string };
type RatingForm = { score: string; aspect: string; comment: string };

const reload = ['supplier'];
const stars = (score: number) => `${'★'.repeat(score)}${'☆'.repeat(Math.max(0, 5 - score))} ${score}/5`;

export default function SupplierPage({ supplier }: { supplier: Supplier }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [edit, setEdit] = useState<Edit | null>(null);
    const [price, setPrice] = useState<PriceForm | null>(null);
    const [rating, setRating] = useState<RatingForm | null>(null);
    const [amountError, setAmountError] = useState(false);
    const mayRate = supplier.may.rate || supplier.may.manage;
    const aspect = (a: string) => t(`inv.sup.aspect.${a}` as MessageKey);
    const money = (minor: number) => format.money(minor, supplier.currency);
    const priceItem = price === null ? null : supplier.items.find((i) => i.id === price.item_id) ?? null;

    function openEdit() {
        action.clear();
        setEdit({
            name: supplier.name, contact_name: supplier.contact_name ?? '', phone: supplier.phone ?? '', email: supplier.email ?? '', address: supplier.address ?? '',
            tax_id: supplier.tax_id ?? '', payment_terms_days: String(supplier.payment_terms_days), note: supplier.note ?? '', active: supplier.is_active,
        });
    }

    function openPrice() {
        action.clear();
        setAmountError(false);
        const first = supplier.items[0];
        setPrice({ item_id: first?.id ?? '', unit: first?.base_unit ?? '', amount: '', valid_from: '', reason: '' });
    }

    function openRating() {
        action.clear();
        setRating({ score: '5', aspect: supplier.aspects[0] ?? 'overall', comment: '' });
    }

    async function saveEdit() {
        if (edit === null) return;
        const done = await action.run(`/inventory/suppliers/${supplier.id}`, {
            body: {
                name: edit.name, contact_name: edit.contact_name || null, phone: edit.phone || null, email: edit.email || null, address: edit.address || null, tax_id: edit.tax_id || null,
                payment_terms_days: edit.payment_terms_days, note: edit.note || null, active: edit.active, lock_version: supplier.lock_version,
            },
            reload,
        });
        if (done !== null) setEdit(null);
    }

    async function savePrice() {
        if (price === null) return;
        const minor = parseMajorToMinor(price.amount, supplier.currency);
        setAmountError(minor === null);
        if (minor === null) return;
        const done = await action.run(`/inventory/suppliers/${supplier.id}/prices`, { body: { item_id: price.item_id, unit: price.unit, unit_price_minor: minor, valid_from: price.valid_from, reason: price.reason || null }, reload });
        if (done !== null) setPrice(null);
    }

    async function saveRating() {
        if (rating === null) return;
        const done = await action.run(`/inventory/suppliers/${supplier.id}/ratings`, { body: { score: Number(rating.score), aspect: rating.aspect, comment: rating.comment || null }, reload });
        if (done !== null) setRating(null);
    }

    const priceColumns: DataGridColumn<Price>[] = [
        { id: 'item', label: t('inv.col.item'), value: (p) => p.item_code, searchText: (p) => `${p.item_code} ${p.item_name}`, rowHeader: true, cell: (p) => <><span className="font-medium">{p.item_code}</span> <span className="text-muted-foreground">{p.item_name}</span></> },
        { id: 'unit', label: t('inv.col.unit'), value: (p) => p.unit, filter: 'select' },
        { id: 'price', label: t('inv.sup.col.price'), align: 'right', value: (p) => p.unit_price_minor, cell: (p) => money(p.unit_price_minor) },
        { id: 'from', label: t('inv.sup.col.validFrom'), value: (p) => p.valid_from, cell: (p) => format.date(p.valid_from) },
        {
            id: 'state', label: t('inv.col.status'), value: (p) => (p.in_force ? 'in_force' : 'other'), filter: 'select', filterLabel: (v) => t(v === 'in_force' ? 'inv.sup.inForce' : 'inv.sup.notInForce'),
            cell: (p) => <StatusBadge label={t(p.in_force ? 'inv.sup.inForce' : 'inv.sup.notInForce')} tone={p.in_force ? 'success' : 'neutral'} />,
        },
        { id: 'reason', label: t('inv.col.reason'), value: (p) => p.reason ?? '', cell: (p) => p.reason ?? '—', hidden: true },
        { id: 'by', label: t('inv.sup.col.addedBy'), value: (p) => p.created_by_name ?? '', cell: (p) => p.created_by_name ?? '—', hidden: true },
    ];
    const ratingColumns: DataGridColumn<Rating>[] = [
        { id: 'score', label: t('inv.sup.col.score'), value: (r) => r.score, rowHeader: true, cell: (r) => stars(r.score) },
        { id: 'aspect', label: t('inv.sup.col.aspect'), value: (r) => r.aspect, filter: 'select', filterLabel: aspect, cell: (r) => aspect(r.aspect) },
        { id: 'comment', label: t('inv.sup.col.comment'), value: (r) => r.comment ?? '', cell: (r) => r.comment ?? '—' },
        { id: 'by', label: t('inv.sup.col.ratedBy'), value: (r) => r.created_by_name ?? '', cell: (r) => r.created_by_name ?? '—' },
        { id: 'when', label: t('inv.sup.col.when'), value: (r) => r.created_at, cell: (r) => format.instant(r.created_at) },
    ];
    const facts: [string, string][] = [
        [t('inv.sup.f.contactName'), supplier.contact_name ?? '—'],
        [t('inv.sup.col.phone'), supplier.phone ?? '—'],
        [t('inv.sup.f.email'), supplier.email ?? '—'],
        [t('inv.sup.f.address'), supplier.address ?? '—'],
        [t('inv.sup.col.taxId'), supplier.tax_id ?? '—'],
        [t('inv.sup.f.terms'), t('inv.sup.days', { count: supplier.payment_terms_days })],
        [t('inv.sup.col.rating'), supplier.rating_avg === null || supplier.rating_count === 0 ? t('inv.sup.noRating') : `${format.number(supplier.rating_avg)} / 5 (${format.number(supplier.rating_count)})`],
        [t('inv.col.note'), supplier.note ?? '—'],
    ];

    return (
        <InventoryShell
            actions={<Button asChild variant="outline"><Link href="/inventory/suppliers">{t('inv.sup.back')}</Link></Button>}
            description={t('inv.sup.description')}
            title={`${supplier.code} · ${supplier.name}`}
            wide
        >
            <section aria-labelledby="sup-details-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="sup-details-h">{t('inv.sup.details')}</h2>
                    <div className="flex items-center gap-2">
                        <StatusBadge label={t(supplier.is_active ? 'inv.status.active' : 'inv.status.inactive')} tone={supplier.is_active ? 'success' : 'neutral'} />
                        {supplier.may.manage ? <Button onClick={openEdit} size="sm" type="button" variant="outline">{t('inv.sup.edit')}</Button> : null}
                    </div>
                </div>
                <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-4" data-testid="supplier-details">
                    {facts.map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-xs text-muted-foreground">{label}</dt>
                            <dd className="break-words">{value}</dd>
                        </div>
                    ))}
                </dl>
            </section>

            <section aria-labelledby="sup-prices-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="sup-prices-h">{t('inv.sup.prices')}</h2>
                    {supplier.may.manage ? <Button onClick={openPrice} size="sm" type="button">{t('inv.sup.addPrice')}</Button> : null}
                </div>
                <p className="text-sm text-muted-foreground">{t('inv.sup.pricesHint')}</p>
                <DataGrid caption={t('inv.sup.prices')} columns={priceColumns} empty={<EmptyState title={t('inv.sup.noPrices')} />} getRowId={(p) => p.id} id="inv.supplier.prices" rows={supplier.prices} testId="supplier-prices" />
            </section>

            <section aria-labelledby="sup-ratings-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="sup-ratings-h">{t('inv.sup.ratings')}</h2>
                    <div className="flex items-center gap-3">
                        {supplier.rating_avg !== null && supplier.rating_count > 0 ? <span className="text-sm" data-testid="supplier-average">{t('inv.sup.average')}: {format.number(supplier.rating_avg)} / 5 ({format.number(supplier.rating_count)})</span> : null}
                        {mayRate ? <Button onClick={openRating} size="sm" type="button">{t('inv.sup.addRating')}</Button> : null}
                    </div>
                </div>
                <DataGrid caption={t('inv.sup.ratings')} columns={ratingColumns} empty={<EmptyState title={t('inv.sup.noRatings')} />} getRowId={(r) => r.id} id="inv.supplier.ratings" rows={supplier.ratings} testId="supplier-ratings" />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setEdit(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void saveEdit()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setEdit(null)}
                open={edit !== null}
                title={t('inv.sup.edit')}
            >
                {edit !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('name')} field="name" label={t('inv.col.name')}>
                                <Input maxLength={120} onChange={(e) => setEdit({ ...edit, name: e.target.value })} value={edit.name} />
                            </FormField>
                        </div>
                        <FormField error={action.fieldError('contact_name')} field="contact_name" label={t('inv.sup.f.contactName')}>
                            <Input maxLength={80} onChange={(e) => setEdit({ ...edit, contact_name: e.target.value })} value={edit.contact_name} />
                        </FormField>
                        <FormField error={action.fieldError('phone')} field="phone" label={t('inv.sup.col.phone')}>
                            <Input inputMode="tel" maxLength={30} onChange={(e) => setEdit({ ...edit, phone: e.target.value })} value={edit.phone} />
                        </FormField>
                        <FormField error={action.fieldError('email')} field="email" label={t('inv.sup.f.email')}>
                            <Input inputMode="email" maxLength={120} onChange={(e) => setEdit({ ...edit, email: e.target.value })} value={edit.email} />
                        </FormField>
                        <FormField error={action.fieldError('payment_terms_days')} field="payment_terms_days" required label={t('inv.sup.f.terms')}>
                            <Input inputMode="numeric" onChange={(e) => setEdit({ ...edit, payment_terms_days: e.target.value })} value={edit.payment_terms_days} />
                        </FormField>
                        <FormField error={action.fieldError('tax_id')} field="tax_id" hint={t('inv.sup.taxHint')} label={t('inv.sup.col.taxId')}>
                            <Input inputMode="numeric" maxLength={24} onChange={(e) => setEdit({ ...edit, tax_id: e.target.value })} value={edit.tax_id} />
                        </FormField>
                        <FormField error={action.fieldError('address')} field="address" label={t('inv.sup.f.address')}>
                            <Input maxLength={200} onChange={(e) => setEdit({ ...edit, address: e.target.value })} value={edit.address} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('note')} field="note" label={t('inv.sup.f.note')}>
                                <Input maxLength={200} onChange={(e) => setEdit({ ...edit, note: e.target.value })} value={edit.note} />
                            </FormField>
                        </div>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} type="checkbox" />
                            {t('inv.sup.active')}
                        </label>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setPrice(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button disabled={supplier.items.length === 0} loading={action.busy} onClick={() => void savePrice()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setPrice(null)}
                open={price !== null}
                title={t('inv.sup.addPrice')}
            >
                {price !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {supplier.items.length === 0 ? <p className="text-sm text-muted-foreground sm:col-span-2">{t('inv.sup.noItems')}</p> : null}
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('item_id')} field="item_id" label={t('inv.col.item')}>
                            <Select onChange={(e) => setPrice({ ...price, item_id: e.target.value, unit: supplier.items.find((i) => i.id === e.target.value)?.base_unit ?? '' })} value={price.item_id}>
                                {supplier.items.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('unit')} field="unit" label={t('inv.col.unit')}>
                            <Select onChange={(e) => setPrice({ ...price, unit: e.target.value })} value={price.unit}>
                                {priceItem === null ? null : [priceItem.base_unit, ...priceItem.units].map((u) => <option key={u} value={u}>{u}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={amountError ? t('fo.folio.invalidAmount') : action.fieldError('unit_price_minor')} field="unit_price_minor" label={t('inv.sup.f.price', { unit: price.unit, currency: supplier.currency })}>
                            <Input inputMode="decimal" onChange={(e) => setPrice({ ...price, amount: e.target.value })} value={price.amount} />
                        </FormField>
                        <FormField error={action.fieldError('valid_from')} field="valid_from" label={t('inv.sup.f.validFrom')}>
                            <DatePicker onChange={(e) => setPrice({ ...price, valid_from: e.target.value })} value={price.valid_from} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('reason')} field="reason" label={t('inv.sup.f.reason')}>
                                <Input maxLength={200} onChange={(e) => setPrice({ ...price, reason: e.target.value })} value={price.reason} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setRating(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void saveRating()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setRating(null)}
                open={rating !== null}
                title={t('inv.sup.addRating')}
            >
                {rating !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('score')} field="score" label={t('inv.sup.f.score')}>
                            <Select onChange={(e) => setRating({ ...rating, score: e.target.value })} searchable={false} value={rating.score}>
                                {[5, 4, 3, 2, 1].map((n) => <option key={n} value={String(n)}>{stars(n)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('aspect')} field="aspect" label={t('inv.sup.f.aspect')}>
                            <Select onChange={(e) => setRating({ ...rating, aspect: e.target.value })} searchable={false} value={rating.aspect}>
                                {supplier.aspects.map((a) => <option key={a} value={a}>{aspect(a)}</option>)}
                            </Select>
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('comment')} field="comment" label={t('inv.sup.f.comment')}>
                                <Input maxLength={200} onChange={(e) => setRating({ ...rating, comment: e.target.value })} value={rating.comment} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
