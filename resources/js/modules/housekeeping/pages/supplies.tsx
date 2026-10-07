import { useState, type FormEvent } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Use = { id: string; item_code: string; item_name: string; location: string; unit: string; quantity_milli: number; note: string | null; by: string; business_date: string; at: string };
type Overview = { items: { id: string; code: string; name: string; base_unit: string }[]; locations: { id: string; code: string; name: string }[]; recent: Use[]; may: { use: boolean } };

/** The cleaning supplies and amenities housekeeping uses up, taken from the stock card of a store (FR-INV-004). */
export default function SuppliesPage({ overview }: { overview: Overview }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ itemId: overview.items[0]?.id ?? '', locationId: overview.locations[0]?.id ?? '', quantity: '', note: '' });
    const [left, setLeft] = useState<number | null>(null);
    const item = overview.items.find((i) => i.id === form.itemId);
    const qty = (n: number) => (n / 1000).toLocaleString(locale, { maximumFractionDigits: 3 });

    async function submit(event: FormEvent) {
        event.preventDefault();
        const done = await action.run<{ use: { balance_milli: number } }>('/housekeeping/supplies', { body: { item_id: form.itemId, location_id: form.locationId, unit: item?.base_unit ?? '', quantity: form.quantity, note: form.note.trim() || null }, idempotencyKey: newIdempotencyKey(), reload: ['overview'] });

        if (done !== null) {
            setLeft(done.use.balance_milli);
            setForm({ ...form, quantity: '', note: '' });
        }
    }

    const columns: DataGridColumn<Use>[] = [
        { id: 'at', label: t('hk.sup.when'), value: (u) => u.at, rowHeader: true, cell: (u) => format.instant(u.at) },
        { id: 'item', label: t('hk.sup.item'), value: (u) => u.item_code, searchText: (u) => `${u.item_code} ${u.item_name}`, cell: (u) => `${u.item_code} · ${u.item_name}` },
        { id: 'location', label: t('hk.sup.location'), value: (u) => u.location, filter: 'select' },
        { id: 'qty', label: t('hk.sup.quantity'), align: 'right', value: (u) => u.quantity_milli, cell: (u) => `${qty(u.quantity_milli)} ${u.unit}` },
        { id: 'by', label: t('hk.sup.by'), value: (u) => u.by },
        { id: 'note', label: t('hk.sup.note'), value: (u) => u.note ?? '' },
    ];

    return (
        <HousekeepingShell description={t('hk.sup.description')} title={t('hk.sup.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {overview.may.use ? (
                overview.items.length === 0 ? <Alert title={t('hk.sup.noItems')} tone="warning" /> : (
                    <form className="grid gap-3 border border-border bg-surface p-4 sm:grid-cols-2" data-testid="supply-form" onSubmit={(e) => void submit(e)}>
                        <FormField error={action.fieldError('item_id')} field="item_id" label={t('hk.sup.item')}><Select onChange={(e) => setForm({ ...form, itemId: e.target.value })} value={form.itemId}>{overview.items.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name} ({i.base_unit})</option>)}</Select></FormField>
                        <FormField error={action.fieldError('location_id')} field="location_id" label={t('hk.sup.location')}><Select onChange={(e) => setForm({ ...form, locationId: e.target.value })} value={form.locationId}>{overview.locations.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('quantity')} field="quantity" label={`${t('hk.sup.quantity')} (${item?.base_unit ?? ''})`}><Input inputMode="decimal" onChange={(e) => setForm({ ...form, quantity: e.target.value })} value={form.quantity} /></FormField>
                        <FormField error={action.fieldError('note')} field="note" label={t('hk.sup.note')}><Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} /></FormField>
                        <div className="flex items-center gap-3 sm:col-span-2">
                            <Button disabled={form.quantity.trim() === ''} loading={action.busy} type="submit">{t('hk.sup.record')}</Button>
                            {left !== null ? <p aria-live="polite" className="text-sm text-success" data-testid="supply-left">{t('hk.sup.left', { qty: qty(left), unit: item?.base_unit ?? '' })}</p> : null}
                        </div>
                    </form>
                )
            ) : null}
            <DataGrid caption={t('hk.sup.recent')} columns={columns} empty={<EmptyState illustration="checklist" title={t('hk.sup.none')} />} getRowId={(u) => u.id} id="hk.supplies" rows={overview.recent} testId="housekeeping-supplies" />
        </HousekeepingShell>
    );
}
