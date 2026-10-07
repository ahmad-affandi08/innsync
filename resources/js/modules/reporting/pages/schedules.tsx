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
import { TimeInput } from '@/components/ui/time-input';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Schedule = {
    id: string; name: string; report: string; params: Record<string, string>; cadence: 'daily' | 'weekly' | 'monthly'; weekday: number | null; month_day: number | null; at_time: string; notify_email: boolean; is_active: boolean;
    next_run_at: string | null; last_run_at: string | null; lock_version: number; created_by: string | null; recipients: { id: string; name: string | null }[];
};
type Overview = {
    schedules: Schedule[]; runs: Record<string, { ran_at: string; queued: number; skipped: number; note: string | null }[]>; reports: string[]; presets: string[]; cadences: string[]; time_zone: string; members: { id: string; name: string }[];
};
type Form = { id: string | null; lock: number; name: string; report: string; preset: string; by: string; kind: string; cadence: Schedule['cadence']; weekday: string; monthDay: string; atTime: string; notify: boolean; recipients: string[] };

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

/** Reports that are built by themselves at a set time for chosen people (FR-RPT-004). */
export default function SchedulesPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const reload = ['overview'];
    const reportName = (code: string) => t(`rpt.report.${code}` as MessageKey);

    function start(s: Schedule | null) {
        action.clear();
        setForm(s === null
            ? { id: null, lock: 0, name: '', report: overview.reports[0] ?? '', preset: 'yesterday', by: 'day', kind: 'day', cadence: 'daily', weekday: '1', monthDay: '1', atTime: '07:00', notify: false, recipients: [] }
            : { id: s.id, lock: s.lock_version, name: s.name, report: s.report, preset: s.params.preset ?? 'yesterday', by: s.params.by ?? 'day', kind: s.params.kind ?? 'day', cadence: s.cadence, weekday: String(s.weekday ?? 1), monthDay: String(s.month_day ?? 1), atTime: s.at_time, notify: s.notify_email, recipients: s.recipients.map((r) => r.id) });
    }

    function paramsOf(f: Form): Record<string, string> {
        if (f.report === 'comparison') return { kind: f.kind };
        if (f.report === 'performance') return f.by === 'day' ? { by: 'day', preset: f.preset } : { by: f.by };

        return { preset: f.preset };
    }

    async function save() {
        if (form === null) return;
        const body = {
            name: form.name.trim(), report: form.report, params: paramsOf(form), cadence: form.cadence, weekday: form.cadence === 'weekly' ? Number(form.weekday) : null, month_day: form.cadence === 'monthly' ? Number(form.monthDay) : null,
            at_time: form.atTime, notify_email: form.notify, recipients: form.recipients,
        };
        const done = form.id === null ? await action.run('/reports/schedules', { body, reload }) : await action.run(`/reports/schedules/${form.id}`, { body: { ...body, lock_version: form.lock }, reload });
        if (done !== null) setForm(null);
    }

    const when = (s: Schedule) => {
        const at = s.at_time;

        return s.cadence === 'daily' ? t('rpt.sch.whenDaily', { time: at }) : s.cadence === 'weekly' ? t('rpt.sch.whenWeekly', { day: t(`rpt.sch.day.${s.weekday ?? 1}` as MessageKey), time: at }) : t('rpt.sch.whenMonthly', { day: s.month_day ?? 1, time: at });
    };
    const period = (s: Schedule) => (s.report === 'comparison' ? t(`rpt.sch.kind.${s.params.kind ?? 'day'}` as MessageKey) : s.report === 'performance' && s.params.by !== 'day' ? t(`rpt.sch.kind.${s.params.by ?? 'month'}` as MessageKey) : t(`rpt.sch.preset.${s.params.preset ?? 'yesterday'}` as MessageKey));

    const columns: DataGridColumn<Schedule>[] = [
        { id: 'name', label: t('rpt.sch.colName'), value: (s) => s.name, rowHeader: true, cell: (s) => <span>{s.name}<span className="block text-xs text-muted-foreground">{reportName(s.report)} · {period(s)}</span></span> },
        { id: 'when', label: t('rpt.sch.colWhen'), value: (s) => s.cadence, cell: (s) => when(s) },
        { id: 'to', label: t('rpt.sch.colTo'), value: (s) => s.recipients.length, sortable: false, cell: (s) => <span>{s.recipients.map((r) => r.name ?? '—').join(', ')}{s.notify_email ? <span className="block text-xs text-muted-foreground">{t('rpt.sch.emailed')}</span> : null}</span> },
        { id: 'next', label: t('rpt.sch.colNext'), value: (s) => s.next_run_at ?? '', cell: (s) => (s.next_run_at === null ? '—' : format.instant(s.next_run_at)) },
        {
            id: 'last', label: t('rpt.sch.colLast'), value: (s) => s.last_run_at ?? '',
            cell: (s) => {
                const run = overview.runs[s.id]?.[0];

                return s.last_run_at === null ? '—' : <span>{format.instant(s.last_run_at)}{run !== undefined ? <span className="block text-xs text-muted-foreground">{t('rpt.sch.runResult', { queued: run.queued, skipped: run.skipped })}{run.note !== null ? ` · ${run.note}` : ''}</span> : null}</span>;
            },
        },
        { id: 'status', label: t('rpt.sch.colStatus'), value: (s) => (s.is_active ? 'on' : 'off'), cell: (s) => <StatusBadge label={s.is_active ? t('rpt.sch.on') : t('rpt.sch.off')} tone={s.is_active ? 'success' : 'neutral'} /> },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (s) => (
                <span className="flex gap-2">
                    <Button onClick={() => start(s)} size="sm" type="button" variant="outline">{t('hr.edit')}</Button>
                    <Button disabled={action.busy} onClick={() => void action.run(`/reports/schedules/${s.id}/active`, { body: { active: !s.is_active, lock_version: s.lock_version }, reload })} size="sm" type="button" variant="outline">{s.is_active ? t('rpt.sch.pause') : t('rpt.sch.resume')}</Button>
                </span>
            ),
        },
    ];

    return (
        <ReportingShell description={t('rpt.sch.description')} title={t('rpt.sch.title')} wide>
            {action.error !== null && form === null ? failure : null}
            <div className="flex flex-wrap items-center gap-3">
                <Button disabled={overview.reports.length === 0} onClick={() => start(null)} type="button">{t('rpt.sch.add')}</Button>
                <p className="text-sm text-muted-foreground">{t('rpt.sch.zone', { zone: overview.time_zone })}</p>
            </div>
            <DataGrid caption={t('rpt.sch.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('rpt.sch.none')} />} getRowId={(s) => s.id} id="reporting.schedules" rows={overview.schedules} testId="report-schedules" />
            <p className="text-xs text-muted-foreground">{t('rpt.sch.note')}</p>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form === null || form.name.trim() === '' || form.recipients.length === 0} loading={action.busy} onClick={() => void save()} type="button">{t('rpt.sch.save')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form?.id === null ? t('rpt.sch.add') : t('hr.edit')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {failure}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={action.fieldError('name')} field="name" label={t('rpt.sch.colName')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                            <FormField error={action.fieldError('report')} field="report" label={t('rpt.exports.report')}>
                                <Select onChange={(e) => setForm({ ...form, report: e.target.value })} value={form.report}>{overview.reports.map((r) => <option key={r} value={r}>{reportName(r)}</option>)}</Select>
                            </FormField>
                            {form.report === 'comparison' || (form.report === 'performance' && form.by !== 'day') ? null : (
                                <FormField error={action.fieldError('params')} field="params" label={t('rpt.sch.period')}>
                                    <Select onChange={(e) => setForm({ ...form, preset: e.target.value })} value={form.preset}>
                                        {(form.report === 'movements' ? ['today', 'yesterday'] : overview.presets).map((p) => <option key={p} value={p}>{t(`rpt.sch.preset.${p}` as MessageKey)}</option>)}
                                    </Select>
                                </FormField>
                            )}
                            {form.report === 'comparison' ? (
                                <FormField label={t('rpt.sch.period')}><Select onChange={(e) => setForm({ ...form, kind: e.target.value })} value={form.kind}>{['day', 'month', 'year'].map((k) => <option key={k} value={k}>{t(`rpt.sch.kind.${k}` as MessageKey)}</option>)}</Select></FormField>
                            ) : null}
                            {form.report === 'performance' ? (
                                <FormField label={t('rpt.sch.by')}><Select onChange={(e) => setForm({ ...form, by: e.target.value })} value={form.by}>{['day', 'month', 'year'].map((k) => <option key={k} value={k}>{t(`rpt.sch.kind.${k}` as MessageKey)}</option>)}</Select></FormField>
                            ) : null}
                            <FormField error={action.fieldError('cadence')} field="cadence" label={t('rpt.sch.cadence')}>
                                <Select onChange={(e) => setForm({ ...form, cadence: e.target.value as Form['cadence'] })} value={form.cadence}>{overview.cadences.map((c) => <option key={c} value={c}>{t(`rpt.sch.cadence.${c}` as MessageKey)}</option>)}</Select>
                            </FormField>
                            {form.cadence === 'weekly' ? (
                                <FormField error={action.fieldError('weekday')} field="weekday" label={t('rpt.sch.weekday')}>
                                    <Select onChange={(e) => setForm({ ...form, weekday: e.target.value })} value={form.weekday}>{WEEKDAYS.map((d) => <option key={d} value={d}>{t(`rpt.sch.day.${d}` as MessageKey)}</option>)}</Select>
                                </FormField>
                            ) : null}
                            {form.cadence === 'monthly' ? <FormField error={action.fieldError('month_day')} field="month_day" hint={t('rpt.sch.monthDayHint')} label={t('rpt.sch.monthDay')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, monthDay: e.target.value.replace(/\D/g, '') })} value={form.monthDay} /></FormField> : null}
                            <FormField error={action.fieldError('at_time')} field="at_time" hint={t('rpt.sch.zone', { zone: overview.time_zone })} label={t('rpt.sch.atTime')}><TimeInput onChange={(e) => setForm({ ...form, atTime: e.target.value })} value={form.atTime} /></FormField>
                        </div>
                        <fieldset className="flex flex-col gap-2">
                            <legend className="text-sm font-medium">{t('rpt.sch.colTo')}</legend>
                            <p className="text-xs text-muted-foreground">{t('rpt.sch.recipientsHint')}</p>
                            <div className="grid gap-1 sm:grid-cols-2" data-testid="schedule-recipients">
                                {overview.members.map((m) => (
                                    <label className="flex items-center gap-2 text-sm" key={m.id}>
                                        <input checked={form.recipients.includes(m.id)} onChange={(e) => setForm({ ...form, recipients: e.target.checked ? [...form.recipients, m.id] : form.recipients.filter((r) => r !== m.id) })} type="checkbox" />
                                        {m.name}
                                    </label>
                                ))}
                            </div>
                            {action.fieldError('recipients') !== undefined ? <Alert title={String(action.fieldError('recipients'))} tone="warning" /> : null}
                        </fieldset>
                        <label className="flex items-center gap-2 text-sm"><input checked={form.notify} onChange={(e) => setForm({ ...form, notify: e.target.checked })} type="checkbox" />{t('rpt.sch.notify')}</label>
                    </div>
                )}
            </Dialog>
        </ReportingShell>
    );
}
