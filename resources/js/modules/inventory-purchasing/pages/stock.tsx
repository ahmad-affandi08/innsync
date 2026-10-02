import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Row = {
    item_id: string; item_code: string; item_name: string; category_name: string; department: string; base_unit: string; is_active: boolean;
    location_id: string; location_code: string; location_name: string; balance_milli: number; min_milli: number | null; max_milli: number | null; limit_lock: number | null; status: string; last_at: string | null;
};
type Movement = { id: string; kind: string; reason_code: string | null; override_reason: string | null; item_code: string; item_name: string; location_code: string; unit: string; unit_qty_milli: number; factor_milli: number; base_qty_milli: number; base_unit: string; reference: string | null; note: string | null; business_date: string; created_at: string };
type CatalogItem = { id: string; code: string; name: string; base_unit: string; is_active: boolean; units: { unit: string; factor_milli: number }[] };
type Catalog = { items: CatalogItem[]; locations: { id: string; code: string; name: string; is_active: boolean }[]; may: { manage: boolean; stock: boolean } };
type Position = { reasons: { adjust: string[]; write_off: string[]; departments: string[] }; rows: Row[]; below_minimum: number; may: { post: boolean; adjust: boolean; negative: boolean; transfer: boolean; limits: boolean } };
type Move = { kind: string; item_id: string; location_id: string; unit: string; quantity: string; reason_code: string; reference: string; note: string; negative_reason: string };

const OUTFLOWS = ['issue', 'adjustment_out', 'write_off'];

const tone: Record<string, StatusTone> = { below_minimum: 'danger', above_maximum: 'warning', ok: 'success' };

