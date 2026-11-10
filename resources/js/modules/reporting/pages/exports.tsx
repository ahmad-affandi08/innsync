import { useEffect, useState } from 'react';

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
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Job = { id: string; report: string; params: Record<string, string | number>; status: 'queued' | 'running' | 'done' | 'failed'; rows: number | null; filename: string | null; error: string | null; requested_at: string; finished_at: string | null; seen: boolean };
type Overview = { jobs: Job[]; reports: string[]; unseen: number };

const TONE: Record<string, StatusTone> = { queued: 'pending', running: 'info', done: 'success', failed: 'danger' };
const PERSONAL = ['movements', 'registrations', 'foreign_guests'];
const PRESETS = ['today', 'yesterday', 'last7', 'month'] as const;

/** Report exports built in the background, with their status (FR-RPT-011). */
export default function ExportsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ report: overview.reports[0] ?? '', preset: 'today', from: '', to: '', date: '', purpose: '' });
    const [adding, setAdding] = useState(false);
    const waiting = overview.jobs.some((j) => j.status === 'queued' || j.status === 'running');
    const personal = PERSONAL.includes(form.report);

    // The page looks again while something is waiting, and tells the server what the person has now seen.
    useEffect(() => {
        if (!waiting) return;
        const timer = window.setInterval(() => { void action.run('/reports/exports', { method: 'GET', reload: ['overview'] }).catch(() => undefined); }, 5000);
        return () => window.clearInterval(timer);
    }, [waiting]);
    useEffect(() => {
        if (overview.unseen > 0) void action.run('/reports/exports/seen', { body: {} });
    }, []);

    async function submit() {
        const params: Record<string, string> = {};
        if (form.report === 'movements') { if (form.date !== '') params.date = form.date; }
        else if (form.report !== 'comparison') { if (form.from !== '' && form.to !== '') { params.from = form.from; params.to = form.to; } else params.preset = form.preset; }
        const done = await action.run('/reports/exports', { body: { report: form.report, params, purpose: form.purpose.trim() || null }, reload: ['overview'] });
        if (done === null) return;
        setForm({ ...form, purpose: '' });
        setAdding(false);
    }

    const reportName = (j: Job) => t(`rpt.report.${j.report}` as 'rpt.report.flash');
    const columns: DataGridColumn<Job>[] = [
        {
            id: 'report', label: t('rpt.exports.report'), value: reportName, searchText: (j) => `${reportName(j)} ${Object.entries(j.params).map(([k, v]) => `${k}: ${v}`).join(' ')}`, filter: 'select', rowHeader: true,
            cell: (j) => <>{reportName(j)}<span className="block text-xs font-normal text-muted-foreground">{Object.entries(j.params).map(([k, v]) => `${k}: ${v}`).join(' · ')}</span></>,
        },
        { id: 'requested', label: t('rpt.exports.requested'), value: (j) => j.requested_at, cell: (j) => format.instant(j.requested_at) },
        {
            id: 'status', label: t('rpt.exports.status'), value: (j) => j.status, filter: 'select', filterLabel: (v) => t(`rpt.exports.status.${v}` as 'rpt.exports.status.queued'),
            cell: (j) => <><StatusBadge label={t(`rpt.exports.status.${j.status}` as 'rpt.exports.status.queued')} tone={TONE[j.status]} />{j.error !== null ? <span className="block text-xs text-danger">{j.error}</span> : null}</>,
        },
        { id: 'lines', label: t('rpt.exports.lines'), align: 'right', value: (j) => j.rows, cell: (j) => j.rows ?? '—' },
        { id: 'download', label: t('rpt.exports.download'), cell: (j) => (j.status === 'done' ? <span className="flex gap-2"><Button asChild size="sm" variant="outline"><a href={`/reports/exports/${j.id}/download`}>{t('rpt.exports.download')}</a></Button><Button asChild size="sm" variant="outline"><a href={`/reports/exports/${j.id}/download?format=pdf`}>{t('rpt.export.pdf')}</a></Button></span> : null) },
    ];

    return (
        <ReportingShell description={t('rpt.exports.description')} title={t('rpt.exports.title')} wide>
            {action.error !== null && !adding ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <div className="flex flex-wrap items-start justify-between gap-2">
                <Alert title={t('rpt.exports.note')} tone="info" />
                {overview.reports.length > 0 ? <Button onClick={() => { action.clear(); setAdding(true); }} size="sm" type="button">{t('rpt.exports.new')}</Button> : null}
            </div>

            <DataGrid
                caption={t('rpt.exports.title')}
                columns={columns}
                empty={<EmptyState title={t('rpt.exports.empty')} />}
                getRowId={(j) => j.id}
                id="rpt.exports"
                rows={overview.jobs}
                testId="jobs"
            />

            {overview.reports.length > 0 && (
                <Dialog className="w-[min(42rem,calc(100vw-2rem))]" footer={<><Button disabled={action.busy} onClick={() => setAdding(false)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button form="export-new-form" loading={action.busy} type="submit">{t('rpt.exports.ask')}</Button></>} onClose={() => setAdding(false)} open={adding} title={t('rpt.exports.new')}>
                        <form className="grid gap-3 sm:grid-cols-2" id="export-new-form" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                            <FormField field="report" error={action.fieldError('report')} label={t('rpt.exports.report')}><Select onChange={(e) => setForm({ ...form, report: e.target.value })} value={form.report}>{overview.reports.map((r) => <option key={r} value={r}>{t(`rpt.report.${r}` as 'rpt.report.flash')}</option>)}</Select></FormField>
                            {form.report === 'movements' ? <FormField label={t('rpt.exports.date')}><DatePicker onChange={(e) => setForm({ ...form, date: e.target.value })} value={form.date} /></FormField> : null}
                            {form.report !== 'movements' && form.report !== 'comparison' ? (
                                <>
                                    <FormField label={t('rpt.period.label')}><Select onChange={(e) => setForm({ ...form, preset: e.target.value })} value={form.preset}>{PRESETS.map((p) => <option key={p} value={p}>{t(`rpt.period.${p}` as 'rpt.period.today')}</option>)}</Select></FormField>
                                    <FormField label={t('rpt.period.from')}><DatePicker onChange={(e) => setForm({ ...form, from: e.target.value })} value={form.from} /></FormField>
                                    <FormField label={t('rpt.period.to')}><DatePicker onChange={(e) => setForm({ ...form, to: e.target.value })} value={form.to} /></FormField>
                                </>
                            ) : null}
                            {personal ? <div className="sm:col-span-2"><FormField field="purpose" error={action.fieldError('purpose')} hint={t('rpt.exports.purposeHint')} label={t('rpt.exports.purpose')}><Input maxLength={300} onChange={(e) => setForm({ ...form, purpose: e.target.value })} required value={form.purpose} /></FormField></div> : null}
                        </form>
                </Dialog>
            )}
        </ReportingShell>
    );
}
