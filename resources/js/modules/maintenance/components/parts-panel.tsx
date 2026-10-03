import { useCallback, useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { formatMilli, parseMilli } from '@/modules/kitchen/lib/kitchen';
import type { PartsPanel as Panel, Priority } from '@/modules/maintenance/lib/maintenance';
import { apiRequest, newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const REQUEST_TONE: Record<string, StatusTone> = { draft: 'neutral', pending_approval: 'pending', approved: 'success', rejected: 'danger', cancelled: 'neutral', ordered: 'info' };

type Line = { itemId: string; unit: string; quantity: string };

/** The spare parts of one work order: what went into it and what it cost, the form to record a part, and the purchase requests started from it. */
export function PartsPanel({ workOrderId, status, onChanged }: { workOrderId: string; status: string; onChanged: () => void }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [panel, setPanel] = useState<Panel | null>(null);
    const [use, setUse] = useState({ locationId: '', itemId: '', unit: '', quantity: '', note: '' });
    const [asking, setAsking] = useState(false);
    const [ask, setAsk] = useState<{ urgency: Priority; reason: string; neededBy: string; lines: Line[] }>({ urgency: 'normal', reason: '', neededBy: '', lines: [{ itemId: '', unit: '', quantity: '' }] });

    const load = useCallback(async () => {
        try {
            const next = await apiRequest<Panel>(`/maintenance/work-orders/${workOrderId}/parts`, { method: 'GET' });

            setPanel(next);
            setUse((u) => ({ ...u, locationId: u.locationId === '' ? (next.locations.find((l) => l.kind === 'engineering') ?? next.locations[0])?.id ?? '' : u.locationId }));
            setAsk((a) => ({ ...a, neededBy: a.neededBy === '' ? next.business_date : a.neededBy }));
        } catch {
            setPanel(null);
        }
    }, [workOrderId]);

    useEffect(() => {
        void load();
    }, [load, status]);

    if (panel === null) return null;
    const money = (minor: number) => format.money(minor, panel.currency);
    const unitsOf = (itemId: string) => panel.items.find((i) => i.id === itemId)?.units ?? [];
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const quantity = parseMilli(use.quantity);

    async function record() {
        const result = await action.run<Panel>(`/maintenance/work-orders/${workOrderId}/parts`, { idempotencyKey: newIdempotencyKey(), body: { item_id: use.itemId, location_id: use.locationId, unit: use.unit, quantity_milli: parseMilli(use.quantity), note: use.note.trim() === '' ? null : use.note.trim() } });

        if (result !== null) {
            setPanel(result);
            setUse({ ...use, itemId: '', unit: '', quantity: '', note: '' });
            onChanged();
        }
    }

    async function send() {
        const result = await action.run<Panel>(`/maintenance/work-orders/${workOrderId}/part-requests`, {
            idempotencyKey: newIdempotencyKey(),
            body: { urgency: ask.urgency, reason: ask.reason.trim(), needed_by: ask.neededBy, lines: ask.lines.map((l) => ({ item_id: l.itemId, unit: l.unit, quantity_milli: parseMilli(l.quantity) })) },
        });

        if (result !== null) {
            setPanel(result);
            onChanged();
            setAsking(false);
            setAsk({ ...ask, reason: '', lines: [{ itemId: '', unit: '', quantity: '' }] });
        }
    }

    const setLine = (i: number, patch: Partial<Line>) => setAsk({ ...ask, lines: ask.lines.map((l, n) => (n === i ? { ...l, ...patch } : l)) });
    const askInvalid = ask.reason.trim() === '' || ask.lines.some((l) => l.itemId === '' || l.unit === '' || parseMilli(l.quantity) === null);

    return (
        <section aria-labelledby="mtc-parts-h" className="flex flex-col gap-3 border-t border-border pt-3" data-testid="mtc-parts">
            <h3 className="text-sm font-semibold" id="mtc-parts-h">{t('mtc.parts.title')}</h3>
            {failure}
            {panel.uses.length === 0 ? <p className="text-sm text-muted-foreground">{t('mtc.parts.empty')}</p> : (
                <ul className="flex flex-col gap-1 text-sm">
                    {panel.uses.map((u) => <li key={u.id}>{formatMilli(u.quantity_milli)} {u.unit} · {u.item_name} <span className="text-muted-foreground">({u.item_code} · {u.location})</span> · {u.value_minor === null ? t('mtc.parts.noCost') : money(u.value_minor)}{u.note !== null ? ` · ${u.note}` : ''} <span className="text-muted-foreground">· {u.by ?? '—'}</span></li>)}
                    <li className="font-semibold">{t('mtc.parts.total')}: {money(panel.total_minor)}{!panel.complete ? <span className="ml-2 text-xs font-normal text-muted-foreground">{t('mtc.parts.partial')}</span> : null}</li>
                </ul>
            )}

            {panel.may.use ? (
                <div className="grid gap-3 sm:grid-cols-2" data-testid="mtc-parts-use">
                    <FormField error={action.fieldError('location_id')} field="location_id" label={t('mtc.parts.location')}><Select onChange={(e) => setUse({ ...use, locationId: e.target.value })} value={use.locationId}>{panel.locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select></FormField>
                    <FormField error={action.fieldError('item_id')} field="item_id" label={t('mtc.parts.item')}><Select onChange={(e) => setUse({ ...use, itemId: e.target.value, unit: unitsOf(e.target.value)[0] ?? '' })} value={use.itemId}><option value="">—</option>{panel.items.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name}</option>)}</Select></FormField>
                    <FormField error={action.fieldError('quantity_milli')} field="quantity_milli" label={t('mtc.parts.quantity')}><Input inputMode="decimal" onChange={(e) => setUse({ ...use, quantity: e.target.value })} value={use.quantity} /></FormField>
                    <FormField error={action.fieldError('unit')} field="unit" label={t('mtc.parts.unit')}><Select onChange={(e) => setUse({ ...use, unit: e.target.value })} value={use.unit}>{unitsOf(use.itemId).map((u) => <option key={u} value={u}>{u}</option>)}</Select></FormField>
                    <div className="sm:col-span-2"><FormField error={action.fieldError('note')} field="note" label={t('mtc.parts.note')}><Input maxLength={200} onChange={(e) => setUse({ ...use, note: e.target.value })} value={use.note} /></FormField></div>
                    <div><Button disabled={action.busy || use.itemId === '' || use.unit === '' || quantity === null} onClick={() => void record()} size="sm" type="button">{t('mtc.parts.record')}</Button></div>
                </div>
            ) : null}

            <div className="flex flex-col gap-2" data-testid="mtc-parts-requests">
                <h4 className="text-sm font-semibold">{t('mtc.parts.requests')}</h4>
                {panel.requests.length === 0 ? <p className="text-sm text-muted-foreground">{t('mtc.parts.noRequests')}</p> : (
                    <ul className="flex flex-col gap-1 text-sm">
                        {panel.requests.map((r) => <li className="flex flex-wrap items-center gap-2" key={r.id}>{r.number}{r.status !== null ? <StatusBadge label={t(`mtc.parts.pr.${r.status}` as MessageKey)} tone={REQUEST_TONE[r.status] ?? 'neutral'} /> : null}{r.total_minor !== null && r.total_minor > 0 ? money(r.total_minor) : null}<span className="text-muted-foreground">· {r.by ?? '—'}</span></li>)}
                    </ul>
                )}
                {panel.may.request && !asking ? <div><Button onClick={() => setAsking(true)} size="sm" type="button" variant="outline">{t('mtc.parts.ask')}</Button></div> : null}
                {asking ? (
                    <div className="flex flex-col gap-3 border border-border p-3">
                        <p className="text-xs text-muted-foreground">{t('mtc.parts.askHint')}</p>
                        {ask.lines.map((l, i) => (
                            <div className="grid gap-2 sm:grid-cols-[2fr_1fr_1fr_auto]" key={i}>
                                <FormField error={i === 0 ? action.fieldError('lines') : undefined} label={t('mtc.parts.item')}><Select onChange={(e) => setLine(i, { itemId: e.target.value, unit: unitsOf(e.target.value)[0] ?? '' })} value={l.itemId}><option value="">—</option>{panel.items.map((it) => <option key={it.id} value={it.id}>{it.code} · {it.name}</option>)}</Select></FormField>
                                <FormField label={t('mtc.parts.quantity')}><Input inputMode="decimal" onChange={(e) => setLine(i, { quantity: e.target.value })} value={l.quantity} /></FormField>
                                <FormField label={t('mtc.parts.unit')}><Select onChange={(e) => setLine(i, { unit: e.target.value })} value={l.unit}>{unitsOf(l.itemId).map((u) => <option key={u} value={u}>{u}</option>)}</Select></FormField>
                                <div className="flex items-end">{ask.lines.length > 1 ? <Button aria-label={t('mtc.parts.removeLine')} onClick={() => setAsk({ ...ask, lines: ask.lines.filter((_, n) => n !== i) })} size="sm" type="button" variant="outline">×</Button> : null}</div>
                            </div>
                        ))}
                        <div><Button onClick={() => setAsk({ ...ask, lines: [...ask.lines, { itemId: '', unit: '', quantity: '' }] })} size="sm" type="button" variant="outline">{t('mtc.parts.addLine')}</Button></div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={action.fieldError('reason')} field="reason" label={t('mtc.parts.reason')}><Input maxLength={160} onChange={(e) => setAsk({ ...ask, reason: e.target.value })} value={ask.reason} /></FormField>
                            <FormField error={action.fieldError('urgency')} field="urgency" label={t('mtc.parts.urgency')}><Select onChange={(e) => setAsk({ ...ask, urgency: e.target.value as Priority })} value={ask.urgency}>{panel.urgencies.map((u) => <option key={u} value={u}>{t(`mtc.priority.${u}` as MessageKey)}</option>)}</Select></FormField>
                            <FormField error={action.fieldError('needed_by')} field="needed_by" label={t('mtc.parts.neededBy')}><DatePicker onChange={(e) => setAsk({ ...ask, neededBy: e.target.value })} value={ask.neededBy} /></FormField>
                        </div>
                        <div className="flex gap-2">
                            <Button disabled={action.busy || askInvalid} onClick={() => void send()} size="sm" type="button">{t('mtc.parts.send')}</Button>
                            <Button disabled={action.busy} onClick={() => setAsking(false)} size="sm" type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                        </div>
                    </div>
                ) : null}
            </div>
        </section>
    );
}