export default function StockPage({ position, movements, catalog, filters }: { position: Position; movements: Movement[]; catalog: Catalog; filters: { location: string; item: string } }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [move, setMove] = useState<Move | null>(null);
    const [limits, setLimits] = useState<{ row: Row; min: string; max: string } | null>(null);
    const [posted, setPosted] = useState(false);
    const reload = ['position', 'movements'];
    const qty = (n: number) => formatMilli(n, locale);
    const kinds = [...(position.may.post ? ['opening', 'receipt', 'issue'] : []), ...(position.may.adjust ? ['adjustment_in', 'adjustment_out', 'write_off'] : [])];
    const kindLabel = (k: string) => t(`inv.stock.kind.${k}` as MessageKey);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(move)]);

    function go(next: { location: string; item: string }) {
        router.get('/inventory/stock', { ...(next.location ? { location: next.location } : {}), ...(next.item ? { item: next.item } : {}) }, { preserveScroll: true });
    }

    function openMove() {
        action.clear();
        setPosted(false);
        const item = catalog.items.find((i) => i.is_active);
        setMove({ kind: kinds[0] ?? 'receipt', item_id: item?.id ?? '', location_id: catalog.locations.find((l) => l.is_active)?.id ?? '', unit: item?.base_unit ?? '', quantity: '', reason_code: '', reference: '', note: '', negative_reason: '' });
    }

    const moveItem = move === null ? null : catalog.items.find((i) => i.id === move.item_id) ?? null;
    const factorOf = (unit: string) => (moveItem === null ? null : unit === moveItem.base_unit ? 1000 : (moveItem.units.find((u) => u.unit === unit)?.factor_milli ?? null));
    const preview = (() => {
        if (move === null || moveItem === null) return null;
        const q = parseMilli(move.quantity);
        const f = factorOf(move.unit);

        return q === null || f === null ? null : toBaseMilli(q, f);
    })();
    const reasons = move === null ? [] : move.kind === 'issue' ? position.reasons.departments : move.kind === 'write_off' ? position.reasons.write_off : move.kind.startsWith('adjustment') ? position.reasons.adjust : [];
    const reasonLabel = (code: string) => t((move?.kind === 'issue' ? `inv.dept.${code}` : `inv.reason.${code}`) as MessageKey);

    async function postMove() {
        if (move === null) return;
        const done = await action.run('/inventory/stock/movements', {
            body: { kind: move.kind, item_id: move.item_id, location_id: move.location_id, unit: move.unit, quantity: move.quantity, reason_code: move.reason_code || null, reference: move.reference || null, note: move.note || null, negative_reason: move.negative_reason || null },
            idempotencyKey: intent,
            reload,
        });
        if (done !== null) { setPosted(true); setMove(null); }
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
        { id: 'kind', label: t('inv.col.kind'), value: (m) => m.kind, filter: 'select', filterLabel: kindLabel, cell: (m) => kindLabel(m.kind) },
        { id: 'reasonCode', label: t('inv.col.reasonCode'), value: (m) => m.reason_code ?? '', hidden: true },
        { id: 'qty', label: t('inv.col.quantity'), align: 'right', value: (m) => m.unit_qty_milli, cell: (m) => `${qty(m.unit_qty_milli)} ${m.unit}` },
        { id: 'base', label: t('inv.col.base'), align: 'right', value: (m) => m.base_qty_milli, cell: (m) => `${qty(m.base_qty_milli)} ${m.base_unit}` },
        { id: 'reference', label: t('inv.col.reference'), value: (m) => m.reference ?? '', hidden: true },
        { id: 'note', label: t('inv.col.note'), value: (m) => m.note ?? '', hidden: true },
    ];

    return (
        <InventoryShell actions={<>{position.may.transfer ? <Button asChild variant="outline"><Link href="/inventory/transfers">{t('inv.stock.transfer')}</Link></Button> : null}{kinds.length > 0 ? <Button onClick={openMove} type="button">{t('inv.stock.post')}</Button> : null}</>} description={t('inv.stock.description')} title={t('inv.stock.title')} wide>
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
                    <Button disabled={action.busy} onClick={() => setMove(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void postMove()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setMove(null)}
                open={move !== null}
                title={t('inv.move.title')}
            >
                {move !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {move.kind === 'opening' ? <p className="text-sm text-muted-foreground sm:col-span-2">{t('inv.opening.hint')}</p> : null}
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('kind')} field="kind" label={t('inv.col.kind')}>
                                <Select onChange={(e) => setMove({ ...move, kind: e.target.value, reason_code: '' })} searchable={false} value={move.kind}>
                                    {kinds.map((k) => <option key={k} value={k}>{kindLabel(k)}</option>)}
                                </Select>
                            </FormField>
                        </div>
                        <FormField error={action.fieldError('item_id')} field="item_id" label={t('inv.opening.item')}>
                            <Select onChange={(e) => { const next = catalog.items.find((i) => i.id === e.target.value); setMove({ ...move, item_id: e.target.value, unit: next?.base_unit ?? '' }); }} value={move.item_id}>
                                {catalog.items.filter((i) => i.is_active).map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('location_id')} field="location_id" label={t('inv.opening.location')}>
                            <Select onChange={(e) => setMove({ ...move, location_id: e.target.value })} value={move.location_id}>
                                {catalog.locations.filter((l) => l.is_active).map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('quantity')} field="quantity" label={t('inv.opening.quantity')}>
                            <Input inputMode="decimal" onChange={(e) => setMove({ ...move, quantity: e.target.value })} value={move.quantity} />
                        </FormField>
                        <FormField error={action.fieldError('unit')} field="unit" label={t('inv.opening.unit')}>
                            <Select onChange={(e) => setMove({ ...move, unit: e.target.value })} value={move.unit}>
                                {moveItem === null ? null : [moveItem.base_unit, ...moveItem.units.map((u) => u.unit)].map((u) => <option key={u} value={u}>{u}</option>)}
                            </Select>
                        </FormField>
                        {preview !== null && moveItem !== null ? <p className="text-sm sm:col-span-2" data-testid="opening-preview">{t(OUTFLOWS.includes(move.kind) ? 'inv.move.out' : 'inv.move.in', { qty: qty(preview), unit: moveItem.base_unit })}</p> : null}
                        {reasons.length > 0 ? (
                            <div className="sm:col-span-2">
                                <FormField error={action.fieldError('reason_code')} field="reason_code" label={t(move.kind === 'issue' ? 'inv.move.department' : 'inv.move.reason')}>
                                    <Select onChange={(e) => setMove({ ...move, reason_code: e.target.value })} value={move.reason_code}>
                                        <option value="">—</option>
                                        {reasons.map((c) => <option key={c} value={c}>{reasonLabel(c)}</option>)}
                                    </Select>
                                </FormField>
                            </div>
                        ) : null}
                        <FormField error={action.fieldError('reference')} field="reference" label={t('inv.opening.reference')}>
                            <Input maxLength={40} onChange={(e) => setMove({ ...move, reference: e.target.value })} value={move.reference} />
                        </FormField>
                        <FormField error={action.fieldError('note')} field="note" label={t('inv.opening.note')}>
                            <Input maxLength={200} onChange={(e) => setMove({ ...move, note: e.target.value })} value={move.note} />
                        </FormField>
                        {OUTFLOWS.includes(move.kind) && position.may.negative ? (
                            <div className="sm:col-span-2">
                                <FormField error={action.fieldError('negative_reason')} field="negative_reason" hint={t('inv.move.negativeHint')} label={t('inv.move.negative')}>
                                    <Input maxLength={200} onChange={(e) => setMove({ ...move, negative_reason: e.target.value })} value={move.negative_reason} />
                                </FormField>
                            </div>
                        ) : null}
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
