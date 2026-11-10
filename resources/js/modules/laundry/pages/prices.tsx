import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
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

type Turnaround = { standard_hours: number; express_hours: number; lock_version: number };

export default function LaundryPricesPage({ currency, items, treatments, turnaround, may }: { currency: string; items: Item[]; treatments: Treatment[]; turnaround: Turnaround; may: { prices: boolean } }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ code: '', name: '', price: '', reason: '' });
    const [edit, setEdit] = useState<Record<string, { price: string; reason: string }>>({});
    const [invalid, setInvalid] = useState(false);
    const [dialog, setDialog] = useState<'item' | 'treatment' | null>(null);
    const [tForm, setTForm] = useState(BLANK_TREATMENT);
    const [tReason, setTReason] = useState<Record<string, string>>({});
    const [hours, setHours] = useState({ standard: String(turnaround.standard_hours), express: String(turnaround.express_hours), reason: '' });
    const hoursChanged = hours.standard !== String(turnaround.standard_hours) || hours.express !== String(turnaround.express_hours);

    async function saveHours() {
        await action.run('/laundry/turnaround', { body: { standard_hours: Number(hours.standard), express_hours: Number(hours.express), lock_version: turnaround.lock_version, reason: hours.reason }, reload: ['turnaround'] });
        setHours((h) => ({ ...h, reason: '' }));
    }
    const rate = (tr: { pricing: string; value: number }) => (tr.pricing === 'percent' ? `+${tr.value / 100}%` : `+${format.money(tr.value, currency)}`);

    async function add() {
        const minor = parseMajorToMinor(form.price, currency);
        setInvalid(minor === null);
        if (minor === null) return;
        const done = await action.run('/laundry/prices', { body: { code: form.code, name: form.name, unit_price_minor: minor, reason: form.reason }, reload: ['items'] });
        if (done !== null) { setForm({ code: '', name: '', price: '', reason: '' }); setDialog(null); }
    }

    function openDialog(kind: 'item' | 'treatment') {
        action.clear();
        setInvalid(false);
        setDialog(kind);
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
        if (done !== null) { setTForm(BLANK_TREATMENT); setDialog(null); }
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
            {action.error !== null && dialog === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {may.prices ? <div className="flex justify-end"><Button onClick={() => openDialog('item')} size="sm" type="button">{t('ldy.prices.add')}</Button></div> : null}
            <DataGrid
                caption={t('ldy.prices.title')}
                columns={itemColumns}
                empty={<EmptyState title={t('ldy.prices.empty')} />}
                getRowId={(i) => i.id}
                id="ldy.prices"
                rows={items}
            />
            {invalid && dialog === null ? <p className="text-sm text-danger">{t('ldy.prices.invalid')}</p> : null}
            <section aria-labelledby="ldy-turnaround-h" className="flex max-w-3xl flex-col gap-3" data-testid="turnaround">
                <h2 className="text-lg font-semibold" id="ldy-turnaround-h">{t('ldy.turnaround.title')}</h2>
                <p className="text-sm text-muted-foreground">{t('ldy.turnaround.hint')}</p>
                {may.prices ? (
                    <div className="grid gap-3 sm:grid-cols-3">
                        <FormField error={action.fieldError('standard_hours')} field="standard_hours" label={t('ldy.turnaround.standard')}><Input inputMode="numeric" onChange={(e) => setHours({ ...hours, standard: e.target.value })} value={hours.standard} /></FormField>
                        <FormField error={action.fieldError('express_hours')} field="express_hours" label={t('ldy.turnaround.express')}><Input inputMode="numeric" onChange={(e) => setHours({ ...hours, express: e.target.value })} value={hours.express} /></FormField>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('ldy.prices.reason')}><Input maxLength={300} onChange={(e) => setHours({ ...hours, reason: e.target.value })} value={hours.reason} /></FormField>
                        <div><Button disabled={!hoursChanged || hours.reason.trim() === ''} loading={action.busy} onClick={() => void saveHours()} type="button" variant="outline">{t('ldy.turnaround.save')}</Button></div>
                    </div>
                ) : <p className="text-sm" data-testid="turnaround-read">{t('ldy.turnaround.read', { standard: turnaround.standard_hours, express: turnaround.express_hours })}</p>}
            </section>

            {may.prices && (
                <Dialog className="w-[min(42rem,calc(100vw-2rem))]" footer={<><Button disabled={action.busy} onClick={() => setDialog(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button form="price-form" loading={action.busy} type="submit">{t('ldy.prices.add')}</Button></>} onClose={() => setDialog(null)} open={dialog === 'item'} title={t('ldy.prices.add')}>
                    <div className="flex flex-col gap-3" data-testid="price-form">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        {invalid ? <p className="text-sm text-danger">{t('ldy.prices.invalid')}</p> : null}
                        <form className="grid gap-3 sm:grid-cols-2" id="price-form" onSubmit={(e) => { e.preventDefault(); void add(); }}>
                            <FormField field="code" error={action.fieldError('code')} label={t('ldy.prices.code')}><Input maxLength={20} onChange={(e) => setForm({ ...form, code: e.target.value })} required value={form.code} /></FormField>
                            <FormField field="name" error={action.fieldError('name')} label={t('ldy.prices.name')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} required value={form.name} /></FormField>
                            <FormField field="unit_price_minor" error={action.fieldError('unit_price_minor')} label={t('ldy.prices.price')}><MoneyInput onChange={(e) => setForm({ ...form, price: e.target.value })} required value={form.price} /></FormField>
                            <FormField field="reason" error={action.fieldError('reason')} label={t('ldy.prices.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} required value={form.reason} /></FormField>
                        </form>
                    </div>
                </Dialog>
            )}

            <section aria-labelledby="treat-h" className="flex flex-col gap-3 border-t border-border pt-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="treat-h">{t('ldy.treat.title')}</h2>
                    {may.prices ? <Button onClick={() => openDialog('treatment')} size="sm" type="button">{t('ldy.treat.add')}</Button> : null}
                </div>
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
            </section>

            {may.prices && (
                <Dialog className="w-[min(42rem,calc(100vw-2rem))]" footer={<><Button disabled={action.busy} onClick={() => setDialog(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button form="treatment-form" loading={action.busy} type="submit">{t('ldy.treat.add')}</Button></>} onClose={() => setDialog(null)} open={dialog === 'treatment'} title={t('ldy.treat.add')}>
                    <div className="flex flex-col gap-3" data-testid="treatment-form">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        {invalid ? <p className="text-sm text-danger">{t('ldy.prices.invalid')}</p> : null}
                        <form className="grid gap-3 sm:grid-cols-2" id="treatment-form" onSubmit={(e) => { e.preventDefault(); void addTreatment(); }}>
                            <FormField field="code" error={action.fieldError('code')} label={t('ldy.prices.code')}><Input maxLength={20} onChange={(e) => setTForm({ ...tForm, code: e.target.value })} required value={tForm.code} /></FormField>
                            <FormField field="name" error={action.fieldError('name')} label={t('ldy.prices.name')}><Input maxLength={80} onChange={(e) => setTForm({ ...tForm, name: e.target.value })} required value={tForm.name} /></FormField>
                            <FormField field="kind" error={action.fieldError('kind')} label={t('ldy.treat.kind')}><Select onChange={(e) => setTForm({ ...tForm, kind: e.target.value })} value={tForm.kind}><option value="service">{t('ldy.treat.kind.service')}</option><option value="express">{t('ldy.treat.kind.express')}</option></Select></FormField>
                            <FormField field="pricing" error={action.fieldError('pricing')} label={t('ldy.treat.pricing')}><Select onChange={(e) => setTForm({ ...tForm, pricing: e.target.value })} value={tForm.pricing}><option value="percent">{t('ldy.treat.pricing.percent')}</option><option value="fixed">{t('ldy.treat.pricing.fixed')}</option></Select></FormField>
                            <FormField field="value" error={action.fieldError('value')} label={t('ldy.treat.value')}><Input inputMode="decimal" onChange={(e) => setTForm({ ...tForm, value: e.target.value })} required value={tForm.value} /></FormField>
                            <FormField field="reason" error={action.fieldError('reason')} label={t('ldy.prices.reason')}><Input maxLength={300} onChange={(e) => setTForm({ ...tForm, reason: e.target.value })} required value={tForm.reason} /></FormField>
                        </form>
                    </div>
                </Dialog>
            )}
        </LaundryShell>
    );
}
