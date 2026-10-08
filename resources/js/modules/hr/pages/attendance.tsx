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
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AttendanceReview, type ReviewItem } from '@/modules/hr/components/attendance-review';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { AttendanceCorrection, AttendanceOverview, AttendanceRow, AttendanceStatus, AttendanceSummaryRow, CorrectionOverview, CorrectionStatus, OvertimeOverview, OvertimeRequest, OvertimeStatus } from '@/modules/hr/lib/hr';
import { deviceId } from '@/modules/hr/lib/device';
import { descriptorOf } from '@/shared/lib/face';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<AttendanceStatus, StatusTone> = { upcoming: 'neutral', not_in: 'warning', on_duty: 'info', present: 'success', missing_out: 'warning', absent: 'danger' };
const OVERTIME_TONE: Record<OvertimeStatus, StatusTone> = { pending_approval: 'pending', approved: 'success', rejected: 'danger', cancelled: 'neutral' };
const CORRECTION_TONE: Record<CorrectionStatus, StatusTone> = { pending_approval: 'pending', applied: 'success', rejected: 'danger', cancelled: 'neutral' };
const hm = (minutes: number) => `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;

function position(): Promise<{ latitude: number; longitude: number; accuracy: number } | null> {
    return new Promise((resolve) => {
        if (typeof navigator === 'undefined' || !('geolocation' in navigator)) return resolve(null);
        navigator.geolocation.getCurrentPosition((p) => resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy }), () => resolve(null), { enableHighAccuracy: true, timeout: 10_000, maximumAge: 0 });
    });
}

/** Attendance: the person's own shift to clock in and out of, who came on a day, and how the period went (for those who manage it). */
export default function AttendancePage({ overview, overtime, corrections, review = null }: { overview: AttendanceOverview; overtime: OvertimeOverview | null; corrections: CorrectionOverview | null; review?: ReviewItem[] | null }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [photo, setPhoto] = useState<File | null>(null);
    const [photoKey, setPhotoKey] = useState(0);
    const [date, setDate] = useState(overview.day?.date ?? overview.today);
    const [from, setFrom] = useState(overview.summary?.from ?? overview.today);
    const [to, setTo] = useState(overview.summary?.to ?? overview.today);
    const [manual, setManual] = useState<{ employeeId: string; date: string; inTime: string; outTime: string; reason: string } | null>(null);
    const [ask, setAsk] = useState<{ employeeId: string; date: string; minutes: string; reason: string } | null>(null);
    const [fix, setFix] = useState<{ employeeId: string; date: string; inTime: string; outTime: string; reason: string } | null>(null);
    const [settings, setSettings] = useState<{ lat: string; lng: string; radius: string; selfie: boolean; late: string; early: string; extra: string; face: 'off' | 'flag' | 'require'; lock: number | null } | null>(null);
    const [reading, setReading] = useState(false);
    const [faceProblem, setFaceProblem] = useState<MessageKey | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const s = overview.settings;
    const me = overview.me;
    const time = (iso: string | null) => (iso === null ? '—' : format.instant(iso));
    const shiftText = (x: { starts_at: string; ends_at: string; starts2_at: string | null; ends2_at: string | null }) => `${x.starts_at}–${x.ends_at}${x.starts2_at !== null ? ` · ${x.starts2_at}–${x.ends2_at}` : ''}`;
    const number = (v: string) => (v.trim() === '' ? null : Number(v));

    async function punch(kind: 'clock-in' | 'clock-out') {
        const body = new FormData();
        const where = await position();

        if (where !== null) {
            body.set('latitude', String(where.latitude));
            body.set('longitude', String(where.longitude));
            body.set('accuracy', String(where.accuracy));
        }

        const device = deviceId();
        if (device !== null) body.set('device', device);

        if (photo !== null) body.set('photo', photo);

        // With face matching on, the browser reads the face in the selfie into 128 numbers and sends only those along with it.
        setFaceProblem(null);

        if (s.face_mode !== 'off' && photo !== null) {
            setReading(true);

            try {
                const face = await descriptorOf(photo);

                if (face !== null) body.set('face', JSON.stringify(face));
                else if (s.face_mode === 'require') {
                    setFaceProblem('hr.att.faceNone');

                    return;
                }
            } catch {
                if (s.face_mode === 'require') {
                    setFaceProblem('hr.att.faceUnavailable');

                    return;
                }
            } finally {
                setReading(false);
            }
        }

        const result = await action.run(`/hr/attendance/${kind}`, { idempotencyKey: newIdempotencyKey(), body, reload: ['overview'] });

        if (result !== null) {
            setPhoto(null);
            setPhotoKey((k) => k + 1);
        }
    }

    async function recordManually() {
        if (manual === null) return;
        const result = await action.run('/hr/attendance/manual', { idempotencyKey: newIdempotencyKey(), body: { employee_id: manual.employeeId, work_date: manual.date, in_time: manual.inTime, out_time: manual.outTime === '' ? null : manual.outTime, reason: manual.reason.trim() }, reload: ['overview'] });

        if (result !== null) setManual(null);
    }

    async function askOvertime() {
        if (ask === null) return;
        const result = await action.run('/hr/overtime', { idempotencyKey: newIdempotencyKey(), body: { employee_id: ask.employeeId, work_date: ask.date, minutes: Number(ask.minutes), reason: ask.reason.trim() }, reload: ['overview', 'overtime'] });

        if (result !== null) setAsk(null);
    }

    async function correctDay() {
        if (fix === null) return;
        const result = await action.run('/hr/attendance/corrections', { idempotencyKey: newIdempotencyKey(), body: { employee_id: fix.employeeId, work_date: fix.date, in_time: fix.inTime, out_time: fix.outTime === '' ? null : fix.outTime, reason: fix.reason.trim() }, reload: ['overview', 'corrections'] });

        if (result !== null) setFix(null);
    }

    const settle = (url: string, reload: string[]) => action.run(url, { body: {}, reload });

    async function saveSettings() {
        if (settings === null) return;
        const result = await action.run('/hr/attendance/settings', {
            body: { latitude: number(settings.lat), longitude: number(settings.lng), radius_m: Number(settings.radius), require_selfie: settings.selfie, late_grace_minutes: Number(settings.late), early_grace_minutes: Number(settings.early), extra_after_minutes: Number(settings.extra), lock_version: settings.lock },
            reload: ['overview'],
        });

        if (result !== null && settings.face !== s.face_mode) {
            await action.run('/hr/attendance/face-mode', { body: { face_mode: settings.face }, reload: ['overview'] });
        }

        if (result !== null) setSettings(null);
    }

    const flags = (r: AttendanceRow) => [r.late_minutes > 0 ? t('hr.att.late', { n: r.late_minutes }) : null, r.early_minutes > 0 ? t('hr.att.early', { n: r.early_minutes }) : null, r.overtime_minutes > 0 ? t('hr.att.overtime', { n: r.overtime_minutes }) : null, r.unapproved_minutes > 0 ? t('hr.att.unapproved', { n: r.unapproved_minutes }) : null].filter((x) => x !== null).join(' · ');

    const dayColumns: DataGridColumn<AttendanceRow>[] = [
        { id: 'name', label: t('hr.col.name'), value: (r) => r.employee.name, rowHeader: true, cell: (r) => <span>{r.employee.name}<span className="block text-xs text-muted-foreground">{r.employee.number} · {label('hr.department', r.employee.department)}</span></span> },
        { id: 'shift', label: t('hr.att.shift'), value: (r) => r.shift.code, cell: (r) => <span>{r.shift.code}<span className="block text-xs text-muted-foreground">{shiftText(r.shift)}</span></span> },
        { id: 'status', label: t('hr.col.status'), value: (r) => r.status, filter: 'select', filterLabel: (v) => label('hr.att.status', v), cell: (r) => <StatusBadge label={label('hr.att.status', r.status)} tone={TONE[r.status]} /> },
        { id: 'in', label: t('hr.att.in'), value: (r) => r.record?.in_at ?? '', cell: (r) => <span>{time(r.record?.in_at ?? null)}{r.record !== null && r.record.in_method === 'manual' ? <span className="block text-xs text-muted-foreground">{t('hr.att.manual')}</span> : null}</span> },
        { id: 'out', label: t('hr.att.out'), value: (r) => r.record?.out_at ?? '', cell: (r) => time(r.record?.out_at ?? null) },
        { id: 'worked', label: t('hr.att.worked'), align: 'right', value: (r) => r.worked_minutes ?? 0, cell: (r) => (r.worked_minutes === null ? '—' : hm(r.worked_minutes)) },
        { id: 'flags', label: t('hr.att.flags'), value: (r) => flags(r), cell: (r) => <span className="text-sm">{flags(r) || '—'}{r.record?.manual_reason ? <span className="block text-xs text-muted-foreground">{r.record.manual_reason}</span> : null}</span> },
        { id: 'photo', label: '', value: () => '', sortable: false, cell: (r) => r.record !== null && (r.record.has_in_photo || r.record.has_out_photo) ? <Button asChild size="sm" variant="outline"><a href={`/hr/attendance/${r.record.id}/photo/${r.record.has_in_photo ? 'in' : 'out'}`} rel="noreferrer" target="_blank">{t('hr.att.selfie')}</a></Button> : null },
    ];
    const summaryColumns: DataGridColumn<AttendanceSummaryRow>[] = [
        { id: 'name', label: t('hr.col.name'), value: (r) => r.employee.name, rowHeader: true, cell: (r) => <span>{r.employee.name}<span className="block text-xs text-muted-foreground">{r.employee.number} · {label('hr.department', r.employee.department)}</span></span> },
        { id: 'scheduled', label: t('hr.att.scheduled'), align: 'right', value: (r) => r.scheduled },
        { id: 'present', label: t('hr.att.present'), align: 'right', value: (r) => r.present },
        { id: 'absent', label: t('hr.att.absent'), align: 'right', value: (r) => r.absent, cell: (r) => (r.absent > 0 ? <span className="text-danger">{r.absent}</span> : '0') },
        { id: 'late', label: t('hr.att.lateDays'), align: 'right', value: (r) => r.late_minutes, cell: (r) => `${r.late_days} · ${hm(r.late_minutes)}` },
        { id: 'early', label: t('hr.att.earlyDays'), align: 'right', value: (r) => r.early_minutes, cell: (r) => `${r.early_days} · ${hm(r.early_minutes)}` },
        { id: 'extra', label: t('hr.att.extraHours'), align: 'right', value: (r) => r.extra_minutes, cell: (r) => hm(r.extra_minutes) },
        { id: 'overtime', label: t('hr.att.overtimeHours'), align: 'right', value: (r) => r.overtime_minutes, cell: (r) => hm(r.overtime_minutes) },
        { id: 'unapproved', label: t('hr.att.unapprovedHours'), align: 'right', value: (r) => r.unapproved_minutes, cell: (r) => (r.unapproved_minutes > 0 ? <span className="text-warning">{hm(r.unapproved_minutes)}</span> : hm(0)) },
        { id: 'worked', label: t('hr.att.worked'), align: 'right', value: (r) => r.worked_minutes, cell: (r) => hm(r.worked_minutes) },
    ];

    const clock = (iso: string | null) => (iso === null ? '—' : new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', timeZone: format.timeZone ?? 'UTC' }).format(new Date(iso)));
    const range = (x: { in_at: string | null; out_at: string | null }) => `${clock(x.in_at)} → ${clock(x.out_at)}`;
    const overtimeColumns: DataGridColumn<OvertimeRequest>[] = [
        { id: 'name', label: t('hr.col.name'), value: (r) => r.employee.name, rowHeader: true, cell: (r) => <span>{r.employee.name}<span className="block text-xs text-muted-foreground">{r.employee.number} · {label('hr.department', r.employee.department)}</span></span> },
        { id: 'date', label: t('hr.att.date'), value: (r) => r.work_date, cell: (r) => format.date(r.work_date) },
        { id: 'minutes', label: t('hr.att.overtimeMinutes'), align: 'right', value: (r) => r.minutes, cell: (r) => hm(r.minutes) },
        { id: 'status', label: t('hr.col.status'), value: (r) => r.status, filter: 'select', filterLabel: (v) => label('hr.att.overtimeStatus', v), cell: (r) => <StatusBadge label={label('hr.att.overtimeStatus', r.status)} tone={OVERTIME_TONE[r.status]} /> },
        { id: 'reason', label: t('hr.att.reasonShort'), value: (r) => r.reason },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (r) => <span className="flex gap-2">{r.status === 'pending_approval' ? <Button disabled={action.busy} onClick={() => void settle(`/hr/overtime/${r.id}/release`, ['overtime'])} size="sm" type="button">{t('hr.att.take')}</Button> : null}{r.may_cancel ? <Button disabled={action.busy} onClick={() => void settle(`/hr/overtime/${r.id}/cancel`, ['overtime'])} size="sm" type="button" variant="outline">{t('hr.att.cancelRequest')}</Button> : null}</span> },
    ];
    const correctionColumns: DataGridColumn<AttendanceCorrection>[] = [
        { id: 'name', label: t('hr.col.name'), value: (r) => r.employee.name, rowHeader: true, cell: (r) => <span>{r.employee.name}<span className="block text-xs text-muted-foreground">{r.employee.number} · {label('hr.department', r.employee.department)}</span></span> },
        { id: 'date', label: t('hr.att.date'), value: (r) => r.work_date, cell: (r) => format.date(r.work_date) },
        { id: 'before', label: t('hr.att.before'), value: (r) => range(r.old), sortable: false },
        { id: 'after', label: t('hr.att.after'), value: (r) => range(r.new), sortable: false },
        { id: 'status', label: t('hr.col.status'), value: (r) => r.status, filter: 'select', filterLabel: (v) => label('hr.att.correctionStatus', v), cell: (r) => <StatusBadge label={label('hr.att.correctionStatus', r.status)} tone={CORRECTION_TONE[r.status]} /> },
        { id: 'reason', label: t('hr.att.reasonShort'), value: (r) => r.reason },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (r) => r.status === 'pending_approval' ? <span className="flex gap-2"><Button disabled={action.busy} onClick={() => void settle(`/hr/attendance/corrections/${r.id}/apply`, ['overview', 'corrections'])} size="sm" type="button">{t('hr.att.take')}</Button><Button disabled={action.busy} onClick={() => void settle(`/hr/attendance/corrections/${r.id}/cancel`, ['corrections'])} size="sm" type="button" variant="outline">{t('hr.att.cancelRequest')}</Button></span> : null },
    ];

    return (
        <HrShell
            actions={overview.may.manage ? <><Button onClick={() => { action.clear(); setSettings({ lat: s.latitude === null ? '' : String(s.latitude), lng: s.longitude === null ? '' : String(s.longitude), radius: String(s.radius_m), selfie: s.require_selfie, late: String(s.late_grace), early: String(s.early_grace), extra: String(s.extra_after), face: s.face_mode, lock: s.lock_version }); }} type="button" variant="outline">{t('hr.att.settings')}</Button><Button onClick={() => { action.clear(); setManual({ employeeId: overview.day?.rows[0]?.employee.id ?? '', date: overview.day?.date ?? overview.today, inTime: '', outTime: '', reason: '' }); }} type="button">{t('hr.att.record')}</Button></> : undefined}
            description={t('hr.att.description')}
            title={t('hr.att.title')}
        >
            {action.error !== null && manual === null && settings === null && ask === null && fix === null ? failure : null}
            {me !== null ? (
                <section aria-labelledby="hr-att-me" className="flex flex-col gap-3 border border-border bg-surface p-4" data-testid="hr-att-me">
                    <h2 className="font-semibold" id="hr-att-me">{t('hr.att.mine')}</h2>
                    {me.shift === null ? <p className="text-sm text-muted-foreground">{t('hr.att.noShift')}</p> : (
                        <>
                            <div className="flex flex-wrap items-center gap-3"><span className="text-2xl font-semibold">{me.shift.code} · {shiftText(me.shift)}</span><StatusBadge label={label('hr.att.status', me.shift.status)} tone={TONE[me.shift.status]} /></div>
                            <p className="text-sm text-muted-foreground">{t('hr.att.mineLine', { in: time(me.shift.record?.in_at ?? null), out: time(me.shift.record?.out_at ?? null) })}{me.shift.late_minutes > 0 ? ` · ${t('hr.att.late', { n: me.shift.late_minutes })}` : ''}</p>
                            {(me.may_clock_in || me.may_clock_out) ? (
                                <div className="flex flex-wrap items-end gap-3">
                                    {s.require_selfie || s.face_mode !== 'off' ? <FormField error={action.fieldError('photo')} field="photo" label={t('hr.att.selfieField')}><Input accept="image/*" capture="user" key={photoKey} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} type="file" /></FormField> : null}
                                    {me.may_clock_in ? <Button disabled={action.busy || reading || ((s.require_selfie || s.face_mode !== 'off') && photo === null)} loading={action.busy || reading} onClick={() => void punch('clock-in')} type="button">{t('hr.att.clockIn')}</Button> : null}
                                    {me.may_clock_out ? <Button disabled={action.busy || reading || ((s.require_selfie || s.face_mode !== 'off') && photo === null)} loading={action.busy || reading} onClick={() => void punch('clock-out')} type="button" variant="outline">{t('hr.att.clockOut')}</Button> : null}
                                </div>
                            ) : null}
                            {faceProblem !== null ? <Alert title={t(faceProblem)} tone="danger" /> : null}
                            {reading ? <p className="text-xs text-muted-foreground">{t('hr.att.faceReading')}</p> : null}
                            <p className="text-xs text-muted-foreground">{s.geofence ? t('hr.att.geofenceOn', { n: s.radius_m }) : t('hr.att.geofenceOff')}</p>
                        </>
                    )}
                </section>
            ) : null}
            {overview.may.manage && review !== null ? <AttendanceReview items={review} /> : null}

            {overview.may.manage && overview.day !== null && overview.summary !== null ? (
                <Tabs defaultValue="day">
                    <TabsList aria-label={t('hr.att.title')}>
                        <TabsTrigger value="day">{t('hr.att.dayTab')}</TabsTrigger>
                        <TabsTrigger value="summary">{t('hr.att.summaryTab')}</TabsTrigger>
                        {overtime !== null ? <TabsTrigger value="overtime">{t('hr.att.overtimeTab')}</TabsTrigger> : null}
                        {corrections !== null ? <TabsTrigger value="corrections">{t('hr.att.correctionsTab')}</TabsTrigger> : null}
                    </TabsList>
                    <TabsContent className="flex flex-col gap-3" value="day">
                        <div className="flex flex-wrap items-end gap-2">
                            <FormField label={t('hr.att.date')}><DatePicker onChange={(e) => setDate(e.target.value)} value={date} /></FormField>
                            <Button disabled={date === ''} onClick={() => router.get('/hr/attendance', { date })} type="button">{t('mtc.rep.show')}</Button>
                        </div>
                        <DataGrid caption={t('hr.att.dayTab')} columns={dayColumns} empty={<EmptyState illustration="checklist" title={t('hr.att.noRows')} />} getRowId={(r) => r.employee.id} id="hr.att.day" rows={overview.day.rows} testId="hr-att-day" />
                    </TabsContent>
                    <TabsContent className="flex flex-col gap-3" value="summary">
                        <div className="flex flex-wrap items-end gap-2">
                            <FormField label={t('mtc.rep.from')}><DatePicker onChange={(e) => setFrom(e.target.value)} value={from} /></FormField>
                            <FormField label={t('mtc.rep.to')}><DatePicker onChange={(e) => setTo(e.target.value)} value={to} /></FormField>
                            <FormField label={t('hr.col.department')}><Select onChange={(e) => router.get('/hr/attendance', { from, to, ...(e.target.value === '' ? {} : { department: e.target.value }) })} value={overview.summary.department ?? ''}><option value="">{t('hr.roster.allDepartments')}</option>{overview.departments.map((d) => <option key={d} value={d}>{label('hr.department', d)}</option>)}</Select></FormField>
                            <Button disabled={from === '' || to === ''} onClick={() => router.get('/hr/attendance', { from, to, ...(overview.summary?.department ? { department: overview.summary.department } : {}) })} type="button">{t('mtc.rep.show')}</Button>
                        </div>
                        <p className="text-xs text-muted-foreground">{t('hr.att.summaryHint')}</p>
                        <DataGrid caption={t('hr.att.summaryTab')} columns={summaryColumns} empty={<EmptyState illustration="checklist" title={t('hr.att.noRows')} />} getRowId={(r) => r.employee.id} id="hr.att.summary" rows={overview.summary.rows} testId="hr-att-summary" />
                    </TabsContent>
                    {overtime !== null ? (
                        <TabsContent className="flex flex-col gap-3" value="overtime">
                            <p className="text-xs text-muted-foreground">{t('hr.att.overtimeHint')}</p>
                            <div><Button onClick={() => { action.clear(); setAsk({ employeeId: overview.day?.rows[0]?.employee.id ?? '', date: overview.today, minutes: '60', reason: '' }); }} type="button">{t('hr.att.askOvertime')}</Button></div>
                            <DataGrid caption={t('hr.att.overtimeTab')} columns={overtimeColumns} empty={<EmptyState illustration="checklist" title={t('hr.att.noOvertime')} />} getRowId={(r) => r.id} id="hr.att.overtime" rows={overtime.requests} testId="hr-att-overtime" />
                        </TabsContent>
                    ) : null}
                    {corrections !== null ? (
                        <TabsContent className="flex flex-col gap-3" value="corrections">
                            <p className="text-xs text-muted-foreground">{t('hr.att.correctionsHint', { n: corrections.days_back })}</p>
                            <div><Button onClick={() => { action.clear(); setFix({ employeeId: overview.day?.rows[0]?.employee.id ?? '', date: overview.today, inTime: '', outTime: '', reason: '' }); }} type="button">{t('hr.att.correctDay')}</Button></div>
                            <DataGrid caption={t('hr.att.correctionsTab')} columns={correctionColumns} empty={<EmptyState illustration="checklist" title={t('hr.att.noCorrections')} />} getRowId={(r) => r.id} id="hr.att.corrections" rows={corrections.corrections} testId="hr-att-corrections" />
                        </TabsContent>
                    ) : null}
                </Tabs>
            ) : null}
            {me === null && !overview.may.manage ? <Alert title={t('hr.att.noEmployee')} tone="warning" /> : null}

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setManual(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={manual?.employeeId === '' || manual?.inTime === '' || manual?.reason.trim() === ''} loading={action.busy} onClick={() => void recordManually()} type="button">{t('hr.att.recordDo')}</Button></>}
                onClose={() => setManual(null)}
                open={manual !== null}
                title={t('hr.att.record')}
            >
                {manual !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('employee_id')} field="employee_id" hint={t('hr.att.recordHint')} label={t('hr.col.name')}><Select onChange={(e) => setManual({ ...manual, employeeId: e.target.value })} value={manual.employeeId}>{(overview.day?.rows ?? []).map((r) => <option key={r.employee.id} value={r.employee.id}>{r.employee.name} · {r.shift.code}</option>)}</Select></FormField></div>
                        <FormField error={action.fieldError('work_date')} field="work_date" label={t('hr.att.date')}><DatePicker onChange={(e) => setManual({ ...manual, date: e.target.value })} value={manual.date} /></FormField>
                        <div />
                        <FormField error={action.fieldError('in_time')} field="in_time" label={t('hr.att.in')}><Input maxLength={5} onChange={(e) => setManual({ ...manual, inTime: e.target.value })} placeholder="07:05" value={manual.inTime} /></FormField>
                        <FormField error={action.fieldError('out_time')} field="out_time" label={t('hr.att.out')}><Input maxLength={5} onChange={(e) => setManual({ ...manual, outTime: e.target.value })} placeholder="15:00" value={manual.outTime} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reason')}><Input maxLength={200} onChange={(e) => setManual({ ...manual, reason: e.target.value })} value={manual.reason} /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAsk(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={ask?.employeeId === '' || ask?.minutes.trim() === '' || ask?.reason.trim() === ''} loading={action.busy} onClick={() => void askOvertime()} type="button">{t('hr.att.askDo')}</Button></>}
                onClose={() => setAsk(null)}
                open={ask !== null}
                title={t('hr.att.askOvertime')}
            >
                {ask !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('employee_id')} field="employee_id" hint={t('hr.att.askHint')} label={t('hr.col.name')}><Select onChange={(e) => setAsk({ ...ask, employeeId: e.target.value })} value={ask.employeeId}>{(overview.day?.rows ?? []).map((r) => <option key={r.employee.id} value={r.employee.id}>{r.employee.name} · {r.shift.code}</option>)}</Select></FormField></div>
                        <FormField error={action.fieldError('work_date')} field="work_date" label={t('hr.att.date')}><DatePicker onChange={(e) => setAsk({ ...ask, date: e.target.value })} value={ask.date} /></FormField>
                        <FormField error={action.fieldError('minutes')} field="minutes" label={t('hr.att.overtimeMinutes')}><Input inputMode="numeric" onChange={(e) => setAsk({ ...ask, minutes: e.target.value })} value={ask.minutes} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setAsk({ ...ask, reason: e.target.value })} value={ask.reason} /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setFix(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={fix?.employeeId === '' || fix?.inTime === '' || fix?.reason.trim() === ''} loading={action.busy} onClick={() => void correctDay()} type="button">{t('hr.att.correctDo')}</Button></>}
                onClose={() => setFix(null)}
                open={fix !== null}
                title={t('hr.att.correctDay')}
            >
                {fix !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('employee_id')} field="employee_id" hint={t('hr.att.correctHint')} label={t('hr.col.name')}><Select onChange={(e) => setFix({ ...fix, employeeId: e.target.value })} value={fix.employeeId}>{(overview.day?.rows ?? []).map((r) => <option key={r.employee.id} value={r.employee.id}>{r.employee.name} · {r.shift.code}</option>)}</Select></FormField></div>
                        <FormField error={action.fieldError('work_date')} field="work_date" label={t('hr.att.date')}><DatePicker onChange={(e) => setFix({ ...fix, date: e.target.value })} value={fix.date} /></FormField>
                        <div />
                        <FormField error={action.fieldError('in_time')} field="in_time" label={t('hr.att.in')}><Input maxLength={5} onChange={(e) => setFix({ ...fix, inTime: e.target.value })} placeholder="07:00" value={fix.inTime} /></FormField>
                        <FormField error={action.fieldError('out_time')} field="out_time" label={t('hr.att.out')}><Input maxLength={5} onChange={(e) => setFix({ ...fix, outTime: e.target.value })} placeholder="15:00" value={fix.outTime} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setFix({ ...fix, reason: e.target.value })} value={fix.reason} /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setSettings(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveSettings()} type="button">{t('hr.saveSettings')}</Button></>}
                onClose={() => setSettings(null)}
                open={settings !== null}
                title={t('hr.att.settings')}
            >
                {settings !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{s.is_baseline ? t('hr.att.settingsBaseline') : t('hr.att.settingsHint')}</p>
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('latitude')} field="latitude" hint={t('hr.att.coordsHint')} label={t('hr.att.latitude')}><Input inputMode="decimal" onChange={(e) => setSettings({ ...settings, lat: e.target.value })} value={settings.lat} /></FormField>
                        <FormField error={action.fieldError('longitude')} field="longitude" label={t('hr.att.longitude')}><Input inputMode="decimal" onChange={(e) => setSettings({ ...settings, lng: e.target.value })} value={settings.lng} /></FormField>
                        <FormField error={action.fieldError('radius_m')} field="radius_m" label={t('hr.att.radius')}><Input inputMode="numeric" onChange={(e) => setSettings({ ...settings, radius: e.target.value })} value={settings.radius} /></FormField>
                        <label className="flex items-center gap-2 self-end text-sm"><input checked={settings.selfie} onChange={(e) => setSettings({ ...settings, selfie: e.target.checked })} type="checkbox" />{t('hr.att.requireSelfie')}</label>
                        <FormField error={action.fieldError('face_mode')} field="face_mode" hint={t('hr.att.faceModeHint')} label={t('hr.att.faceMode')}>
                            <Select onChange={(e) => setSettings({ ...settings, face: e.target.value as 'off' | 'flag' | 'require' })} searchable={false} value={settings.face}>
                                {(['off', 'flag', 'require'] as const).map((m) => <option key={m} value={m}>{t(`hr.att.faceMode.${m}` as MessageKey)}</option>)}
                            </Select>
                        </FormField>
                        {!s.geofence ? <p className="text-sm text-amber-700 sm:col-span-2">{t('hr.att.geofenceWarn')}</p> : null}
                        <FormField error={action.fieldError('late_grace_minutes')} field="late_grace_minutes" label={t('hr.att.lateGrace')}><Input inputMode="numeric" onChange={(e) => setSettings({ ...settings, late: e.target.value })} value={settings.late} /></FormField>
                        <FormField error={action.fieldError('early_grace_minutes')} field="early_grace_minutes" label={t('hr.att.earlyGrace')}><Input inputMode="numeric" onChange={(e) => setSettings({ ...settings, early: e.target.value })} value={settings.early} /></FormField>
                        <FormField error={action.fieldError('extra_after_minutes')} field="extra_after_minutes" hint={t('hr.att.extraHint')} label={t('hr.att.extraAfter')}><Input inputMode="numeric" onChange={(e) => setSettings({ ...settings, extra: e.target.value })} value={settings.extra} /></FormField>
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
