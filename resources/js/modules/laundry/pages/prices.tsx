import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { LaundryShell } from '@/modules/laundry/components/laundry-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Item = { id: string; code: string; name: string; unit_price_minor: number; is_active: boolean; lock_version: number };

type Treatment = { id: string; code: string; name: string; kind: string; pricing: string; value: number; is_active: boolean; lock_version: number };
const BLANK_TREATMENT = { code: '', name: '', kind: 'service', pricing: 'percent', value: '', reason: '' };

export default function LaundryPricesPage({ currency, items, treatments, may }: { currency: string; items: Item[]; treatments: Treatment[]; may: { prices: boolean } }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ code: '', name: '', price: '', reason: '' });
    const [edit, setEdit] = useState<Record<string, { price: string; reason: string }>>({});
    const [invalid, setInvalid] = useState(false);
    const [tForm, setTForm] = useState(BLANK_TREATMENT);
    const [tReason, setTReason] = useState<Record<string, string>>({});
    const rate = (tr: { pricing: string; value: number }) => (tr.pricing === 'percent' ? `+${tr.value / 100}%` : `+${format.money(tr.value, currency)}`);

    async function add() {
        const minor = parseMajorToMinor(form.price, currency);
        setInvalid(minor === null);
        if (minor === null) return;
        const done = await action.run('/laundry/prices', { body: { code: form.code, name: form.name, unit_price_minor: minor, reason: form.reason }, reload: ['items'] });
        if (done !== null) setForm({ code: '', name: '', price: '', reason: '' });
    }

    async function save(item: Item, active: boolean) {
        const e = edit[item.id] ?? { price: '', reason: '' };
        const minor = e.price === '' ? item.unit_price_minor : parseMajorToMinor(e.price, currency);
        if (minor === null) { setInvalid(true); return; }
        setInvalid(false);
        await action.run(`/laundry/prices/${item.id}`, { body: { name: item.name, unit_price_minor: minor, is_active: active, lock_version: item.lock_version, reason: e.reason }, reload: ['items'] });
    }

    function treatmentValue(pricing: string, text: string): number | null {
        if (pricing === 'percent') {
            const n = Number(text.replace(',', '.'));
            return text.trim() === '' || Number.isNaN(n) || n < 0 ? null : Math.round(n * 100);
        }
        return parseMajorToMinor(text, currency);
    }

    async function addTreatment() {
        const value = treatmentValue(tForm.pricing, tForm.value);
        setInvalid(value === null);
        if (value === null) return;
        const done = await action.run('/laundry/treatments', { body: { code: tForm.code, name: tForm.name, kind: tForm.kind, pricing: tForm.pricing, value, reason: tForm.reason }, reload: ['treatments'] });
        if (done !== null) setTForm(BLANK_TREATMENT);
    }

    async function toggleTreatment(tr: Treatment) {
        await action.run(`/laundry/treatments/${tr.id}`, { body: { name: tr.name, pricing: tr.pricing, value: tr.value, is_active: !tr.is_active, lock_version: tr.lock_version, reason: tReason[tr.id] ?? '' }, reload: ['treatments'] });
    }

    const activeColumn = <T extends { is_active: boolean }>(): DataGridColumn<T> => ({
        id: 'active', label: t('ldy.prices.active'), value: (x) => (x.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: (v) => (v === 'active' ? t('property.status.active') : t('property.status.inactive')),
        cell: (x) => <StatusBadge label={x.is_active ? t('ldy.prices.active') : '—'} tone={x.is_active ? 'success' : 'neutral'} />,
    });
    const itemColumns: DataGridColumn<Item>[] = [
        { id: 'code', label: t('ldy.prices.code'), value: (i) => i.code, rowHeader: true },
        { id: 'name', label: t('ldy.prices.name'), value: (i) => i.name },
        { id: 'price', label: t('ldy.prices.price'), align: 'right', value: (i) => i.unit_price_minor, cell: (i) => format.money(i.unit_price_minor, currency) },
        activeColumn<Item>(),
        ...(may.prices ? [{
            id: 'actions', label: t('ldy.actions'),
            cell: (i: Item) => (
                <div className="flex flex-wrap items-center gap-2">
                    <MoneyInput aria-label={`${t('ldy.prices.price')} ${i.code}`} className="min-h-9 w-32" onChange={(e) => setEdit({ ...edit, [i.id]: { price: e.target.value, reason: edit[i.id]?.reason ?? '' } })} placeholder={t('ldy.prices.price')} value={edit[i.id]?.price ?? ''} />
                    <Input aria-label={`${t('ldy.prices.reason')} ${i.code}`} className="min-h-9 w-44" maxLength={300} onChange={(e) => setEdit({ ...edit, [i.id]: { price: edit[i.id]?.price ?? '', reason: e.target.value } })} placeholder={t('ldy.prices.reason')} value={edit[i.id]?.reason ?? ''} />
                    <Button disabled={action.busy || (edit[i.id]?.reason ?? '').trim() === ''} onClick={() => void save(i, i.is_active)} size="sm" type="button" variant="outline">{t('ldy.prices.save')}</Button>
                    <Button disabled={action.busy || (edit[i.id]?.reason ?? '').trim() === ''} onClick={() => void save(i, !i.is_active)} size="sm" type="button" variant="outline">{i.is_active ? t('ldy.prices.deactivate') : t('ldy.prices.activate')}</Button>
                </div>
            ),
        }] : []),
    ];
    const treatmentColumns: DataGridColumn<Treatment>[] = [
        { id: 'code', label: t('ldy.prices.code'), value: (tr) => tr.code, rowHeader: true },
        { id: 'name', label: t('ldy.prices.name'), value: (tr) => tr.name },
        { id: 'kind', label: t('ldy.treat.kind'), value: (tr) => tr.kind, filter: 'select', filterLabel: (v) => t(`ldy.treat.kind.${v}` as 'ldy.treat.kind.service'), cell: (tr) => t(`ldy.treat.kind.${tr.kind}` as 'ldy.treat.kind.service') },
        { id: 'pricing', label: t('ldy.treat.pricing'), value: (tr) => tr.pricing, filter: 'select', filterLabel: (v) => t(`ldy.treat.pricing.${v}` as 'ldy.treat.pricing.percent'), cell: (tr) => t(`ldy.treat.pricing.${tr.pricing}` as 'ldy.treat.pricing.percent'), hidden: true },
        { id: 'rate', label: t('ldy.treat.rate'), align: 'right', value: rate },
        activeColumn<Treatment>(),
        ...(may.prices ? [{
            id: 'actions', label: t('ldy.actions'),
            cell: (tr: Treatment) => (
                <div className="flex flex-wrap items-center gap-2">
                    <Input aria-label={`${t('ldy.prices.reason')} ${tr.code}`} className="min-h-9 w-44" maxLength={300} onChange={(e) => setTReason({ ...tReason, [tr.id]: e.target.value })} placeholder={t('ldy.prices.reason')} value={tReason[tr.id] ?? ''} />
                    <Button disabled={action.busy || (tReason[tr.id] ?? '').trim() === ''} onClick={() => void toggleTreatment(tr)} size="sm" type="button" variant="outline">{tr.is_active ? t('ldy.prices.deactivate') : t('ldy.prices.activate')}</Button>
                </div>
            ),
        }] : []),
    ];

    return (
        <LaundryShell description={t('ldy.prices.description')} title={t('ldy.prices.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <DataGrid
                caption={t('ldy.prices.title')}
                columns={itemColumns}
                empty={<EmptyState title={t('ldy.prices.empty')} />}
                getRowId={(i) => i.id}
                id="ldy.prices"
                rows={items}
            />
            {invalid ? <p className="text-sm text-danger">{t('ldy.prices.invalid')}</p> : null}

            {may.prices && (
                <section aria-labelledby="add-h" className="flex flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="add-h">{t('ldy.prices.add')}</h2>
                    <form className="grid gap-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); void add(); }}>
                        <FormField field="code" error={action.fieldError('code')} label={t('ldy.prices.code')}><Input maxLength={20} onChange={(e) => setForm({ ...form, code: e.target.value })} required value={form.code} /></FormField>
                        <FormField field="name" error={action.fieldError('name')} label={t('ldy.prices.name')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} required value={form.name} /></FormField>
                        <FormField field="unit_price_minor" error={action.fieldError('unit_price_minor')} label={t('ldy.prices.price')}><MoneyInput onChange={(e) => setForm({ ...form, price: e.target.value })} required value={form.price} /></FormField>
                        <FormField field="reason" error={action.fieldError('reason')} label={t('ldy.prices.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} required value={form.reason} /></FormField>
                        <div className="sm:col-span-4"><Button loading={action.busy} type="submit">{t('ldy.prices.add')}</Button></div>
                    </form>
                </section>
            )}

            <section aria-labelledby="treat-h" className="flex flex-col gap-3 border-t border-border pt-4">
                <h2 className="text-lg font-semibold" id="treat-h">{t('ldy.treat.title')}</h2>
                <p className="text-sm text-muted-foreground">{t('ldy.treat.description')}</p>
                <DataGrid
                    caption={t('ldy.treat.title')}
                    columns={treatmentColumns}
                    empty={<EmptyState title={t('ldy.treat.empty')} />}
                    getRowId={(tr) => tr.id}
                    id="ldy.treatments"
                    rows={treatments}
                    testId="treatments"
                />
                {may.prices && (
                    <form className="grid gap-3 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); void addTreatment(); }}>
                        <FormField field="code" error={action.fieldError('code')} label={t('ldy.prices.code')}><Input maxLength={20} onChange={(e) => setTForm({ ...tForm, code: e.target.value })} required value={tForm.code} /></FormField>
                        <FormField field="name" error={action.fieldError('name')} label={t('ldy.prices.name')}><Input maxLength={80} onChange={(e) => setTForm({ ...tForm, name: e.target.value })} required value={tForm.name} /></FormField>
                        <FormField field="kind" error={action.fieldError('kind')} label={t('ldy.treat.kind')}><Select onChange={(e) => setTForm({ ...tForm, kind: e.target.value })} value={tForm.kind}><option value="service">{t('ldy.treat.kind.service')}</option><option value="express">{t('ldy.treat.kind.express')}</option></Select></FormField>
                        <FormField field="pricing" error={action.fieldError('pricing')} label={t('ldy.treat.pricing')}><Select onChange={(e) => setTForm({ ...tForm, pricing: e.target.value })} value={tForm.pricing}><option value="percent">{t('ldy.treat.pricing.percent')}</option><option value="fixed">{t('ldy.treat.pricing.fixed')}</option></Select></FormField>
                        <FormField field="value" error={action.fieldError('value')} label={t('ldy.treat.value')}><Input inputMode="decimal" onChange={(e) => setTForm({ ...tForm, value: e.target.value })} required value={tForm.value} /></FormField>
                        <FormField field="reason" error={action.fieldError('reason')} label={t('ldy.prices.reason')}><Input maxLength={300} onChange={(e) => setTForm({ ...tForm, reason: e.target.value })} required value={tForm.reason} /></FormField>
                        <div className="sm:col-span-3"><Button loading={action.busy} type="submit">{t('ldy.treat.add')}</Button></div>
                    </form>
                )}
            </section>
        </LaundryShell>
    );
}
