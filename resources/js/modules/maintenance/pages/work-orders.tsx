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
import { PartsPanel } from '@/modules/maintenance/components/parts-panel';
import type { Overview, Priority, Status, WorkOrderDetail, WorkOrderSummary } from '@/modules/maintenance/lib/maintenance';
import { apiRequest } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const PRIORITY_TONE: Record<Priority, StatusTone> = { urgent: 'danger', high: 'warning', normal: 'info', low: 'neutral' };
const STATUS_TONE: Record<Status, StatusTone> = { open: 'info', assigned: 'pending', in_progress: 'warning', on_hold: 'unknown', done: 'success', cancelled: 'neutral' };
const OPEN: Status[] = ['open', 'assigned', 'in_progress', 'on_hold'];

type Report = { title: string; description: string; category: string; department: string; roomId: string; area: string; priority: Priority; assetId: string };
const BLANK: Report = { title: '', description: '', category: 'other', department: 'housekeeping', roomId: '', area: '', priority: 'normal', assetId: '' };

/** Work orders: report what is broken, see how far each is, and (for a technician or a manager) work them. */
export default function WorkOrdersPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [report, setReport] = useState<Report | null>(null);
    const [photo, setPhoto] = useState<File | null>(null);
    const [pickerKey, setPickerKey] = useState(0);
    const [detail, setDetail] = useState<WorkOrderDetail | null>(null);
    const [loadFailed, setLoadFailed] = useState(false);
    const [sla, setSla] = useState<(Record<Priority, string> & { warn: string; escalate: string; nightFrom: string; nightTo: string }) | null>(null);
    const [ackNote, setAckNote] = useState('');
    const [hold, setHold] = useState({ reason: 'waiting_parts', note: '' });
    const [done, setDone] = useState({ note: '' });
    const [doneFile, setDoneFile] = useState<File | null>(null);
    const [reason, setReason] = useState('');
    const [tech, setTech] = useState('');
    const [block, setBlock] = useState({ kind: 'out_of_order', until: '' });
    const [oversold, setOversold] = useState<string[]>([]);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const place = (w: WorkOrderSummary) => (w.room !== null ? t('mtc.room', { number: w.room }) : (w.area ?? '—'));
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const left = (w: WorkOrderSummary) => {
        if (w.minutes_left === null) return '—';
        const m = Math.abs(w.minutes_left);
        const text = m >= 1440 ? t('mtc.days', { n: Math.floor(m / 1440) }) : m >= 60 ? t('mtc.hours', { n: Math.floor(m / 60) }) : t('mtc.minutes', { n: m });

        return w.overdue ? t('mtc.late', { time: text }) : t('mtc.left', { time: text });
    };

    async function open(w: { id: string }) {
        action.clear();
        setLoadFailed(false);
        setOversold([]);
        try {
            const d = await apiRequest<WorkOrderDetail>(`/maintenance/work-orders/${w.id}`, { method: 'GET' });

            setDetail(d);
            setReason('');
            setTech(d.work_order.assigned_to ?? d.technicians[0]?.id ?? '');
            setBlock({ kind: 'out_of_order', until: d.business_date });
            setDoneFile(null);
            setDone({ note: '' });
            setHold({ reason: 'waiting_parts', note: '' });
        } catch {
            setLoadFailed(true);
        }
    }

    async function refresh() {
        if (detail === null) return;

        try {
            setDetail(await apiRequest<WorkOrderDetail>(`/maintenance/work-orders/${detail.work_order.id}`, { method: 'GET' }));
        } catch {
            setLoadFailed(true);
        }
    }

    async function step(path: string, body: Record<string, unknown> | FormData) {
        if (detail === null) return;
        const result = await action.run<WorkOrderDetail>(`/maintenance/work-orders/${detail.work_order.id}/${path}`, { body: body instanceof FormData ? body : { ...body, lock_version: detail.work_order.lock_version } });

        if (result !== null) {
            setDetail(result);
            setOversold(result.oversold_nights ?? []);
            router.reload({ only: ['overview'] });
        }
    }

    async function submitReport() {
        if (report === null) return;
        const body = new FormData();

        body.set('title', report.title.trim());
        if (report.description.trim() !== '') body.set('description', report.description.trim());
        body.set('category', report.category);
        body.set('reporter_department', report.department);
        if (report.roomId !== '') body.set('room_id', report.roomId);
        if (report.area.trim() !== '') body.set('area', report.area.trim());
        body.set('priority', report.priority);
        if (report.assetId !== '') body.set('asset_id', report.assetId);
        if (photo !== null) body.set('photo', photo);
        const result = await action.run('/maintenance/work-orders', { body, reload: ['overview'] });

        if (result !== null) {
            setReport(null);
            setPhoto(null);
            setPickerKey((k) => k + 1);
        }
    }

    async function finish() {
        if (detail === null) return;
        const body = new FormData();

        body.set('note', done.note.trim());
        body.set('lock_version', String(detail.work_order.lock_version));
        if (doneFile !== null) body.set('photo', doneFile);
        await step('complete', body);
    }

    async function saveSla() {
        if (sla === null) return;
        const n = (p: Priority) => Number(sla[p]);
        const result = await action.run('/maintenance/sla', { body: { urgent: n('urgent'), high: n('high'), normal: n('normal'), low: n('low'), warn_percent: Number(sla.warn), escalate_percent: Number(sla.escalate), night_from_hour: Number(sla.nightFrom), night_to_hour: Number(sla.nightTo), lock_version: overview.sla_lock_version }, reload: ['overview'] });

        if (result !== null) setSla(null);
    }

    const columns: DataGridColumn<WorkOrderSummary>[] = [
        { id: 'number', label: t('mtc.col.number'), value: (w) => w.number, rowHeader: true },
        { id: 'title', label: t('mtc.col.title'), value: (w) => w.title, searchText: (w) => `${w.title} ${w.number} ${place(w)}` },
        { id: 'place', label: t('mtc.col.place'), value: (w) => place(w) },
        { id: 'category', label: t('mtc.col.category'), value: (w) => w.category, filter: 'select', filterLabel: (v) => label('mtc.category', v), cell: (w) => label('mtc.category', w.category) },
        { id: 'priority', label: t('mtc.col.priority'), value: (w) => w.priority, filter: 'select', filterLabel: (v) => label('mtc.priority', v), cell: (w) => <StatusBadge label={label('mtc.priority', w.priority)} tone={PRIORITY_TONE[w.priority]} /> },
        { id: 'status', label: t('mtc.col.status'), value: (w) => w.status, filter: 'select', filterLabel: (v) => label('mtc.status', v), cell: (w) => <span className="flex flex-wrap items-center gap-1"><StatusBadge label={label('mtc.status', w.status)} tone={STATUS_TONE[w.status]} />{w.hold_reason !== null ? <span className="text-xs text-muted-foreground">{label('mtc.hold', w.hold_reason)}</span> : null}{w.off_sale ? <StatusBadge label={t('mtc.offSale')} tone="danger" /> : null}</span> },
        { id: 'due', label: t('mtc.col.due'), value: (w) => w.due_at, cell: (w) => <span className={w.overdue ? 'font-semibold text-danger' : ''}>{left(w)}</span> },
        { id: 'who', label: t('mtc.col.assigned'), value: (w) => w.assigned_name ?? '—', filter: 'select' },
        { id: 'open', label: '', value: () => '', sortable: false, cell: (w) => <Button onClick={() => void open(w)} size="sm" type="button" variant="outline">{t('mtc.open')}</Button> },
    ];
    const mine = overview.work_orders.filter((w) => w.assigned_to === overview.me && OPEN.includes(w.status));
    const grid = (rows: WorkOrderSummary[], id: string) => <DataGrid caption={t('mtc.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('mtc.empty')} />} getRowId={(w) => w.id} id={id} rows={rows} testId={id} />;
    const d = detail?.work_order ?? null;
    const dm = detail?.may ?? null;

    return (
        <MaintenanceShell
            actions={<div className="flex flex-wrap gap-2">{overview.may.manage ? <Button onClick={() => setSla({ urgent: String(overview.sla.urgent), high: String(overview.sla.high), normal: String(overview.sla.normal), low: String(overview.sla.low), warn: String(overview.escalation.warn), escalate: String(overview.escalation.escalate), nightFrom: String(overview.escalation.night_from), nightTo: String(overview.escalation.night_to) })} type="button" variant="outline">{t('mtc.sla.open')}</Button> : null}{overview.may.report ? <Button onClick={() => { action.clear(); setReport(BLANK); }} type="button">{t('mtc.report')}</Button> : null}</div>}
            description={t('mtc.description')}
            title={t('mtc.title')}
        >
            {loadFailed ? <Alert title={t('mtc.loadFailed')} tone="danger" /> : null}
            {overview.escalations.length > 0 ? (
                <section aria-labelledby="mtc-esc-h" className="flex flex-col gap-2 border border-warning bg-surface p-3" data-testid="mtc-escalations">
                    <h2 className="font-semibold" id="mtc-esc-h">{t('mtc.esc.title', { count: overview.escalations.length })}</h2>
                    <Input aria-label={t('mtc.esc.note')} maxLength={200} onChange={(e) => setAckNote(e.target.value)} placeholder={t('mtc.esc.note')} value={ackNote} />
                    <ul className="flex flex-col divide-y divide-border">
                        {overview.escalations.map((e) => (
                            <li className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" key={e.id}>
                                <span><span className="font-medium">{e.number}</span> · {e.title} · {e.place ?? '—'} <StatusBadge label={label('mtc.priority', e.priority)} tone={PRIORITY_TONE[e.priority]} /> <StatusBadge label={t(e.overdue ? 'mtc.esc.overdue' : 'mtc.esc.near')} tone={e.overdue ? 'danger' : 'warning'} /> <span className="text-xs text-muted-foreground">{t('mtc.esc.level', { level: e.level, target: label('mtc.esc.target', e.target), shift: label('mtc.esc.shift', e.shift) })}{!e.assigned ? ` · ${t('mtc.esc.unassigned')}` : ''}</span></span>
                                <span className="flex gap-2">
                                    <Button onClick={() => void open({ id: e.work_order_id })} size="sm" type="button" variant="outline">{t('mtc.open')}</Button>
                                    <Button disabled={action.busy} onClick={async () => { const done = await action.run(`/maintenance/escalations/${e.id}/acknowledge`, { body: { note: ackNote.trim() === '' ? null : ackNote.trim() }, reload: ['overview'] }); if (done !== null) setAckNote(''); }} size="sm" type="button">{t('mtc.esc.ack')}</Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="mtc-kpis">
                <Metric label={t('mtc.kpi.open')} value={String(overview.counts.open + overview.counts.assigned)} />
                <Metric label={t('mtc.kpi.progress')} value={String(overview.counts.in_progress + overview.counts.on_hold)} />
                <Metric detail={overview.overdue > 0 ? t('mtc.kpi.overdueHint') : undefined} label={t('mtc.kpi.overdue')} value={String(overview.overdue)} />
                <Metric label={t('mtc.kpi.done')} value={String(overview.counts.done)} />
            </div>
            {overview.may.perform || overview.may.manage ? (
                <Tabs defaultValue={overview.may.perform && !overview.may.manage ? 'mine' : 'all'}>
                    <TabsList aria-label={t('mtc.title')}>
                        {overview.may.perform ? <TabsTrigger value="mine">{t('mtc.tab.mine', { count: mine.length })}</TabsTrigger> : null}
                        <TabsTrigger value="all">{t('mtc.tab.all')}</TabsTrigger>
                    </TabsList>
                    {overview.may.perform ? <TabsContent className="flex flex-col gap-3" value="mine">{grid(mine, 'mtc.mine')}</TabsContent> : null}
                    <TabsContent className="flex flex-col gap-3" value="all">{grid(overview.work_orders, 'mtc.all')}</TabsContent>
                </Tabs>
            ) : grid(overview.work_orders, 'mtc.all')}

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setReport(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={report?.title.trim() === ''} loading={action.busy} onClick={() => void submitReport()} type="button">{t('mtc.report.send')}</Button></>}
                onClose={() => setReport(null)}
                open={report !== null}
                title={t('mtc.report')}
            >
                {report !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('title')} field="title" label={t('mtc.f.title')}><Input maxLength={80} onChange={(e) => setReport({ ...report, title: e.target.value })} value={report.title} /></FormField></div>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('description')} field="description" label={t('mtc.f.description')}><Input maxLength={500} onChange={(e) => setReport({ ...report, description: e.target.value })} value={report.description} /></FormField></div>
                        <FormField error={action.fieldError('room_id')} field="room_id" label={t('mtc.f.room')}>
                            <Select onChange={(e) => setReport({ ...report, roomId: e.target.value })} value={report.roomId}><option value="">{t('mtc.f.noRoom')}</option>{overview.rooms.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}</Select>
                        </FormField>
                        <FormField error={action.fieldError('area')} field="area" hint={t('mtc.f.areaHint')} label={t('mtc.f.area')}><Input maxLength={80} onChange={(e) => setReport({ ...report, area: e.target.value })} value={report.area} /></FormField>
                        <FormField error={action.fieldError('category')} field="category" label={t('mtc.f.category')}>
                            <Select onChange={(e) => setReport({ ...report, category: e.target.value })} value={report.category}>{overview.categories.map((c) => <option key={c} value={c}>{label('mtc.category', c)}</option>)}</Select>
                        </FormField>
                        <FormField error={action.fieldError('priority')} field="priority" label={t('mtc.f.priority')}>
                            <Select onChange={(e) => setReport({ ...report, priority: e.target.value as Priority })} value={report.priority}>{overview.priorities.map((p) => <option key={p} value={p}>{label('mtc.priority', p)}</option>)}</Select>
                        </FormField>
                        <FormField error={action.fieldError('reporter_department')} field="reporter_department" label={t('mtc.f.department')}>
                            <Select onChange={(e) => setReport({ ...report, department: e.target.value })} value={report.department}>{overview.departments.map((c) => <option key={c} value={c}>{label('mtc.department', c)}</option>)}</Select>
                        </FormField>
                        {overview.assets.length > 0 ? (
                            <div className="sm:col-span-2"><FormField error={action.fieldError('asset_id')} field="asset_id" hint={t('mtc.f.assetHint')} label={t('mtc.f.asset')}>
                                <Select onChange={(e) => setReport({ ...report, assetId: e.target.value })} value={report.assetId}><option value="">{t('mtc.f.noAsset')}</option>{overview.assets.map((a) => <option key={a.id} value={a.id}>{a.number} · {a.name}</option>)}</Select>
                            </FormField></div>
                        ) : null}
                        <FormField error={action.fieldError('photo')} field="photo" label={t('mtc.f.photo')}><Input accept="image/jpeg,image/png" capture="environment" key={pickerKey} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} type="file" /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                className="w-[min(44rem,calc(100vw-2rem))]"
                footer={<Button onClick={() => setDetail(null)} type="button" variant="outline">{t('mtc.close')}</Button>}
                onClose={() => setDetail(null)}
                open={detail !== null}
                title={d === null ? '' : `${d.number} · ${d.title}`}
            >
                {d !== null && dm !== null && detail !== null && (
                    <div className="flex flex-col gap-4">
                        {failure}
                        {oversold.length > 0 ? <Alert title={t('mtc.oversold', { nights: oversold.map((n) => format.date(n)).join(', ') })} tone="warning" /> : null}
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge label={label('mtc.priority', d.priority)} tone={PRIORITY_TONE[d.priority]} />
                            <StatusBadge label={label('mtc.status', d.status)} tone={STATUS_TONE[d.status]} />
                            {d.hold_reason !== null ? <span className="text-sm">{label('mtc.hold', d.hold_reason)}{d.hold_note !== null ? ` · ${d.hold_note}` : ''}</span> : null}
                            {d.block !== null ? <StatusBadge label={t('mtc.offSaleKind', { kind: label('mtc.block', d.block.kind) })} tone="danger" /> : null}
                        </div>
                        <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                            <div><dt className="text-muted-foreground">{t('mtc.col.place')}</dt><dd>{place(d)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.col.category')}</dt><dd>{label('mtc.category', d.category)} · {label('mtc.department', d.department)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.reportedBy')}</dt><dd>{d.reported_by ?? '—'} · {format.instant(d.reported_at)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.col.due')}</dt><dd className={d.overdue ? 'font-semibold text-danger' : ''}>{format.instant(d.due_at)} · {left(d)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.col.assigned')}</dt><dd>{d.assigned_name ?? '—'}</dd></div>
                            {d.asset !== null ? <div><dt className="text-muted-foreground">{t('mtc.f.asset')}</dt><dd>{d.asset.number} · {d.asset.name}{d.preventive ? ` · ${t('mtc.preventive')}` : ''}</dd></div> : null}
                        </dl>
                        {d.description !== null ? <p className="text-sm">{d.description}</p> : null}
                        <div className="flex flex-wrap gap-2">
                            {d.has_report_photo ? <Button asChild size="sm" variant="outline"><a href={`/maintenance/work-orders/${d.id}/photo/report`} rel="noreferrer" target="_blank">{t('mtc.photo.report')}</a></Button> : null}
                            {d.has_done_photo ? <Button asChild size="sm" variant="outline"><a href={`/maintenance/work-orders/${d.id}/photo/done`} rel="noreferrer" target="_blank">{t('mtc.photo.done')}</a></Button> : null}
                        </div>
                        {d.done_note !== null ? <Alert title={t('mtc.doneNote', { by: d.done_by ?? '', note: d.done_note })} tone="success" /> : null}
                        {d.cancel_reason !== null ? <Alert title={t('mtc.cancelNote', { reason: d.cancel_reason })} tone="warning" /> : null}

                        {dm.assign ? (
                            <div className="flex flex-wrap items-end gap-2 border-t border-border pt-3">
                                <FormField label={t('mtc.assign.to')}><Select onChange={(e) => setTech(e.target.value)} value={tech}>{detail.technicians.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</Select></FormField>
                                <Button disabled={action.busy || tech === ''} onClick={() => void step('assign', { technician_id: tech })} size="sm" type="button">{t('mtc.assign.do')}</Button>
                            </div>
                        ) : null}
                        {dm.prioritize ? (
                            <div className="flex flex-wrap items-center gap-2"><span className="text-sm text-muted-foreground">{t('mtc.f.priority')}:</span>
                                {overview.priorities.filter((p) => p !== d.priority).map((p) => <Button disabled={action.busy} key={p} onClick={() => void step('priority', { priority: p })} size="sm" type="button" variant="outline">{label('mtc.priority', p)}</Button>)}
                            </div>
                        ) : null}
                        <div className="flex flex-wrap gap-2 border-t border-border pt-3">
                            {dm.start ? <Button disabled={action.busy} onClick={() => void step('start', {})} type="button">{t('mtc.start')}</Button> : null}
                            {dm.resume ? <Button disabled={action.busy} onClick={() => void step('resume', {})} type="button">{t('mtc.resume')}</Button> : null}
                        </div>
                        {dm.hold ? (
                            <div className="grid gap-2 sm:grid-cols-[1fr_2fr_auto] sm:items-end">
                                <FormField label={t('mtc.hold.reason')}><Select onChange={(e) => setHold({ ...hold, reason: e.target.value })} value={hold.reason}>{overview.hold_reasons.map((r) => <option key={r} value={r}>{label('mtc.hold', r)}</option>)}</Select></FormField>
                                <FormField label={t('mtc.hold.note')}><Input maxLength={200} onChange={(e) => setHold({ ...hold, note: e.target.value })} value={hold.note} /></FormField>
                                <Button disabled={action.busy} onClick={() => void step('hold', { reason: hold.reason, note: hold.note.trim() === '' ? null : hold.note.trim() })} size="sm" type="button" variant="outline">{t('mtc.hold.do')}</Button>
                            </div>
                        ) : null}
                        {dm.complete ? (
                            <div className="flex flex-col gap-2 border border-border p-3">
                                <h3 className="font-semibold">{t('mtc.complete.title')}</h3>
                                <p className="text-xs text-muted-foreground">{t('mtc.complete.hint')}</p>
                                <FormField error={action.fieldError('note')} field="note" label={t('mtc.complete.note')}><Input maxLength={300} onChange={(e) => setDone({ note: e.target.value })} value={done.note} /></FormField>
                                <FormField error={action.fieldError('photo')} field="photo" label={t('mtc.complete.photo')}><Input accept="image/jpeg,image/png" capture="environment" onChange={(e) => setDoneFile(e.target.files?.[0] ?? null)} type="file" /></FormField>
                                <div><Button disabled={done.note.trim() === ''} loading={action.busy} onClick={() => void finish()} type="button">{t('mtc.complete.do')}</Button></div>
                            </div>
                        ) : null}
                        {dm.block ? (
                            <div className="flex flex-wrap items-end gap-2 border-t border-border pt-3">
                                <FormField label={t('mtc.block.kind')}><Select onChange={(e) => setBlock({ ...block, kind: e.target.value })} value={block.kind}>{['out_of_order', 'out_of_service'].map((k) => <option key={k} value={k}>{label('mtc.block', k)}</option>)}</Select></FormField>
                                <FormField error={action.fieldError('until')} field="until" label={t('mtc.block.until')}><DatePicker min={detail.business_date} onChange={(e) => setBlock({ ...block, until: e.target.value })} value={block.until} /></FormField>
                                <Button disabled={action.busy || block.until === ''} onClick={() => void step('block', block)} size="sm" type="button" variant="outline">{t('mtc.block.do')}</Button>
                            </div>
                        ) : null}
                        {dm.release ? <div><Button disabled={action.busy} onClick={() => void step('release-room', {})} size="sm" type="button" variant="outline">{t('mtc.block.release')}</Button></div> : null}
                        {dm.cancel ? (
                            <div className="flex flex-wrap items-end gap-2 border-t border-border pt-3">
                                <FormField error={action.fieldError('reason')} field="reason" label={t('mtc.cancel.reason')}><Input maxLength={200} onChange={(e) => setReason(e.target.value)} value={reason} /></FormField>
                                <Button disabled={action.busy || reason.trim() === ''} onClick={() => void step('cancel', { reason: reason.trim() })} size="sm" type="button" variant="outline">{t('mtc.cancel.do')}</Button>
                            </div>
                        ) : null}

                        <PartsPanel onChanged={() => void refresh()} status={detail.work_order.status} workOrderId={detail.work_order.id} />

                        <section aria-labelledby="mtc-history-h" className="flex flex-col gap-1 border-t border-border pt-3">
                            <h3 className="text-sm font-semibold" id="mtc-history-h">{t('mtc.history')}</h3>
                            <ol className="flex flex-col gap-1 text-sm" data-testid="mtc-history">
                                {detail.events.map((e, i) => <li key={i}>{format.instant(e.at)} · {label('mtc.event', e.kind)}{e.note !== null ? `: ${e.note}` : ''} · {e.by ?? '—'}</li>)}
                            </ol>
                        </section>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setSla(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveSla()} type="button">{t('mtc.sla.save')}</Button></>}
                onClose={() => setSla(null)}
                open={sla !== null}
                title={t('mtc.sla.title')}
            >
                {sla !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{overview.sla_is_baseline ? t('mtc.sla.baseline') : t('mtc.sla.hint')}</p>
                        {failure}
                        {overview.priorities.map((p) => <FormField error={p === 'urgent' ? action.fieldError('sla') : undefined} key={p} label={t('mtc.sla.minutes', { priority: label('mtc.priority', p) })}><Input inputMode="numeric" onChange={(e) => setSla({ ...sla, [p]: e.target.value })} value={sla[p]} /></FormField>)}
                        <h3 className="pt-2 font-semibold">{t('mtc.esc.settings')}</h3>
                        <p className="text-xs text-muted-foreground">{t('mtc.esc.matrix')}</p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={action.fieldError('warn_percent')} field="warn_percent" label={t('mtc.esc.warn')}><Input inputMode="numeric" onChange={(e) => setSla({ ...sla, warn: e.target.value })} value={sla.warn} /></FormField>
                            <FormField error={action.fieldError('escalate_percent')} field="escalate_percent" label={t('mtc.esc.escalate')}><Input inputMode="numeric" onChange={(e) => setSla({ ...sla, escalate: e.target.value })} value={sla.escalate} /></FormField>
                            <FormField error={action.fieldError('night_from_hour')} field="night_from_hour" label={t('mtc.esc.nightFrom')}><Input inputMode="numeric" onChange={(e) => setSla({ ...sla, nightFrom: e.target.value })} value={sla.nightFrom} /></FormField>
                            <FormField error={action.fieldError('night_to_hour')} field="night_to_hour" label={t('mtc.esc.nightTo')}><Input inputMode="numeric" onChange={(e) => setSla({ ...sla, nightTo: e.target.value })} value={sla.nightTo} /></FormField>
                        </div>
                    </div>
                )}
            </Dialog>
        </MaintenanceShell>
    );
}
