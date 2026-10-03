import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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

    const scopeText = (l: Level) => (l.scope_kind === 'room_type' ? `${t('hk.par.roomType')} ${typeCode(l.room_type_id)}` : `${t('hk.par.area')} ${l.area}`);
    const needColumns: DataGridColumn<Need>[] = [
        { id: 'item', label: t('hk.par.item'), value: (n) => `${n.code} · ${n.name}`, searchText: (n) => `${n.code} ${n.name}`, rowHeader: true },
        { id: 'target', label: t('hk.par.target'), align: 'right', value: (n) => n.target, cell: (n) => format.number(n.target) },
        { id: 'on_floor', label: t('hk.par.onFloor'), align: 'right', value: (n) => n.on_floor, cell: (n) => format.number(n.on_floor) },
        { id: 'need', label: t('hk.par.toBring'), align: 'right', value: (n) => n.need, cell: (n) => <span className="font-medium">{format.number(n.need)}</span> },
        { id: 'in_store', label: t('hk.par.inStore'), align: 'right', value: (n) => n.in_store, cell: (n) => format.number(n.in_store) },
        { id: 'from_store', label: t('hk.par.fromStore'), align: 'right', value: (n) => n.from_store, cell: (n) => format.number(n.from_store) },
        { id: 'to_obtain', label: t('hk.par.toObtain'), align: 'right', value: (n) => n.to_obtain, cell: (n) => <span className={n.to_obtain > 0 ? 'font-medium text-danger' : undefined}>{format.number(n.to_obtain)}</span> },
    ];
    const useColumns: DataGridColumn<Row>[] = [
        { id: 'item', label: t('hk.par.item'), value: (r) => `${r.code} · ${r.name}`, searchText: (r) => `${r.code} ${r.name}`, rowHeader: true },
        { id: 'room_type', label: t('hk.par.roomType'), value: (r) => r.room_type, filter: 'select' },
        { id: 'serviced', label: t('hk.par.serviced'), align: 'right', value: (r) => r.rooms_serviced },
        { id: 'standard', label: t('hk.par.standard'), align: 'right', value: (r) => r.standard },
        { id: 'expected', label: t('hk.par.expected'), align: 'right', value: (r) => r.expected },
        { id: 'actual', label: t('hk.par.actual'), align: 'right', value: (r) => r.actual },
        { id: 'variance', label: t('hk.par.variance'), align: 'right', value: (r) => r.variance, cell: (r) => <span className={r.variance > 0 ? 'font-medium text-danger' : r.variance < 0 ? 'font-medium text-warning' : undefined}>{r.variance > 0 ? `+${r.variance}` : r.variance}</span> },
    ];
    const levelColumns: DataGridColumn<Level>[] = [
        { id: 'item', label: t('hk.par.item'), value: (l) => `${l.item_code} · ${l.item_name}`, searchText: (l) => `${l.item_code} ${l.item_name}`, rowHeader: true },
        { id: 'scope', label: t('hk.par.scope'), value: scopeText, filter: 'select' },
        { id: 'par', label: t('hk.par.par'), align: 'right', value: (l) => l.par_quantity },
        { id: 'standard', label: t('hk.par.standard'), align: 'right', value: (l) => (l.scope_kind === 'room_type' ? l.use_quantity : null), cell: (l) => (l.scope_kind === 'room_type' ? l.use_quantity : '—') },
        ...(overview.may.manage ? [{
            id: 'actions', label: t('hk.board.actions'),
            cell: (l: Level) => <Button aria-label={`${t('hk.par.edit')} ${l.item_code} ${l.scope_kind === 'room_type' ? typeCode(l.room_type_id) : l.area}`} onClick={() => edit(l)} size="sm" type="button" variant="outline">{t('hk.par.edit')}</Button>,
        }] : []),
    ];

    return (
        <HousekeepingShell description={t('hk.par.description')} title={t('hk.par.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <Tabs defaultValue="par-need-h">
                <TabsList>
                    <TabsTrigger value="par-need-h">{t('hk.par.need')}</TabsTrigger>
                    <TabsTrigger value="par-use-h">{t('hk.par.consumption')}</TabsTrigger>
                    <TabsTrigger value="par-set-h">{t('hk.par.levels')}</TabsTrigger>
                </TabsList>

            <TabsContent className="flex flex-col gap-3" value="par-need-h">
                <h2 className="sr-only" id="par-need-h">{t('hk.par.need')}</h2>
                <DataGrid
                    caption={t('hk.par.need')}
                    columns={needColumns}
                    empty={<EmptyState title={t('hk.par.noNeed')} />}
                    getRowId={(n) => n.item_id}
                    id="hk.par.need"
                    rows={overview.replenishment}
                    testId="need"
                />
            </TabsContent>

            <TabsContent className="flex flex-col gap-3" value="par-use-h">
                <h2 className="sr-only" id="par-use-h">{t('hk.par.consumption')}</h2>
                <p className="text-sm text-muted-foreground">{t('hk.par.shiftNote')}</p>
                <div className="flex flex-wrap items-end gap-2">
                    <FormField label={t('hk.par.date')}><DatePicker onChange={(e) => setWhen({ ...when, date: e.target.value })} value={when.date} /></FormField>
                    <FormField label={t('hk.par.shift')}><Select onChange={(e) => setWhen({ ...when, shift: e.target.value })} value={when.shift}>{overview.shifts.map((s) => <option key={s.code} value={s.code}>{t(`hk.par.shift.${s.code}` as 'hk.par.shift.morning')} ({s.from}–{s.to})</option>)}</Select></FormField>
                    <Button disabled={action.busy} onClick={() => void show()} type="button" variant="outline">{t('hk.par.show')}</Button>
                </div>
                {shown === null ? null : (
                    <DataGrid
                        caption={t('hk.par.consumption')}
                        columns={useColumns}
                        empty={<p className="text-sm text-muted-foreground" data-testid="use-empty">{t('hk.par.noUse')}</p>}
                        getRowId={(r) => `${r.item_id}${r.room_type}`}
                        id="hk.par.use"
                        rows={shown.rows}
                        testId="use"
                    />
                )}
            </TabsContent>

            <TabsContent className="flex flex-col gap-3" value="par-set-h">
                <h2 className="sr-only" id="par-set-h">{t('hk.par.levels')}</h2>
                <DataGrid
                    caption={t('hk.par.levels')}
                    columns={levelColumns}
                    empty={<EmptyState title={t('hk.par.none')} />}
                    getRowId={(l) => l.id}
                    id="hk.par.levels"
                    rows={overview.levels}
                    testId="levels"
                />
            </TabsContent>
            </Tabs>

            {overview.may.manage && (
                <section aria-labelledby="par-form-h" className="flex max-w-3xl flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="par-form-h">{t('hk.par.set')}</h2>
                    <Alert title={t('hk.par.setNote')} tone="info" />
                    <form className="grid gap-3 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault(); void save(); }}>
                        <FormField field="item_id" error={action.fieldError('item_id')} label={t('hk.par.item')}><Select onChange={(e) => setForm({ ...form, itemId: e.target.value })} value={form.itemId}>{overview.items.map((i) => <option key={i.id} value={i.id}>{i.code} · {i.name}</option>)}</Select></FormField>
                        <FormField field="scope_kind" error={action.fieldError('scope_kind')} label={t('hk.par.scope')}>
                            <Select onChange={(e) => setForm({ ...form, kind: e.target.value, ref: e.target.value === 'area' ? '' : (overview.room_types[0]?.id ?? ''), use: e.target.value === 'area' ? '0' : form.use })} value={form.kind}><option value="room_type">{t('hk.par.roomType')}</option><option value="area">{t('hk.par.area')}</option></Select>
                        </FormField>
                        {form.kind === 'room_type' ? (
                            <FormField field="scope_ref" error={action.fieldError('scope_ref')} label={t('hk.par.roomType')}><Select onChange={(e) => setForm({ ...form, ref: e.target.value })} value={form.ref}>{overview.room_types.map((r) => <option key={r.id} value={r.id}>{r.code} · {r.name} ({r.rooms})</option>)}</Select></FormField>
                        ) : (
                            <FormField field="scope_ref" error={action.fieldError('scope_ref')} hint={overview.areas.length === 0 ? undefined : overview.areas.join(', ')} label={t('hk.par.area')}><Input maxLength={40} onChange={(e) => setForm({ ...form, ref: e.target.value })} value={form.ref} /></FormField>
                        )}
                        <FormField field="par_quantity" error={action.fieldError('par_quantity')} hint={form.kind === 'room_type' ? t('hk.par.parHint') : t('hk.par.parAreaHint')} label={t('hk.par.par')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, par: e.target.value })} required value={form.par} /></FormField>
                        {form.kind === 'room_type' ? <FormField field="use_quantity" error={action.fieldError('use_quantity')} hint={t('hk.par.useHint')} label={t('hk.par.standard')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, use: e.target.value })} required value={form.use} /></FormField> : <div />}
                        <FormField field="reason" error={action.fieldError('reason')} label={t('fo.folio.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} required value={form.reason} /></FormField>
                        <div className="sm:col-span-3"><Button loading={action.busy} type="submit">{t('hk.par.save')}</Button></div>
                    </form>
                </section>
            )}
        </HousekeepingShell>
    );
}
