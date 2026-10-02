import { useEffect, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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
        if (done !== null) setForm({ ...form, purpose: '' });
    }

    return (
        <ReportingShell description={t('rpt.exports.description')} title={t('rpt.exports.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <Alert title={t('rpt.exports.note')} tone="info" />

            {overview.jobs.length === 0 ? <EmptyState title={t('rpt.exports.empty')} /> : (
                <table className="w-full text-left text-sm" data-testid="jobs">
                    <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('rpt.exports.report')}</th><th scope="col">{t('rpt.exports.requested')}</th><th scope="col">{t('rpt.exports.status')}</th><th scope="col">{t('rpt.exports.lines')}</th><th scope="col" /></tr></thead>
                    <tbody>{overview.jobs.map((j) => (
                        <tr className="border-t border-border align-top" key={j.id}>
                            <th className="py-2 font-medium" scope="row">{t(`rpt.report.${j.report}` as 'rpt.report.flash')}<span className="block text-xs font-normal text-muted-foreground">{Object.entries(j.params).map(([k, v]) => `${k}: ${v}`).join(' · ')}</span></th>
                            <td>{format.instant(j.requested_at)}</td>
                            <td><StatusBadge label={t(`rpt.exports.status.${j.status}` as 'rpt.exports.status.queued')} tone={TONE[j.status]} />{j.error !== null ? <span className="block text-xs text-danger">{j.error}</span> : null}</td>
                            <td>{j.rows ?? '—'}</td>
                            <td>{j.status === 'done' ? <Button asChild size="sm" variant="outline"><a href={`/reports/exports/${j.id}/download`}>{t('rpt.exports.download')}</a></Button> : null}</td>
                        </tr>
                    ))}</tbody>
                </table>
            )}

            {overview.reports.length > 0 && (
                <section aria-labelledby="exp-new-h" className="flex max-w-3xl flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="exp-new-h">{t('rpt.exports.new')}</h2>
                    <form className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
                        <FormField error={action.fieldError('report')} label={t('rpt.exports.report')}><Select onChange={(e) => setForm({ ...form, report: e.target.value })} value={form.report}>{overview.reports.map((r) => <option key={r} value={r}>{t(`rpt.report.${r}` as 'rpt.report.flash')}</option>)}</Select></FormField>
                        {form.report === 'movements' ? <FormField label={t('rpt.exports.date')}><Input onChange={(e) => setForm({ ...form, date: e.target.value })} type="date" value={form.date} /></FormField> : null}
                        {form.report !== 'movements' && form.report !== 'comparison' ? (
                            <>
                                <FormField label={t('rpt.period.label')}><Select onChange={(e) => setForm({ ...form, preset: e.target.value })} value={form.preset}>{PRESETS.map((p) => <option key={p} value={p}>{t(`rpt.period.${p}` as 'rpt.period.today')}</option>)}</Select></FormField>
                                <FormField label={t('rpt.period.from')}><Input onChange={(e) => setForm({ ...form, from: e.target.value })} type="date" value={form.from} /></FormField>
                                <FormField label={t('rpt.period.to')}><Input onChange={(e) => setForm({ ...form, to: e.target.value })} type="date" value={form.to} /></FormField>
                            </>
                        ) : null}
                        {personal ? <div className="sm:col-span-2"><FormField error={action.fieldError('purpose')} hint={t('rpt.exports.purposeHint')} label={t('rpt.exports.purpose')}><Input maxLength={300} onChange={(e) => setForm({ ...form, purpose: e.target.value })} required value={form.purpose} /></FormField></div> : null}
                        <div className="sm:col-span-2"><Button loading={action.busy} type="submit">{t('rpt.exports.ask')}</Button></div>
                    </form>
                </section>
            )}
        </ReportingShell>
    );
}
