import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Level = { id: string; item_id: string; item_code: string; item_name: string; scope_kind: 'room_type' | 'area'; room_type_id: string | null; area: string | null; par_quantity: number; use_quantity: number; lock_version: number };
type Need = { item_id: string; code: string; name: string; unit: string; target: number; on_floor: number; need: number; in_store: number; from_store: number; to_obtain: number };
type Overview = {
    items: { id: string; code: string; name: string; kind: string; unit: string }[]; room_types: { id: string; code: string; name: string; rooms: number }[]; areas: string[]; levels: Level[]; replenishment: Need[];
    shifts: { code: string; from: string; to: string }[]; business_date: string; may: { manage: boolean };
};
type Row = { item_id: string; code: string; name: string; room_type: string; rooms_serviced: number; standard: number; expected: number; actual: number; variance: number };
type Consumption = { date: string; shift: string; rows: Row[] };

/** Par levels of linen and amenities, what to bring to the floor and consumption by shift (FR-HK-019). */
export default function ParLevelsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ itemId: overview.items[0]?.id ?? '', kind: 'room_type', ref: overview.room_types[0]?.id ?? '', par: '', use: '0', reason: '' });
    const [when, setWhen] = useState({ date: overview.business_date, shift: 'morning' });
    const [shown, setShown] = useState<Consumption | null>(null);
    const typeCode = (id: string | null) => overview.room_types.find((x) => x.id === id)?.code ?? '';

    async function save() {
        const existing = overview.levels.find((l) => l.item_id === form.itemId && l.scope_kind === form.kind && (form.kind === 'area' ? l.area === form.ref.trim() : l.room_type_id === form.ref));
        const done = await action.run('/housekeeping/par-levels', {
            body: { item_id: form.itemId, scope_kind: form.kind, scope_ref: form.ref.trim(), par_quantity: Number(form.par), use_quantity: form.kind === 'area' ? 0 : Number(form.use), lock_version: existing === undefined ? null : existing.lock_version, reason: form.reason.trim() },
            reload: ['overview'],
        });
        if (done !== null) setForm({ ...form, par: '', reason: '' });
    }

    async function show() {
        const query = new URLSearchParams({ date: when.date, shift: when.shift }).toString();
        const result = await action.run<{ consumption: Consumption }>(`/housekeeping/par-levels/consumption?${query}`, { method: 'GET' });
        if (result !== null) setShown(result.consumption);
    }

    function edit(l: Level) {
        action.clear();
        setForm({ itemId: l.item_id, kind: l.scope_kind, ref: l.scope_kind === 'area' ? (l.area ?? '') : (l.room_type_id ?? ''), par: String(l.par_quantity), use: String(l.use_quantity), reason: '' });
    }

    return (
        <HousekeepingShell description={t('hk.par.description')} title={t('hk.par.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <section aria-labelledby="par-need-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="par-need-h">{t('hk.par.need')}</h2>
                {overview.replenishment.length === 0 ? <EmptyState title={t('hk.par.noNeed')} /> : (
                    <table className="w-full text-left text-sm" data-testid="need">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('hk.par.item')}</th><th scope="col">{t('hk.par.target')}</th><th scope="col">{t('hk.par.onFloor')}</th><th scope="col">{t('hk.par.toBring')}</th><th scope="col">{t('hk.par.inStore')}</th><th scope="col">{t('hk.par.fromStore')}</th><th scope="col">{t('hk.par.toObtain')}</th></tr></thead>
                        <tbody>{overview.replenishment.map((n) => (
                            <tr className="border-t border-border" key={n.item_id}>
                                <th className="py-2 font-medium" scope="row">{n.code} · {n.name}</th><td>{format.number(n.target)}</td><td>{format.number(n.on_floor)}</td><td className="font-medium">{format.number(n.need)}</td>
                                <td>{format.number(n.in_store)}</td><td>{format.number(n.from_store)}</td><td className={n.to_obtain > 0 ? 'font-medium text-danger' : undefined}>{format.number(n.to_obtain)}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                )}
            </section>

            <section aria-labelledby="par-use-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="par-use-h">{t('hk.par.consumption')}</h2>
                <p className="text-sm text-muted-foreground">{t('hk.par.shiftNote')}</p>
                <div className="flex flex-wrap items-end gap-2">
                    <FormField label={t('hk.par.date')}><Input onChange={(e) => setWhen({ ...when, date: e.target.value })} type="date" value={when.date} /></FormField>
                    <FormField label={t('hk.par.shift')}><Select onChange={(e) => setWhen({ ...when, shift: e.target.value })} value={when.shift}>{overview.shifts.map((s) => <option key={s.code} value={s.code}>{t(`hk.par.shift.${s.code}` as 'hk.par.shift.morning')} ({s.from}–{s.to})</option>)}</Select></FormField>
                    <Button disabled={action.busy} onClick={() => void show()} type="button" variant="outline">{t('hk.par.show')}</Button>
                </div>
                {shown === null ? null : shown.rows.length === 0 ? <p className="text-sm text-muted-foreground" data-testid="use-empty">{t('hk.par.noUse')}</p> : (
                    <table className="w-full text-left text-sm" data-testid="use">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('hk.par.item')}</th><th scope="col">{t('hk.par.roomType')}</th><th scope="col">{t('hk.par.serviced')}</th><th scope="col">{t('hk.par.standard')}</th><th scope="col">{t('hk.par.expected')}</th><th scope="col">{t('hk.par.actual')}</th><th scope="col">{t('hk.par.variance')}</th></tr></thead>
                        <tbody>{shown.rows.map((r) => (
                            <tr className="border-t border-border" key={`${r.item_id}${r.room_type}`}>
                                <th className="py-2 font-medium" scope="row">{r.code} · {r.name}</th><td>{r.room_type}</td><td>{r.rooms_serviced}</td><td>{r.standard}</td><td>{r.expected}</td><td>{r.actual}</td>
                                <td className={r.variance > 0 ? 'font-medium text-danger' : r.variance < 0 ? 'font-medium text-warning' : undefined}>{r.variance > 0 ? `+${r.variance}` : r.variance}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                )}
            </section>

            <section aria-labelledby="par-set-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="par-set-h">{t('hk.par.levels')}</h2>
                {overview.levels.length === 0 ? <EmptyState title={t('hk.par.none')} /> : (
                    <table className="w-full text-left text-sm" data-testid="levels">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('hk.par.item')}</th><th scope="col">{t('hk.par.scope')}</th><th scope="col">{t('hk.par.par')}</th><th scope="col">{t('hk.par.standard')}</th>{overview.may.manage ? <th scope="col" /> : null}</tr></thead>
                        <tbody>{overview.levels.map((l) => (
                            <tr className="border-t border-border" key={l.id}>
                                <th className="py-2 font-medium" scope="row">{l.item_code} · {l.item_name}</th>
                                <td>{l.scope_kind === 'room_type' ? `${t('hk.par.roomType')} ${typeCode(l.room_type_id)}` : `${t('hk.par.area')} ${l.area}`}</td><td>{l.par_quantity}</td><td>{l.scope_kind === 'room_type' ? l.use_quantity : '—'}</td>
                                {overview.may.manage ? <td><Button aria-label={`${t('hk.par.edit')} ${l.item_code} ${l.scope_kind === 'room_type' ? typeCode(l.room_type_id) : l.area}`} onClick={() => edit(l)} size="sm" type="button" variant="outline">{t('hk.par.edit')}</Button></td> : null}
                            </tr>
                        ))}</tbody>
                    </table>
                )}
            </section>

            {overview.may.manage && (
                <section aria-labelledby="par-form-h" className="flex max-w-3xl flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="par-form-h">{t('hk.par.set')}</h2>
                    <Alert title={t('hk.par.setNote')} tone="info" />
                    <form className="grid gap-3 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); void save(); }}>
                        <FormField error={action.fieldError('item_id')} label={t('hk.par.item')}><Select onChange={(e) => setForm({ ...form, itemId: e.target.value })} value={form.itemId}>{overview.items.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('scope_kind')} label={t('hk.par.scope')}>
                            <Select onChange={(e) => setForm({ ...form, kind: e.target.value, ref: e.target.value === 'area' ? '' : (overview.room_types[0]?.id ?? ''), use: e.target.value === 'area' ? '0' : form.use })} value={form.kind}><option value="room_type">{t('hk.par.roomType')}</option><option value="area">{t('hk.par.area')}</option></Select>
                        </FormField>
                        {form.kind === 'room_type' ? (
                            <FormField error={action.fieldError('scope_ref')} label={t('hk.par.roomType')}><Select onChange={(e) => setForm({ ...form, ref: e.target.value })} value={form.ref}>{overview.room_types.map((r) => <option key={r.id} value={r.id}>{r.code} · {r.name} ({r.rooms})</option>)}</Select></FormField>
                        ) : (
                            <FormField error={action.fieldError('scope_ref')} hint={overview.areas.length === 0 ? undefined : overview.areas.join(', ')} label={t('hk.par.area')}><Input maxLength={40} onChange={(e) => setForm({ ...form, ref: e.target.value })} value={form.ref} /></FormField>
                        )}
                        <FormField error={action.fieldError('par_quantity')} hint={form.kind === 'room_type' ? t('hk.par.parHint') : t('hk.par.parAreaHint')} label={t('hk.par.par')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, par: e.target.value })} required value={form.par} /></FormField>
                        {form.kind === 'room_type' ? <FormField error={action.fieldError('use_quantity')} hint={t('hk.par.useHint')} label={t('hk.par.standard')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, use: e.target.value })} required value={form.use} /></FormField> : <div />}
                        <FormField error={action.fieldError('reason')} label={t('fo.folio.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} required value={form.reason} /></FormField>
                        <div className="sm:col-span-3"><Button loading={action.busy} type="submit">{t('hk.par.save')}</Button></div>
                    </form>
                </section>
            )}
        </HousekeepingShell>
    );
}
