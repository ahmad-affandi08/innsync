import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { LaundryShell } from '@/modules/laundry/components/laundry-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Item = { id: string; code: string; name: string; unit_price_minor: number; is_active: boolean; lock_version: number };

export default function LaundryPricesPage({ currency, items, may }: { currency: string; items: Item[]; may: { prices: boolean } }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ code: '', name: '', price: '', reason: '' });
    const [edit, setEdit] = useState<Record<string, { price: string; reason: string }>>({});
    const [invalid, setInvalid] = useState(false);

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

    return (
        <LaundryShell description={t('ldy.prices.description')} title={t('ldy.prices.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {items.length === 0 ? <EmptyState title={t('ldy.prices.empty')} /> : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('ldy.prices.code')}</th><th scope="col">{t('ldy.prices.name')}</th><th scope="col">{t('ldy.prices.price')}</th><th scope="col">{t('ldy.prices.active')}</th>{may.prices ? <th scope="col" /> : null}</tr></thead>
                        <tbody>{items.map((i) => (
                            <tr className="border-t border-border align-top" key={i.id}>
                                <th className="py-2 font-medium" scope="row">{i.code}</th>
                                <td>{i.name}</td>
                                <td>{format.money(i.unit_price_minor, currency)}</td>
                                <td><StatusBadge label={i.is_active ? t('ldy.prices.active') : '—'} tone={i.is_active ? 'success' : 'neutral'} /></td>
                                {may.prices ? (
                                    <td>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Input aria-label={`${t('ldy.prices.price')} ${i.code}`} className="min-h-9 w-32" inputMode="decimal" onChange={(e) => setEdit({ ...edit, [i.id]: { price: e.target.value, reason: edit[i.id]?.reason ?? '' } })} placeholder={t('ldy.prices.price')} value={edit[i.id]?.price ?? ''} />
                                            <Input aria-label={`${t('ldy.prices.reason')} ${i.code}`} className="min-h-9 w-44" maxLength={300} onChange={(e) => setEdit({ ...edit, [i.id]: { price: edit[i.id]?.price ?? '', reason: e.target.value } })} placeholder={t('ldy.prices.reason')} value={edit[i.id]?.reason ?? ''} />
                                            <Button disabled={action.busy || (edit[i.id]?.reason ?? '').trim() === ''} onClick={() => void save(i, i.is_active)} size="sm" type="button" variant="outline">{t('ldy.prices.save')}</Button>
                                            <Button disabled={action.busy || (edit[i.id]?.reason ?? '').trim() === ''} onClick={() => void save(i, !i.is_active)} size="sm" type="button" variant="outline">{i.is_active ? t('ldy.prices.deactivate') : t('ldy.prices.activate')}</Button>
                                        </div>
                                    </td>
                                ) : null}
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            )}
            {invalid ? <p className="text-sm text-danger">{t('ldy.prices.invalid')}</p> : null}

            {may.prices && (
                <section aria-labelledby="add-h" className="flex flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="add-h">{t('ldy.prices.add')}</h2>
                    <form className="grid gap-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); void add(); }}>
                        <FormField error={action.fieldError('code')} label={t('ldy.prices.code')}><Input maxLength={20} onChange={(e) => setForm({ ...form, code: e.target.value })} required value={form.code} /></FormField>
                        <FormField error={action.fieldError('name')} label={t('ldy.prices.name')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} required value={form.name} /></FormField>
                        <FormField error={action.fieldError('unit_price_minor')} label={t('ldy.prices.price')}><Input inputMode="decimal" onChange={(e) => setForm({ ...form, price: e.target.value })} required value={form.price} /></FormField>
                        <FormField error={action.fieldError('reason')} label={t('ldy.prices.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} required value={form.reason} /></FormField>
                        <div className="sm:col-span-4"><Button loading={action.busy} type="submit">{t('ldy.prices.add')}</Button></div>
                    </form>
                </section>
            )}
        </LaundryShell>
    );
}
