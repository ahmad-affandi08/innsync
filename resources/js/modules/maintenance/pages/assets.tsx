import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { MaintenanceShell } from '@/modules/maintenance/components/maintenance-shell';
import type { AssetDetail, AssetSummary, AssetsOverview, Plan, Priority } from '@/modules/maintenance/lib/maintenance';
import { apiRequest } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const BLANK = { name: '', category: 'machine', serial: '', roomId: '', area: '', acquiredOn: '', warrantyUntil: '', meterUnit: '', notes: '' };
const PLAN = { title: '', description: '', category: 'other', priority: 'normal' as Priority, trigger: 'calendar', interval: '30', lead: '7', first: '' };

/** The assets of the property: where they are, their warranty and meter, their routine care and the repairs they had. */
export default function AssetsPage({ overview }: { overview: AssetsOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<typeof BLANK | null>(null);
    const [detail, setDetail] = useState<AssetDetail | null>(null);
    const [loadFailed, setLoadFailed] = useState(false);
    const [plan, setPlan] = useState(PLAN);
    const [reading, setReading] = useState('');
    const [reason, setReason] = useState('');
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const place = (a: { room: string | null; area: string | null }) => (a.room !== null ? t('mtc.room', { number: a.room }) : (a.area ?? '—'));
    const warrantyTone = { valid: 'success', ending: 'warning', expired: 'danger' } as const;

    async function open(a: { id: string }) {
        action.clear();
        setLoadFailed(false);
        try {
            setDetail(await apiRequest<AssetDetail>(`/maintenance/assets/${a.id}`, { method: 'GET' }));
            setPlan(PLAN);
            setReading('');
            setReason('');
        } catch {
            setLoadFailed(true);
        }
    }

    function taken(result: AssetDetail | null) {
        if (result !== null) {
            setDetail(result);
            router.reload({ only: ['overview'] });
        }

        return result;
    }

    async function create() {
        if (form === null) return;
        const done = await action.run<AssetDetail>('/maintenance/assets', {
            body: {
                name: form.name.trim(), category: form.category, serial: form.serial.trim() || null, room_id: form.roomId || null, area: form.area.trim() || null, acquired_on: form.acquiredOn,
                warranty_until: form.warrantyUntil || null, meter_unit: form.meterUnit || null, notes: form.notes.trim() || null,
            },
        });

        if (taken(done) !== null) setForm(null);
    }

    const columns: DataGridColumn<AssetSummary>[] = [
        { id: 'number', label: t('mtc.col.number'), value: (a) => a.number, rowHeader: true },
        { id: 'name', label: t('mtc.asset.name'), value: (a) => a.name, searchText: (a) => `${a.name} ${a.number} ${a.serial ?? ''} ${place(a)}` },
        { id: 'category', label: t('mtc.col.category'), value: (a) => a.category, filter: 'select', filterLabel: (v) => label('mtc.asset.category', v), cell: (a) => label('mtc.asset.category', a.category) },
        { id: 'place', label: t('mtc.col.place'), value: (a) => place(a) },
        { id: 'warranty', label: t('mtc.asset.warranty'), value: (a) => a.warranty_until ?? '', cell: (a) => (a.warranty === null || a.warranty_until === null ? '—' : <span className="flex flex-wrap items-center gap-1">{format.date(a.warranty_until)}<StatusBadge label={label('mtc.asset.warranty', a.warranty)} tone={warrantyTone[a.warranty]} /></span>) },
        { id: 'meter', label: t('mtc.asset.meter'), align: 'right', value: (a) => a.reading ?? 0, cell: (a) => (a.meter_unit === null ? '—' : `${a.reading ?? 0} ${label('mtc.asset.unit', a.meter_unit)}`) },
        { id: 'due', label: t('mtc.asset.nextCare'), value: (a) => a.next_due_on ?? '', cell: (a) => (a.next_due_on === null ? '—' : format.date(a.next_due_on)) },
        { id: 'status', label: t('mtc.col.status'), value: (a) => a.status, filter: 'select', filterLabel: (v) => label('mtc.asset.status', v), cell: (a) => <StatusBadge label={label('mtc.asset.status', a.status)} tone={a.status === 'active' ? 'success' : 'neutral'} /> },
        { id: 'open', label: '', value: () => '', sortable: false, cell: (a) => <Button onClick={() => void open(a)} size="sm" type="button" variant="outline">{t('mtc.open')}</Button> },
    ];
    const d = detail?.asset ?? null;
    const planText = (p: Plan) => (p.trigger === 'calendar' ? t('mtc.plan.everyDays', { n: p.interval }) : t('mtc.plan.everyUnits', { n: p.interval, unit: label('mtc.asset.unit', d?.meter_unit ?? 'hours') }));
    const nextText = (p: Plan) => (p.trigger === 'calendar' ? (p.next_due_on === null ? '—' : format.date(p.next_due_on)) : `${p.next_meter ?? 0} ${label('mtc.asset.unit', d?.meter_unit ?? 'hours')}`);

    return (
        <MaintenanceShell actions={overview.may.manage ? <Button onClick={() => { action.clear(); setForm({ ...BLANK, acquiredOn: overview.business_date }); }} type="button">{t('mtc.asset.new')}</Button> : undefined} description={t('mtc.asset.description')} title={t('mtc.asset.title')}>
            {loadFailed ? <Alert title={t('mtc.loadFailed')} tone="danger" /> : null}
            <DataGrid caption={t('mtc.asset.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('mtc.asset.empty')} />} getRowId={(a) => a.id} id="mtc.assets" rows={overview.assets} testId="mtc-assets" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form?.name.trim() === '' || form?.acquiredOn === ''} loading={action.busy} onClick={() => void create()} type="button">{t('mtc.asset.save')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('mtc.asset.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('name')} field="name" label={t('mtc.asset.name')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField></div>
                        <FormField error={action.fieldError('category')} field="category" label={t('mtc.col.category')}><Select onChange={(e) => setForm({ ...form, category: e.target.value })} value={form.category}>{overview.categories.map((c) => <option key={c} value={c}>{label('mtc.asset.category', c)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('serial')} field="serial" label={t('mtc.asset.serial')}><Input maxLength={40} onChange={(e) => setForm({ ...form, serial: e.target.value })} value={form.serial} /></FormField>
                        <FormField error={action.fieldError('room_id')} field="room_id" label={t('mtc.f.room')}><Select onChange={(e) => setForm({ ...form, roomId: e.target.value })} value={form.roomId}><option value="">{t('mtc.f.noRoom')}</option>{overview.rooms.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('area')} field="area" label={t('mtc.f.area')}><Input maxLength={80} onChange={(e) => setForm({ ...form, area: e.target.value })} value={form.area} /></FormField>
                        <FormField error={action.fieldError('acquired_on')} field="acquired_on" label={t('mtc.asset.acquired')}><DatePicker onChange={(e) => setForm({ ...form, acquiredOn: e.target.value })} value={form.acquiredOn} /></FormField>
                        <FormField error={action.fieldError('warranty_until')} field="warranty_until" label={t('mtc.asset.warrantyUntil')}><DatePicker onChange={(e) => setForm({ ...form, warrantyUntil: e.target.value })} value={form.warrantyUntil} /></FormField>
                        <FormField error={action.fieldError('meter_unit')} field="meter_unit" hint={t('mtc.asset.meterHint')} label={t('mtc.asset.meter')}><Select onChange={(e) => setForm({ ...form, meterUnit: e.target.value })} value={form.meterUnit}><option value="">{t('mtc.asset.noMeter')}</option>{overview.meter_units.map((u) => <option key={u} value={u}>{label('mtc.asset.unit', u)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('notes')} field="notes" label={t('mtc.asset.notes')}><Input maxLength={300} onChange={(e) => setForm({ ...form, notes: e.target.value })} value={form.notes} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                className="w-[min(46rem,calc(100vw-2rem))]"
                footer={<Button onClick={() => setDetail(null)} type="button" variant="outline">{t('mtc.close')}</Button>}
                onClose={() => setDetail(null)}
                open={detail !== null}
                title={d === null ? '' : `${d.number} · ${d.name}`}
            >
                {d !== null && detail !== null && (
                    <div className="flex flex-col gap-4">
                        {failure}
                        <div className="flex flex-wrap items-center gap-2"><StatusBadge label={label('mtc.asset.status', d.status)} tone={d.status === 'active' ? 'success' : 'neutral'} />{d.warranty !== null ? <StatusBadge label={label('mtc.asset.warranty', d.warranty)} tone={warrantyTone[d.warranty]} /> : null}</div>
                        <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                            <div><dt className="text-muted-foreground">{t('mtc.col.category')}</dt><dd>{label('mtc.asset.category', d.category)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.col.place')}</dt><dd>{place(d)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.asset.serial')}</dt><dd>{d.serial ?? '—'}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.asset.acquired')}</dt><dd>{format.date(d.acquired_on)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.asset.warrantyUntil')}</dt><dd>{d.warranty_until === null ? '—' : format.date(d.warranty_until)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.asset.meter')}</dt><dd>{d.meter_unit === null ? '—' : `${d.reading ?? 0} ${label('mtc.asset.unit', d.meter_unit)}`}</dd></div>
                        </dl>
                        {d.notes !== null ? <p className="text-sm">{d.notes}</p> : null}
                        {d.status === 'retired' ? <Alert title={t('mtc.asset.retiredNote', { reason: d.retired_reason ?? '' })} tone="warning" /> : null}

                        {detail.may.read_meter ? (
                            <div className="flex flex-wrap items-end gap-2 border-t border-border pt-3">
                                <FormField error={action.fieldError('reading')} field="reading" label={t('mtc.asset.readNow', { unit: label('mtc.asset.unit', d.meter_unit ?? 'hours') })}><Input inputMode="numeric" onChange={(e) => setReading(e.target.value)} value={reading} /></FormField>
                                <Button disabled={action.busy || !/^\d+$/.test(reading)} onClick={async () => { const done = taken(await action.run<AssetDetail>(`/maintenance/assets/${d.id}/readings`, { body: { reading: Number(reading) } })); if (done !== null) setReading(''); }} size="sm" type="button">{t('mtc.asset.record')}</Button>
                            </div>
                        ) : null}

                        <section aria-labelledby="mtc-plans-h" className="flex flex-col gap-2 border-t border-border pt-3">
                            <h3 className="font-semibold" id="mtc-plans-h">{t('mtc.plan.title')}</h3>
                            {detail.plans.length === 0 ? <p className="text-sm text-muted-foreground">{t('mtc.plan.none')}</p> : (
                                <ul className="flex flex-col divide-y divide-border text-sm" data-testid="mtc-plans">
                                    {detail.plans.map((p) => (
                                        <li className="flex flex-wrap items-center justify-between gap-2 py-2" key={p.id}>
                                            <span><span className="font-medium">{p.title}</span> · {planText(p)} · {t('mtc.plan.next', { next: nextText(p) })}{p.last_done_on !== null ? ` · ${t('mtc.plan.last', { date: format.date(p.last_done_on) })}` : ''} {p.due && p.active ? <StatusBadge label={t('mtc.plan.due')} tone="warning" /> : null}{!p.active ? <StatusBadge label={t('mtc.plan.paused')} tone="neutral" /> : null}</span>
                                            {detail.may.manage && d.status === 'active' ? <Button disabled={action.busy} onClick={() => void action.run<AssetDetail>(`/maintenance/plans/${p.id}/active`, { body: { active: !p.active, lock_version: p.lock_version } }).then(taken)} size="sm" type="button" variant="outline">{t(p.active ? 'mtc.plan.pause' : 'mtc.plan.resume')}</Button> : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {detail.may.manage && d.status === 'active' ? (
                                <div className="grid gap-2 border border-border p-3 sm:grid-cols-2">
                                    <div className="sm:col-span-2"><FormField error={action.fieldError('title')} field="title" label={t('mtc.plan.titleLabel')}><Input maxLength={80} onChange={(e) => setPlan({ ...plan, title: e.target.value })} value={plan.title} /></FormField></div>
                                    <FormField error={action.fieldError('trigger_kind')} field="trigger_kind" label={t('mtc.plan.trigger')}><Select onChange={(e) => setPlan({ ...plan, trigger: e.target.value })} value={plan.trigger}><option value="calendar">{t('mtc.plan.byCalendar')}</option>{d.meter_unit !== null ? <option value="meter">{t('mtc.plan.byMeter')}</option> : null}</Select></FormField>
                                    <FormField error={action.fieldError('interval_value')} field="interval_value" label={plan.trigger === 'calendar' ? t('mtc.plan.intervalDays') : t('mtc.plan.intervalUnits', { unit: label('mtc.asset.unit', d.meter_unit ?? 'hours') })}><Input inputMode="numeric" onChange={(e) => setPlan({ ...plan, interval: e.target.value })} value={plan.interval} /></FormField>
                                    {plan.trigger === 'calendar' ? (
                                        <>
                                            <FormField error={action.fieldError('first_due_on')} field="first_due_on" hint={t('mtc.plan.firstHint')} label={t('mtc.plan.first')}><DatePicker onChange={(e) => setPlan({ ...plan, first: e.target.value })} value={plan.first} /></FormField>
                                            <FormField error={action.fieldError('lead_days')} field="lead_days" hint={t('mtc.plan.leadHint')} label={t('mtc.plan.lead')}><Input inputMode="numeric" onChange={(e) => setPlan({ ...plan, lead: e.target.value })} value={plan.lead} /></FormField>
                                        </>
                                    ) : null}
                                    <FormField error={action.fieldError('category')} field="category" label={t('mtc.f.category')}><Select onChange={(e) => setPlan({ ...plan, category: e.target.value })} value={plan.category}>{['electrical', 'plumbing', 'hvac', 'furniture', 'appliance', 'structural', 'it', 'other'].map((c) => <option key={c} value={c}>{label('mtc.category', c)}</option>)}</Select></FormField>
                                    <FormField error={action.fieldError('priority')} field="priority" label={t('mtc.f.priority')}><Select onChange={(e) => setPlan({ ...plan, priority: e.target.value as Priority })} value={plan.priority}>{(['urgent', 'high', 'normal', 'low'] as const).map((p) => <option key={p} value={p}>{label('mtc.priority', p)}</option>)}</Select></FormField>
                                    <div><Button disabled={action.busy || plan.title.trim() === ''} onClick={() => void action.run<AssetDetail>(`/maintenance/assets/${d.id}/plans`, { body: { title: plan.title.trim(), description: plan.description.trim() || null, category: plan.category, priority: plan.priority, trigger_kind: plan.trigger, interval_value: Number(plan.interval), lead_days: plan.trigger === 'calendar' ? Number(plan.lead) : 0, first_due_on: plan.trigger === 'calendar' && plan.first !== '' ? plan.first : null } }).then(taken)} size="sm" type="button">{t('mtc.plan.add')}</Button></div>
                                </div>
                            ) : null}
                        </section>

                        <section aria-labelledby="mtc-repairs-h" className="flex flex-col gap-1 border-t border-border pt-3">
                            <h3 className="text-sm font-semibold" id="mtc-repairs-h">{t('mtc.asset.repairs')}</h3>
                            {detail.repairs.length === 0 ? <p className="text-sm text-muted-foreground">{t('mtc.asset.noRepairs')}</p> : (
                                <ul className="flex flex-col gap-1 text-sm" data-testid="mtc-repairs">{detail.repairs.map((r) => <li key={r.id}>{format.instant(r.reported_at)} · {r.number} · {r.title} · {label('mtc.status', r.status)}{r.preventive ? ` · ${t('mtc.preventive')}` : ''}</li>)}</ul>
                            )}
                        </section>
                        {detail.readings.length > 0 ? (
                            <section aria-labelledby="mtc-readings-h" className="flex flex-col gap-1 border-t border-border pt-3">
                                <h3 className="text-sm font-semibold" id="mtc-readings-h">{t('mtc.asset.readings')}</h3>
                                <ul className="flex flex-col gap-1 text-sm">{detail.readings.map((r, i) => <li key={i}>{format.date(r.read_on)} · {r.reading} {label('mtc.asset.unit', d.meter_unit ?? 'hours')} · {r.by ?? '—'}</li>)}</ul>
                            </section>
                        ) : null}
                        {detail.may.manage && d.status === 'active' ? (
                            <div className="flex flex-wrap items-end gap-2 border-t border-border pt-3">
                                <FormField error={action.fieldError('reason')} field="reason" label={t('mtc.asset.retireReason')}><Input maxLength={200} onChange={(e) => setReason(e.target.value)} value={reason} /></FormField>
                                <Button disabled={action.busy || reason.trim() === ''} onClick={() => void action.run<AssetDetail>(`/maintenance/assets/${d.id}/retire`, { body: { reason: reason.trim(), lock_version: d.lock_version } }).then(taken)} size="sm" type="button" variant="outline">{t('mtc.asset.retire')}</Button>
                            </div>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </MaintenanceShell>
    );
}
