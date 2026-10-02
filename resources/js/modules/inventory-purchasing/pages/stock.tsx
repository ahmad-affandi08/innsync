import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli, parseMilli, plainMilli, toBaseMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Row = {
    item_id: string; item_code: string; item_name: string; category_name: string; department: string; base_unit: string; is_active: boolean;
    location_id: string; location_code: string; location_name: string; balance_milli: number; min_milli: number | null; max_milli: number | null; limit_lock: number | null; status: string; last_at: string | null;
};
type Movement = { id: string; kind: string; item_code: string; item_name: string; location_code: string; unit: string; unit_qty_milli: number; factor_milli: number; base_qty_milli: number; base_unit: string; reference: string | null; note: string | null; business_date: string; created_at: string };
type CatalogItem = { id: string; code: string; name: string; base_unit: string; is_active: boolean; units: { unit: string; factor_milli: number }[] };
type Catalog = { items: CatalogItem[]; locations: { id: string; code: string; name: string; is_active: boolean }[]; may: { manage: boolean; stock: boolean } };
type Position = { rows: Row[]; below_minimum: number; may: { post: boolean; limits: boolean } };

const tone: Record<string, StatusTone> = { below_minimum: 'danger', above_maximum: 'warning', ok: 'success' };

export default function StockPage({ position, movements, catalog, filters }: { position: Position; movements: Movement[]; catalog: Catalog; filters: { location: string; item: string } }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [opening, setOpening] = useState<{ item_id: string; location_id: string; unit: string; quantity: string; reference: string; note: string } | null>(null);
    const [limits, setLimits] = useState<{ row: Row; min: string; max: string } | null>(null);
    const [posted, setPosted] = useState(false);
    const reload = ['position', 'movements'];
    const qty = (n: number) => formatMilli(n, locale);

    function go(next: { location: string; item: string }) {
        router.get('/inventory/stock', { ...(next.location ? { location: next.location } : {}), ...(next.item ? { item: next.item } : {}) }, { preserveScroll: true });
    }

    function openOpening() {
        action.clear();
        setPosted(false);
        const item = catalog.items.find((i) => i.is_active);
        setOpening({ item_id: item?.id ?? '', location_id: catalog.locations.find((l) => l.is_active)?.id ?? '', unit: item?.base_unit ?? '', quantity: '', reference: '', note: '' });
    }

    const openingItem = opening === null ? null : catalog.items.find((i) => i.id === opening.item_id) ?? null;
    const factorOf = (unit: string) => (openingItem === null ? null : unit === openingItem.base_unit ? 1000 : (openingItem.units.find((u) => u.unit === unit)?.factor_milli ?? null));
    const preview = (() => {
        if (opening === null || openingItem === null) return null;
        const q = parseMilli(opening.quantity);
        const f = factorOf(opening.unit);

        return q === null || f === null ? null : toBaseMilli(q, f);
    })();

    async function postOpening() {
        if (opening === null) return;
        const done = await action.run('/inventory/stock/opening', { body: { item_id: opening.item_id, location_id: opening.location_id, unit: opening.unit, quantity: opening.quantity, reference: opening.reference || null, note: opening.note || null }, reload });
        if (done !== null) { setPosted(true); setOpening(null); }
    }

    async function saveLimits() {
        if (limits === null) return;
        const done = await action.run('/inventory/stock-limits', { body: { item_id: limits.row.item_id, location_id: limits.row.location_id, min: limits.min, max: limits.max === '' ? null : limits.max, lock_version: limits.row.limit_lock }, reload });
        if (done !== null) setLimits(null);
    }

    const columns: DataGridColumn<Row>[] = [
        { id: 'item', label: t('inv.col.item'), value: (r) => r.item_code, searchText: (r) => `${r.item_code} ${r.item_name}`, rowHeader: true, cell: (r) => <><span className="font-medium">{r.item_code}</span> <span className="text-muted-foreground">{r.item_name}</span></> },
        { id: 'location', label: t('inv.col.location'), value: (r) => r.location_code, searchText: (r) => `${r.location_code} ${r.location_name}`, filter: 'select', cell: (r) => r.location_name },
        { id: 'balance', label: t('inv.col.balance'), align: 'right', value: (r) => r.balance_milli, cell: (r) => `${qty(r.balance_milli)} ${r.base_unit}` },
        { id: 'min', label: t('inv.col.min'), align: 'right', value: (r) => r.min_milli, cell: (r) => (r.min_milli === null ? '—' : qty(r.min_milli)) },
        { id: 'max', label: t('inv.col.max'), align: 'right', value: (r) => r.max_milli, cell: (r) => (r.max_milli === null ? '—' : qty(r.max_milli)) },
        { id: 'category', label: t('inv.col.category'), value: (r) => r.category_name, filter: 'select', hidden: true },
        { id: 'status', label: t('inv.col.status'), value: (r) => r.status, filter: 'select', filterLabel: (v) => t((v === 'ok' ? 'inv.stock.ok' : `inv.stock.${v}`) as MessageKey), cell: (r) => <StatusBadge label={t((r.status === 'ok' ? 'inv.stock.ok' : `inv.stock.${r.status}`) as MessageKey)} tone={tone[r.status] ?? 'neutral'} /> },
        ...(position.may.limits ? [{ id: 'actions', label: t('inv.col.actions'), cell: (r: Row) => <Button onClick={() => { action.clear(); setLimits({ row: r, min: r.min_milli === null ? '' : plainMilli(r.min_milli), max: r.max_milli === null ? '' : plainMilli(r.max_milli) }); }} size="sm" type="button" variant="outline">{t('inv.action.limits')}</Button> }] : []),
    ];
    const movementColumns: DataGridColumn<Movement>[] = [
        { id: 'date', label: t('inv.col.date'), value: (m) => m.business_date, rowHeader: true, cell: (m) => format.date(m.business_date) },
        { id: 'item', label: t('inv.col.item'), value: (m) => m.item_code, searchText: (m) => `${m.item_code} ${m.item_name}`, cell: (m) => `${m.item_code} · ${m.item_name}` },
        { id: 'location', label: t('inv.col.location'), value: (m) => m.location_code, filter: 'select' },
        { id: 'kind', label: t('inv.col.kind'), value: (m) => m.kind, filter: 'select', filterLabel: (v) => t(`inv.stock.kind.${v}` as MessageKey), cell: (m) => t(`inv.stock.kind.${m.kind}` as MessageKey) },
        { id: 'qty', label: t('inv.col.quantity'), align: 'right', value: (m) => m.unit_qty_milli, cell: (m) => `${qty(m.unit_qty_milli)} ${m.unit}` },
        { id: 'base', label: t('inv.col.base'), align: 'right', value: (m) => m.base_qty_milli, cell: (m) => `${qty(m.base_qty_milli)} ${m.base_unit}` },
        { id: 'reference', label: t('inv.col.reference'), value: (m) => m.reference ?? '', hidden: true },
        { id: 'note', label: t('inv.col.note'), value: (m) => m.note ?? '', hidden: true },
    ];

    return (
        <InventoryShell actions={position.may.post ? <Button onClick={openOpening} type="button">{t('inv.stock.opening')}</Button> : undefined} description={t('inv.stock.description')} title={t('inv.stock.title')} wide>
            {position.below_minimum > 0 ? <Alert title={t('inv.stock.belowCount', { count: position.below_minimum })} tone="warning" /> : null}
            {posted ? <Alert title={t('inv.opening.posted')} tone="success" /> : null}

            <div className="grid max-w-3xl gap-3 sm:grid-cols-2">
                <Select aria-label={t('inv.col.location')} onChange={(e) => go({ ...filters, location: e.target.value })} value={filters.location}>
                    <option value="">{t('inv.stock.allLocations')}</option>
                    {catalog.locations.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}
                </Select>
                <Select aria-label={t('inv.col.item')} onChange={(e) => go({ ...filters, item: e.target.value })} value={filters.item}>
                    <option value="">{t('inv.stock.allItems')}</option>
                    {catalog.items.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name}</option>)}
                </Select>
            </div>

            <DataGrid caption={t('inv.stock.title')} columns={columns} empty={<EmptyState title={t('inv.stock.empty')} />} getRowId={(r) => `${r.item_id}:${r.location_id}`} id="inv.stock" rows={position.rows} testId="stock-position" />

            <section aria-labelledby="mov-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="mov-h">{t('inv.stock.movements')}</h2>
                <DataGrid caption={t('inv.stock.movements')} columns={movementColumns} empty={<EmptyState title={t('inv.stock.noMovements')} />} getRowId={(m) => m.id} id="inv.movements" rows={movements} testId="stock-movements" />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setOpening(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void postOpening()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setOpening(null)}
                open={opening !== null}
                title={t('inv.opening.title')}
            >
                {opening !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('inv.opening.hint')}</p>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('item_id')} field="item_id" label={t('inv.opening.item')}>
                            <Select onChange={(e) => { const next = catalog.items.find((i) => i.id === e.target.value); setOpening({ ...opening, item_id: e.target.value, unit: next?.base_unit ?? '' }); }} value={opening.item_id}>
                                {catalog.items.filter((i) => i.is_active).map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('location_id')} field="location_id" label={t('inv.opening.location')}>
                            <Select onChange={(e) => setOpening({ ...opening, location_id: e.target.value })} value={opening.location_id}>
                                {catalog.locations.filter((l) => l.is_active).map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('quantity')} field="quantity" label={t('inv.opening.quantity')}>
                            <Input inputMode="decimal" onChange={(e) => setOpening({ ...opening, quantity: e.target.value })} value={opening.quantity} />
                        </FormField>
                        <FormField error={action.fieldError('unit')} field="unit" label={t('inv.opening.unit')}>
                            <Select onChange={(e) => setOpening({ ...opening, unit: e.target.value })} value={opening.unit}>
                                {openingItem === null ? null : [openingItem.base_unit, ...openingItem.units.map((u) => u.unit)].map((u) => <option key={u} value={u}>{u}</option>)}
                            </Select>
                        </FormField>
                        {preview !== null && openingItem !== null ? <p className="text-sm sm:col-span-2" data-testid="opening-preview">{t('inv.opening.equals', { qty: qty(preview), unit: openingItem.base_unit })}</p> : null}
                        <FormField error={action.fieldError('reference')} field="reference" label={t('inv.opening.reference')}>
                            <Input maxLength={40} onChange={(e) => setOpening({ ...opening, reference: e.target.value })} value={opening.reference} />
                        </FormField>
                        <FormField error={action.fieldError('note')} field="note" label={t('inv.opening.note')}>
                            <Input maxLength={200} onChange={(e) => setOpening({ ...opening, note: e.target.value })} value={opening.note} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setLimits(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void saveLimits()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setLimits(null)}
                open={limits !== null}
                title={limits === null ? '' : `${t('inv.limits.title')}: ${limits.row.item_code} · ${limits.row.location_code}`}
            >
                {limits !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('inv.limits.hint')}</p>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('min')} field="min" label={`${t('inv.limits.min')} (${limits.row.base_unit})`}>
                            <Input inputMode="decimal" onChange={(e) => setLimits({ ...limits, min: e.target.value })} value={limits.min} />
                        </FormField>
                        <FormField error={action.fieldError('max')} field="max" label={`${t('inv.limits.max')} (${limits.row.base_unit})`}>
                            <Input inputMode="decimal" onChange={(e) => setLimits({ ...limits, max: e.target.value })} value={limits.max} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
