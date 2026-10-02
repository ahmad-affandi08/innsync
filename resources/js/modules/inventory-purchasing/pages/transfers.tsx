import { router } from '@inertiajs/react';
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
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Line = { item_code: string; item_name: string; unit: string; unit_qty_milli: number; base_qty_milli: number; base_unit: string };
type Place = { id: string; code: string; name: string };
type Transfer = {
    id: string; number: string; status: string; note: string | null; decision_note: string | null; business_date: string; from: Place; to: Place; created_by_name: string | null; decided_by_name: string | null;
    created_at: string | null; decided_at: string | null; lock_version: number; may_receive: boolean; may_cancel: boolean; lines: Line[];
};
type CatalogItem = { id: string; code: string; name: string; base_unit: string; is_active: boolean; units: { unit: string; factor_milli: number }[] };
type Catalog = { items: CatalogItem[]; locations: { id: string; code: string; name: string; is_active: boolean }[] };

const tone: Record<string, StatusTone> = { sent: 'pending', received: 'success', rejected: 'danger', cancelled: 'neutral' };
const BLANK_LINE = { item_id: '', unit: '', quantity: '' };

export default function TransfersPage({ overview, catalog, status }: { overview: { transfers: Transfer[]; may: { send: boolean; receive: boolean } }; catalog: Catalog; status: string }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ from: string; to: string; note: string; lines: { item_id: string; unit: string; quantity: string }[] } | null>(null);
    const [openId, setOpenId] = useState<string | null>(null);
    const [rejecting, setRejecting] = useState('');
    const [showReject, setShowReject] = useState(false);
    const reload = ['overview'];
    const qty = (n: number) => formatMilli(n, locale);
    const label = (s: string) => t(`inv.trf.status.${s}` as MessageKey);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(form)]);
    const current = overview.transfers.find((x) => x.id === openId) ?? null;
    const activeItems = catalog.items.filter((i) => i.is_active);
    const activeLocations = catalog.locations.filter((l) => l.is_active);

    function openNew() {
        action.clear();
        const first = activeItems[0];
        setForm({ from: activeLocations[0]?.id ?? '', to: activeLocations[1]?.id ?? '', note: '', lines: [{ ...BLANK_LINE, item_id: first?.id ?? '', unit: first?.base_unit ?? '' }] });
    }

    function setLine(i: number, patch: Partial<typeof BLANK_LINE>) {
        if (form === null) return;
        setForm({ ...form, lines: form.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)) });
    }

    async function send() {
        if (form === null) return;
        const done = await action.run('/inventory/transfers', {
            body: { from_location_id: form.from, to_location_id: form.to, note: form.note || null, lines: form.lines.map((l) => ({ item_id: l.item_id, unit: l.unit, quantity: l.quantity })) },
            idempotencyKey: intent,
            reload,
        });
        if (done !== null) setForm(null);
    }

    async function decide(kind: 'receive' | 'reject' | 'cancel') {
        if (current === null) return;
        const body = kind === 'reject' ? { note: rejecting, lock_version: current.lock_version } : { lock_version: current.lock_version };
        const done = await action.run(`/inventory/transfers/${current.id}/${kind}`, { body, reload });
        if (done !== null) { setShowReject(false); setRejecting(''); }
    }

    const columns: DataGridColumn<Transfer>[] = [
        { id: 'number', label: t('inv.col.number'), value: (x) => x.number, rowHeader: true },
        { id: 'from', label: t('inv.col.from'), value: (x) => x.from.code, searchText: (x) => `${x.from.code} ${x.from.name}`, filter: 'select', cell: (x) => x.from.name },
        { id: 'to', label: t('inv.col.to'), value: (x) => x.to.code, searchText: (x) => `${x.to.code} ${x.to.name}`, filter: 'select', cell: (x) => x.to.name },
        { id: 'lines', label: t('inv.col.lines'), value: (x) => x.lines.map((l) => l.item_code).join(' '), cell: (x) => x.lines.map((l) => `${l.item_code} ${qty(l.unit_qty_milli)} ${l.unit}`).join(', ') },
        { id: 'date', label: t('inv.col.date'), value: (x) => x.business_date, cell: (x) => format.date(x.business_date) },
        { id: 'sentBy', label: t('inv.col.sentBy'), value: (x) => x.created_by_name ?? '', hidden: true },
        { id: 'state', label: t('inv.col.status'), value: (x) => x.status, filter: 'select', filterLabel: label, cell: (x) => <StatusBadge label={label(x.status)} tone={tone[x.status] ?? 'neutral'} /> },
        { id: 'actions', label: t('inv.col.actions'), cell: (x) => <Button onClick={() => { action.clear(); setShowReject(false); setOpenId(x.id); }} size="sm" type="button" variant="outline">{t('inv.trf.view')}</Button> },
    ];

    return (
        <InventoryShell actions={overview.may.send ? <Button onClick={openNew} type="button">{t('inv.trf.new')}</Button> : undefined} description={t('inv.trf.description')} title={t('inv.trf.title')} wide>
            <div className="max-w-xs">
                <Select aria-label={t('inv.col.status')} onChange={(e) => router.get('/inventory/transfers', e.target.value ? { status: e.target.value } : {}, { preserveScroll: true })} searchable={false} value={status}>
                    <option value="">{t('inv.trf.allStatuses')}</option>
                    {['sent', 'received', 'rejected', 'cancelled'].map((s) => <option key={s} value={s}>{label(s)}</option>)}
                </Select>
            </div>

            <DataGrid caption={t('inv.trf.title')} columns={columns} empty={<EmptyState title={t('inv.trf.empty')} />} getRowId={(x) => x.id} id="inv.transfers" rows={overview.transfers} testId="transfers" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void send()} type="button">{t('inv.trf.send')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.trf.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('inv.trf.hint')}</p>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('from_location_id')} field="from_location_id" label={t('inv.col.from')}>
                            <Select onChange={(e) => setForm({ ...form, from: e.target.value })} value={form.from}>
                                {activeLocations.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('to_location_id')} field="to_location_id" label={t('inv.col.to')}>
                            <Select onChange={(e) => setForm({ ...form, to: e.target.value })} value={form.to}>
                                {activeLocations.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}
                            </Select>
                        </FormField>
                        {form.lines.map((l, i) => {
                            const item = catalog.items.find((x) => x.id === l.item_id);

                            return (
                                <div className="grid gap-3 border-t border-border pt-3 sm:col-span-2 sm:grid-cols-[1fr_7rem_7rem_auto]" key={i}>
                                    <FormField error={i === 0 ? action.fieldError('lines') : undefined} field="lines.*.item_id" label={t('inv.col.lineItem')}>
                                        <Select onChange={(e) => { const next = catalog.items.find((x) => x.id === e.target.value); setLine(i, { item_id: e.target.value, unit: next?.base_unit ?? '' }); }} value={l.item_id}>
                                            {activeItems.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                                        </Select>
                                    </FormField>
                                    <FormField field="lines.*.unit" label={t('inv.col.unit')}>
                                        <Select onChange={(e) => setLine(i, { unit: e.target.value })} searchable={false} value={l.unit}>
                                            {item === undefined ? null : [item.base_unit, ...item.units.map((u) => u.unit)].map((u) => <option key={u} value={u}>{u}</option>)}
                                        </Select>
                                    </FormField>
                                    <FormField field="lines.*.quantity" label={t('inv.opening.quantity')}>
                                        <Input inputMode="decimal" onChange={(e) => setLine(i, { quantity: e.target.value })} value={l.quantity} />
                                    </FormField>
                                    <div className="flex items-end">{form.lines.length > 1 ? <Button onClick={() => setForm({ ...form, lines: form.lines.filter((_, j) => j !== i) })} size="sm" type="button" variant="outline">{t('inv.trf.removeLine')}</Button> : null}</div>
                                </div>
                            );
                        })}
                        <div className="sm:col-span-2"><Button onClick={() => setForm({ ...form, lines: [...form.lines, { ...BLANK_LINE, item_id: activeItems[0]?.id ?? '', unit: activeItems[0]?.base_unit ?? '' }] })} size="sm" type="button" variant="outline">{t('inv.trf.addLine')}</Button></div>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('note')} field="note" label={t('inv.opening.note')}>
                                <Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setOpenId(null)} type="button" variant="outline">{t('inv.trf.back')}</Button>
                    {current?.may_cancel ? <Button disabled={action.busy} onClick={() => void decide('cancel')} type="button" variant="outline">{t('inv.trf.cancel')}</Button> : null}
                    {current?.may_receive && !showReject ? <Button disabled={action.busy} onClick={() => setShowReject(true)} type="button" variant="outline">{t('inv.trf.reject')}</Button> : null}
                    {current?.may_receive && showReject ? <Button disabled={action.busy} loading={action.busy} onClick={() => void decide('reject')} type="button" variant="outline">{t('inv.trf.reject')}</Button> : null}
                    {current?.may_receive && !showReject ? <Button loading={action.busy} onClick={() => void decide('receive')} type="button">{t('inv.trf.confirm')}</Button> : null}
                </>}
                onClose={() => setOpenId(null)}
                open={current !== null}
                title={current === null ? '' : t('inv.trf.detail', { number: current.number })}
            >
                {current !== null && (
                    <div className="flex flex-col gap-3 text-sm">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p><StatusBadge label={label(current.status)} tone={tone[current.status] ?? 'neutral'} /></p>
                        <p>{current.from.name} → {current.to.name}</p>
                        <p className="text-muted-foreground">{t('inv.trf.sent')}: {current.created_by_name ?? '—'}{current.created_at ? ` · ${format.instant(current.created_at)}` : ''}</p>
                        {current.decided_by_name !== null ? <p className="text-muted-foreground">{current.status === 'received' ? t('inv.trf.received') : label(current.status)}: {current.decided_by_name}{current.decided_at ? ` · ${format.instant(current.decided_at)}` : ''}{current.decision_note ? ` · ${current.decision_note}` : ''}</p> : null}
                        {current.note ? <p>{current.note}</p> : null}
                        <ul className="flex flex-col gap-1 border-t border-border pt-3" data-testid="transfer-lines">
                            {current.lines.map((l) => <li key={l.item_code}>{l.item_code} · {l.item_name}: <strong>{qty(l.unit_qty_milli)} {l.unit}</strong>{l.unit !== l.base_unit ? ` (${qty(l.base_qty_milli)} ${l.base_unit})` : ''}</li>)}
                        </ul>
                        {showReject ? (
                            <FormField error={action.fieldError('note')} field="note" label={t('inv.trf.rejectReason')}>
                                <Input maxLength={200} onChange={(e) => setRejecting(e.target.value)} value={rejecting} />
                            </FormField>
                        ) : null}
                        {current.status === 'sent' && !current.may_receive && !current.may_cancel ? <Alert title={t('inv.trf.status.sent')} tone="info" /> : null}
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
