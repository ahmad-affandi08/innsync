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
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { MaintenanceShell } from '@/modules/maintenance/components/maintenance-shell';
import type { DutiesOverview, Duty, DutyChoices, DutyResult, DutyRun, DutyRunDetail, DutyRunStatus } from '@/modules/maintenance/lib/maintenance';
import { apiRequest, newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const RUN_TONE: Record<DutyRunStatus, StatusTone> = { open: 'info', done: 'success', missed: 'danger' };
const RESULT_TONE: Record<DutyResult, StatusTone> = { pending: 'neutral', ok: 'success', issue: 'danger', na: 'unknown' };
const BLANK = { id: '', title: '', frequency: 'daily', shift: 'any', weekday: '1', monthDay: '1', assetId: '', area: '', category: 'other', steps: [''], lock: 0 };

/** The routine duties of engineering: what falls due today, what was missed, and the procedures (for managers). */
export default function DutiesPage({ overview, duties, choices }: { overview: DutiesOverview; duties: Duty[]; choices: DutyChoices | null }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [from, setFrom] = useState(overview.from);
    const [to, setTo] = useState(overview.to);
    const [run, setRun] = useState<DutyRunDetail | null>(null);
    const [notes, setNotes] = useState<Record<string, string>>({});
    const [closing, setClosing] = useState('');
    const [form, setForm] = useState<typeof BLANK | null>(null);
    const [loadFailed, setLoadFailed] = useState(false);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string | number) => t(`${prefix}.${key}` as MessageKey);
    const placeOf = (r: { area: string | null }) => r.area ?? '—';

    async function open(r: { id: string }) {
        action.clear();
        setLoadFailed(false);
        try {
            const next = await apiRequest<DutyRunDetail>(`/maintenance/duty-runs/${r.id}`, { method: 'GET' });

            setRun(next);
            setNotes(Object.fromEntries(next.steps.map((s) => [s.id, s.note ?? ''])));
            setClosing('');
        } catch {
            setLoadFailed(true);
        }
    }

    function taken(next: DutyRunDetail | null) {
        if (next !== null) {
            setRun(next);
            router.reload({ only: ['overview'] });
        }

        return next;
    }

    async function saveDuty() {
        if (form === null) return;
        const body = {
            title: form.title.trim(), frequency: form.frequency, shift: form.shift, weekday: form.frequency === 'weekly' ? Number(form.weekday) : null, month_day: form.frequency === 'monthly' ? Number(form.monthDay) : null,
            asset_id: form.assetId === '' ? null : form.assetId, area: form.area.trim() === '' ? null : form.area.trim(), category: form.category, steps: form.steps.map((s) => s.trim()).filter((s) => s !== ''),
        };
        const result = form.id === ''
            ? await action.run<Duty>('/maintenance/duties', { idempotencyKey: newIdempotencyKey(), body, reload: ['duties', 'overview'] })
            : await action.run<Duty>(`/maintenance/duties/${form.id}`, { body: { ...body, lock_version: form.lock }, reload: ['duties', 'overview'] });

        if (result !== null) setForm(null);
    }

    const edit = (d: Duty) => { action.clear(); setForm({ id: d.id, title: d.title, frequency: d.frequency, shift: d.shift, weekday: String(d.weekday ?? 1), monthDay: String(d.month_day ?? 1), assetId: d.asset_id ?? '', area: d.area ?? '', category: d.category, steps: d.steps, lock: d.lock_version }); };
    const when = (d: Duty) => (d.frequency === 'daily' ? t('mtc.duty.everyDay') : d.frequency === 'weekly' ? t('mtc.duty.everyWeek', { day: label('mtc.duty.weekday', d.weekday ?? 1) }) : t('mtc.duty.everyMonth', { day: d.month_day ?? 1 }));

    const runColumns: DataGridColumn<DutyRun>[] = [
        { id: 'due', label: t('mtc.duty.due'), value: (r) => r.due_on, cell: (r) => format.date(r.due_on) },
        { id: 'title', label: t('mtc.duty.title'), value: (r) => r.title, rowHeader: true, searchText: (r) => `${r.title} ${placeOf(r)}` },
        { id: 'place', label: t('mtc.col.place'), value: (r) => placeOf(r) },
        { id: 'shift', label: t('mtc.duty.shift'), value: (r) => r.shift, filter: 'select', filterLabel: (v) => label('mtc.duty.shifts', v), cell: (r) => label('mtc.duty.shifts', r.shift) },
        { id: 'status', label: t('mtc.col.status'), value: (r) => r.status, filter: 'select', filterLabel: (v) => label('mtc.duty.status', v), cell: (r) => <StatusBadge label={label('mtc.duty.status', r.status)} tone={RUN_TONE[r.status]} /> },
        { id: 'progress', label: t('mtc.duty.progress'), align: 'right', value: (r) => r.steps - r.pending, cell: (r) => <span>{t('mtc.duty.answered', { done: r.steps - r.pending, of: r.steps })}{r.issues > 0 ? <span className="block text-xs text-danger">{t('mtc.duty.issues', { n: r.issues })}</span> : null}</span> },
        { id: 'by', label: t('mtc.duty.doneBy'), value: (r) => r.done_by ?? '', cell: (r) => r.done_by ?? '—' },
        { id: 'open', label: '', value: () => '', sortable: false, cell: (r) => <Button onClick={() => void open(r)} size="sm" type="button" variant="outline">{t('mtc.open')}</Button> },
    ];
    const dutyColumns: DataGridColumn<Duty>[] = [
        { id: 'title', label: t('mtc.duty.title'), value: (d) => d.title, rowHeader: true },
        { id: 'when', label: t('mtc.duty.when'), value: (d) => when(d) },
        { id: 'shift', label: t('mtc.duty.shift'), value: (d) => d.shift, cell: (d) => label('mtc.duty.shifts', d.shift) },
        { id: 'place', label: t('mtc.col.place'), value: (d) => d.asset?.name ?? d.area ?? '', cell: (d) => (d.asset !== null ? `${d.asset.number} · ${d.asset.name}` : (d.area ?? '—')) },
        { id: 'steps', label: t('mtc.duty.steps'), align: 'right', value: (d) => d.steps.length },
        { id: 'status', label: t('mtc.col.status'), value: (d) => (d.active ? 'active' : 'retired'), cell: (d) => <StatusBadge label={t(d.active ? 'mtc.duty.active' : 'mtc.duty.retired')} tone={d.active ? 'success' : 'neutral'} /> },
        { id: 'edit', label: '', value: () => '', sortable: false, cell: (d) => (
            <span className="flex gap-1">
                {d.active ? <Button onClick={() => edit(d)} size="sm" type="button" variant="outline">{t('mtc.duty.edit')}</Button> : null}
                <Button onClick={() => void action.run<Duty>(`/maintenance/duties/${d.id}/active`, { body: { active: !d.active, lock_version: d.lock_version }, reload: ['duties', 'overview'] })} size="sm" type="button" variant="outline">{t(d.active ? 'mtc.duty.retire' : 'mtc.duty.resume')}</Button>
            </span>
        ) },
    ];

    const runsGrid = (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end gap-2">
                <FormField label={t('mtc.rep.from')}><DatePicker onChange={(e) => setFrom(e.target.value)} value={from} /></FormField>
                <FormField label={t('mtc.rep.to')}><DatePicker onChange={(e) => setTo(e.target.value)} value={to} /></FormField>
                <Button disabled={from === '' || to === ''} onClick={() => router.get('/maintenance/duties', { from, to })} type="button">{t('mtc.rep.show')}</Button>
            </div>
            <DataGrid caption={t('mtc.duty.runs')} columns={runColumns} empty={<EmptyState illustration="checklist" title={t('mtc.duty.noRuns')} />} getRowId={(r) => r.id} id="mtc.duty.runs" rows={overview.runs} testId="mtc-duty-runs" />
        </div>
    );

    return (
        <MaintenanceShell
            actions={choices !== null ? <Button onClick={() => { action.clear(); setForm({ ...BLANK, steps: [''] }); }} type="button">{t('mtc.duty.new')}</Button> : undefined}
            description={t('mtc.duty.description')}
            title={t('mtc.duty.page')}
        >
            {loadFailed ? <Alert title={t('mtc.loadFailed')} tone="danger" /> : null}
            {action.error !== null && form === null && run === null ? failure : null}
            <div className="grid gap-3 sm:grid-cols-3" data-testid="mtc-duty-counts">
                <Metric label={t('mtc.duty.status.open')} value={String(overview.counts.open)} />
                <Metric label={t('mtc.duty.status.done')} value={String(overview.counts.done)} />
                <Metric label={t('mtc.duty.status.missed')} value={String(overview.counts.missed)} />
            </div>
            {choices === null ? runsGrid : (
                <Tabs defaultValue="runs">
                    <TabsList aria-label={t('mtc.duty.page')}>
                        <TabsTrigger value="runs">{t('mtc.duty.runs')}</TabsTrigger>
                        <TabsTrigger value="duties">{t('mtc.duty.routines')}</TabsTrigger>
                    </TabsList>
                    <TabsContent className="flex flex-col gap-3" value="runs">{runsGrid}</TabsContent>
                    <TabsContent className="flex flex-col gap-3" value="duties"><DataGrid caption={t('mtc.duty.routines')} columns={dutyColumns} empty={<EmptyState illustration="checklist" title={t('mtc.duty.noDuties')} />} getRowId={(d) => d.id} id="mtc.duty.routines" rows={duties} testId="mtc-duty-routines" /></TabsContent>
                </Tabs>
            )}

            <Dialog
                className="w-[min(44rem,calc(100vw-2rem))]"
                footer={<Button onClick={() => setRun(null)} type="button" variant="outline">{t('mtc.close')}</Button>}
                onClose={() => setRun(null)}
                open={run !== null}
                title={run === null ? '' : `${run.title} · ${format.date(run.due_on)}`}
            >
                {run !== null && (
                    <div className="flex flex-col gap-4" data-testid="mtc-duty-run">
                        {failure}
                        <div className="flex flex-wrap items-center gap-2"><StatusBadge label={label('mtc.duty.status', run.status)} tone={RUN_TONE[run.status]} /><span className="text-sm text-muted-foreground">{label('mtc.duty.shifts', run.shift)} · {placeOf(run)}</span></div>
                        <ol className="flex flex-col gap-3">
                            {run.steps.map((s) => (
                                <li className="flex flex-col gap-2 border border-border p-3" key={s.id}>
                                    <div className="flex flex-wrap items-center gap-2"><span className="font-medium">{s.position}. {s.text}</span><StatusBadge label={label('mtc.duty.result', s.result)} tone={RESULT_TONE[s.result]} />{s.work_order_id !== null ? <span className="text-xs text-muted-foreground">{t('mtc.duty.raised')}</span> : null}</div>
                                    {run.may.do ? (
                                        <div className="flex flex-wrap items-end gap-2">
                                            <FormField error={action.fieldError('note')} label={t('mtc.parts.note')}><Input aria-label={t('mtc.duty.noteFor', { n: s.position })} maxLength={200} onChange={(e) => setNotes({ ...notes, [s.id]: e.target.value })} value={notes[s.id] ?? ''} /></FormField>
                                            {(['ok', 'issue', 'na'] as const).map((r) => <Button disabled={action.busy || (r === 'issue' && (notes[s.id] ?? '').trim() === '')} key={r} onClick={() => void action.run<DutyRunDetail>(`/maintenance/duty-runs/${run.id}/steps/${s.id}`, { body: { result: r, note: (notes[s.id] ?? '').trim() === '' ? null : notes[s.id].trim() } }).then(taken)} size="sm" type="button" variant={s.result === r ? 'default' : 'outline'}>{label('mtc.duty.do', r)}</Button>)}
                                        </div>
                                    ) : (s.note !== null ? <p className="text-sm text-muted-foreground">{s.note}</p> : null)}
                                </li>
                            ))}
                        </ol>
                        {run.may.do ? (
                            <div className="flex flex-wrap items-end gap-2 border-t border-border pt-3">
                                <FormField error={action.fieldError('steps')} label={t('mtc.duty.closing')}><Input maxLength={300} onChange={(e) => setClosing(e.target.value)} value={closing} /></FormField>
                                <Button disabled={action.busy || run.steps.some((s) => s.result === 'pending')} onClick={() => void action.run<DutyRunDetail>(`/maintenance/duty-runs/${run.id}/complete`, { body: { note: closing.trim() === '' ? null : closing.trim(), lock_version: run.lock_version } }).then(taken)} size="sm" type="button">{t('mtc.duty.finish')}</Button>
                            </div>
                        ) : (run.note !== null ? <p className="text-sm">{run.note}</p> : null)}
                    </div>
                )}
            </Dialog>

            <Dialog
                className="w-[min(44rem,calc(100vw-2rem))]"
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form?.title.trim() === ''} loading={action.busy} onClick={() => void saveDuty()} type="button">{t('mtc.duty.save')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null && choices !== null}
                title={form?.id === '' ? t('mtc.duty.new') : t('mtc.duty.edit')}
            >
                {form !== null && choices !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('title')} field="title" label={t('mtc.duty.title')}><Input maxLength={80} onChange={(e) => setForm({ ...form, title: e.target.value })} value={form.title} /></FormField></div>
                        <FormField error={action.fieldError('frequency')} field="frequency" label={t('mtc.duty.frequency')}><Select onChange={(e) => setForm({ ...form, frequency: e.target.value })} value={form.frequency}>{choices.frequencies.map((f) => <option key={f} value={f}>{label('mtc.duty.freq', f)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('shift')} field="shift" label={t('mtc.duty.shift')}><Select onChange={(e) => setForm({ ...form, shift: e.target.value })} value={form.shift}>{choices.shifts.map((f) => <option key={f} value={f}>{label('mtc.duty.shifts', f)}</option>)}</Select></FormField>
                        {form.frequency === 'weekly' ? <FormField error={action.fieldError('weekday')} field="weekday" label={t('mtc.duty.onWeekday')}><Select onChange={(e) => setForm({ ...form, weekday: e.target.value })} value={form.weekday}>{[1, 2, 3, 4, 5, 6, 7].map((d) => <option key={d} value={String(d)}>{label('mtc.duty.weekday', d)}</option>)}</Select></FormField> : null}
                        {form.frequency === 'monthly' ? <FormField error={action.fieldError('month_day')} field="month_day" hint={t('mtc.duty.monthHint')} label={t('mtc.duty.onMonthDay')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, monthDay: e.target.value })} value={form.monthDay} /></FormField> : null}
                        <FormField error={action.fieldError('asset_id')} field="asset_id" label={t('mtc.f.asset')}><Select onChange={(e) => setForm({ ...form, assetId: e.target.value })} value={form.assetId}><option value="">—</option>{choices.assets.map((a) => <option key={a.id} value={a.id}>{a.number} · {a.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('area')} field="area" label={t('mtc.f.area')}><Input maxLength={80} onChange={(e) => setForm({ ...form, area: e.target.value })} value={form.area} /></FormField>
                        <FormField error={action.fieldError('category')} field="category" label={t('mtc.f.category')}><Select onChange={(e) => setForm({ ...form, category: e.target.value })} value={form.category}>{choices.categories.map((c) => <option key={c} value={c}>{label('mtc.category', c)}</option>)}</Select></FormField>
                        <div className="flex flex-col gap-2 sm:col-span-2">
                            <h3 className="text-sm font-semibold">{t('mtc.duty.steps')}</h3>
                            <p className="text-xs text-muted-foreground">{t('mtc.duty.stepsHint')}</p>
                            {form.steps.map((s, i) => (
                                <div className="flex items-end gap-2" key={i}>
                                    <div className="flex-1"><FormField error={i === 0 ? action.fieldError('steps') : undefined} field={i === 0 ? 'steps' : undefined} label={t('mtc.duty.stepN', { n: i + 1 })}><Input maxLength={200} onChange={(e) => setForm({ ...form, steps: form.steps.map((x, n) => (n === i ? e.target.value : x)) })} value={s} /></FormField></div>
                                    {form.steps.length > 1 ? <Button aria-label={t('mtc.duty.removeStep')} onClick={() => setForm({ ...form, steps: form.steps.filter((_, n) => n !== i) })} size="sm" type="button" variant="outline">×</Button> : null}
                                </div>
                            ))}
                            <div><Button disabled={form.steps.length >= choices.max_steps} onClick={() => setForm({ ...form, steps: [...form.steps, ''] })} size="sm" type="button" variant="outline">{t('mtc.duty.addStep')}</Button></div>
                        </div>
                    </div>
                )}
            </Dialog>
        </MaintenanceShell>
    );
}
