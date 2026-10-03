import { useState } from 'react';

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
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { Appraisal, AppraisalForm, AppraisalOverview, AppraisalStatus } from '@/modules/hr/lib/hr';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<AppraisalStatus, StatusTone> = { draft: 'neutral', signed_appraiser: 'pending', completed: 'success', cancelled: 'neutral' };

/** Appraisals: the form, the ratings of the supervisor, and the two signatures. */
export default function AppraisalsPage({ overview }: { overview: AppraisalOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [create, setCreate] = useState<{ employeeId: string; formId: string; label: string; start: string; end: string } | null>(null);
    const [rate, setRate] = useState<{ a: Appraisal; scores: Record<string, string>; comment: string } | null>(null);
    const [view, setView] = useState<Appraisal | null>(null);
    const [sign, setSign] = useState<{ a: Appraisal; agrees: boolean; comment: string } | null>(null);
    const [cancel, setCancel] = useState<{ a: Appraisal; reason: string } | null>(null);
    const [form, setForm] = useState<{ name: string; rows: { label: string; weight: string }[] } | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const reload = ['overview'];
    const score = (x100: number | null) => (x100 === null ? '—' : (x100 / 100).toFixed(2));
    const noDialog = create === null && rate === null && sign === null && cancel === null && form === null;

    async function doCreate() {
        if (create === null) return;
        const result = await action.run('/hr/appraisals', { idempotencyKey: newIdempotencyKey(), body: { employee_id: create.employeeId, form_id: create.formId, period_label: create.label.trim(), period_start: create.start, period_end: create.end }, reload });

        if (result !== null) setCreate(null);
    }

    async function doSave(thenSign: boolean) {
        if (rate === null) return;
        const body = { scores: Object.fromEntries(Object.entries(rate.scores).filter(([, v]) => v !== '').map(([k, v]) => [k, Number(v)])), comment: rate.comment.trim() === '' ? null : rate.comment.trim(), lock_version: rate.a.lock_version };
        const saved = await action.run<unknown>(`/hr/appraisals/${rate.a.id}`, { body, reload });

        if (saved === null || !thenSign) {
            if (saved !== null) setRate(null);

            return;
        }

        const fresh = await action.run(`/hr/appraisals/${rate.a.id}/sign`, { body: { lock_version: rate.a.lock_version + 1 }, reload });

        if (fresh !== null) setRate(null);
    }

    async function doSignEmployee() {
        if (sign === null) return;
        const result = await action.run(`/hr/appraisals/${sign.a.id}/sign-employee`, { body: { agrees: sign.agrees, comment: sign.comment.trim() === '' ? null : sign.comment.trim(), lock_version: sign.a.lock_version }, reload });

        if (result !== null) setSign(null);
    }

    async function doCancel() {
        if (cancel === null) return;
        const result = await action.run(`/hr/appraisals/${cancel.a.id}/cancel`, { body: { reason: cancel.reason.trim(), lock_version: cancel.a.lock_version }, reload });

        if (result !== null) setCancel(null);
    }

    async function doForm() {
        if (form === null) return;
        const result = await action.run('/hr/appraisals/forms', { body: { name: form.name.trim(), criteria: form.rows.map((r) => ({ label: r.label.trim(), weight: Number(r.weight) })) }, reload });

        if (result !== null) setForm(null);
    }

    const columns = (withName: boolean): DataGridColumn<Appraisal>[] => [
        { id: 'number', label: t('hr.appr.number'), value: (a) => a.number, rowHeader: true, cell: (a) => <span>{a.number}<span className="block text-xs text-muted-foreground">{a.period_label}</span></span> },
        ...(withName ? [{ id: 'name', label: t('hr.col.name'), value: (a: Appraisal) => a.employee.name, cell: (a: Appraisal) => <span>{a.employee.name}<span className="block text-xs text-muted-foreground">{a.employee.number} · {a.employee.position}</span></span> }] : []),
        { id: 'status', label: t('hr.col.status'), value: (a) => a.status, filter: 'select', filterLabel: (v) => label('hr.appr.status', v), cell: (a) => <StatusBadge label={label('hr.appr.status', a.status)} tone={TONE[a.status]} /> },
        { id: 'overall', label: t('hr.appr.overall'), align: 'right', value: (a) => a.overall_x100 ?? -1, cell: (a) => (a.rating === null ? '—' : <span>{score(a.overall_x100)}<span className="block text-xs text-muted-foreground">{label('hr.appr.rating', a.rating)}</span></span>) },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (a) => (
                <span className="flex flex-wrap gap-2">
                    <Button onClick={() => setView(a)} size="sm" type="button" variant="outline">{t('hr.run.details')}</Button>
                    {a.may.edit ? <Button onClick={() => { action.clear(); setRate({ a, scores: Object.fromEntries(a.criteria.map((c) => [c.key, a.scores[c.key] === undefined ? '' : String(a.scores[c.key])])), comment: a.comment ?? '' }); }} size="sm" type="button">{t('hr.appr.rate')}</Button> : null}
                    {a.may.sign_employee ? <Button onClick={() => { action.clear(); setSign({ a, agrees: true, comment: '' }); }} size="sm" type="button">{t('hr.appr.signYours')}</Button> : null}
                    {a.may.cancel ? <Button onClick={() => { action.clear(); setCancel({ a, reason: '' }); }} size="sm" type="button" variant="outline">{t('hr.att.cancelRequest')}</Button> : null}
                </span>
            ),
        },
    ];
    const formColumns: DataGridColumn<AppraisalForm>[] = [
        { id: 'name', label: t('hr.shift.name'), value: (f) => f.name, rowHeader: true },
        { id: 'criteria', label: t('hr.appr.criteria'), value: (f) => f.criteria.length, sortable: false, cell: (f) => f.criteria.map((c) => `${c.label} ${c.weight}%`).join(' · ') },
        { id: 'status', label: t('hr.col.status'), value: (f) => (f.active ? 'active' : 'retired'), cell: (f) => <StatusBadge label={f.active ? t('hr.shift.active') : t('hr.shift.retired')} tone={f.active ? 'success' : 'neutral'} /> },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (f) => <Button disabled={action.busy} onClick={() => void action.run(`/hr/appraisals/forms/${f.id}/active`, { body: { active: !f.active, lock_version: f.lock_version }, reload })} size="sm" type="button" variant="outline">{f.active ? t('hr.shift.retire') : t('hr.shift.resume')}</Button> },
    ];
    const weightSum = form === null ? 0 : form.rows.reduce((n, r) => n + (Number(r.weight) || 0), 0);

    return (
        <HrShell actions={overview.may.appraise ? <Button disabled={overview.forms.filter((f) => f.active).length === 0 || overview.employees.length === 0} onClick={() => { action.clear(); setCreate({ employeeId: overview.employees[0]?.id ?? '', formId: overview.forms.find((f) => f.active)?.id ?? '', label: '', start: '', end: '' }); }} type="button">{t('hr.appr.new')}</Button> : undefined} description={t('hr.appr.description')} title={t('hr.appr.title')}>
            {action.error !== null && noDialog ? failure : null}
            <Tabs defaultValue={overview.may.appraise ? 'team' : 'mine'}>
                <TabsList aria-label={t('hr.appr.title')}>
                    <TabsTrigger value="mine">{t('hr.appr.mineTab')}</TabsTrigger>
                    {overview.may.appraise ? <TabsTrigger value="team">{t('hr.appr.teamTab')}</TabsTrigger> : null}
                    {overview.may.manage ? <TabsTrigger value="forms">{t('hr.appr.formsTab')}</TabsTrigger> : null}
                </TabsList>
                <TabsContent value="mine"><DataGrid caption={t('hr.appr.mineTab')} columns={columns(false)} empty={<EmptyState illustration="checklist" title={t('hr.appr.noneMine')} />} getRowId={(a) => a.id} id="hr.appr.mine" rows={overview.mine} testId="hr-appr-mine" /></TabsContent>
                {overview.may.appraise ? <TabsContent value="team"><DataGrid caption={t('hr.appr.teamTab')} columns={columns(true)} empty={<EmptyState illustration="checklist" title={t('hr.appr.noneTeam')} />} getRowId={(a) => a.id} id="hr.appr.team" rows={overview.team} testId="hr-appr-team" /></TabsContent> : null}
                {overview.may.manage ? (
                    <TabsContent className="flex flex-col gap-3" value="forms">
                        <div className="flex gap-2">
                            <Button onClick={() => { action.clear(); setForm({ name: '', rows: [{ label: '', weight: '100' }] }); }} type="button">{t('hr.appr.addForm')}</Button>
                            {overview.forms.length === 0 ? <Button disabled={action.busy} onClick={() => void action.run('/hr/appraisals/forms/baseline', { body: {}, reload })} type="button" variant="outline">{t('hr.appr.baseline')}</Button> : null}
                        </div>
                        <p className="text-xs text-muted-foreground">{t('hr.appr.formsHint')}</p>
                        <DataGrid caption={t('hr.appr.formsTab')} columns={formColumns} empty={<EmptyState illustration="checklist" title={t('hr.appr.noForms')} />} getRowId={(f) => f.id} id="hr.appr.forms" rows={overview.forms} testId="hr-appr-forms" />
                    </TabsContent>
                ) : null}
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setCreate(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={create === null || create.employeeId === '' || create.formId === '' || create.label.trim() === '' || create.start === '' || create.end === ''} loading={action.busy} onClick={() => void doCreate()} type="button">{t('hr.appr.create')}</Button></>}
                onClose={() => setCreate(null)}
                open={create !== null}
                title={t('hr.appr.new')}
            >
                {create !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('employee_id')} field="employee_id" label={t('hr.col.name')}><Select onChange={(e) => setCreate({ ...create, employeeId: e.target.value })} value={create.employeeId}>{overview.employees.map((e) => <option key={e.id} value={e.id}>{e.name} · {e.number}</option>)}</Select></FormField></div>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('form_id')} field="form_id" label={t('hr.appr.form')}><Select onChange={(e) => setCreate({ ...create, formId: e.target.value })} value={create.formId}>{overview.forms.filter((f) => f.active).map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}</Select></FormField></div>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('period_label')} field="period_label" hint={t('hr.appr.labelHint')} label={t('hr.appr.periodLabel')}><Input maxLength={30} onChange={(e) => setCreate({ ...create, label: e.target.value })} value={create.label} /></FormField></div>
                        <FormField error={action.fieldError('period_start')} field="period_start" label={t('mtc.rep.from')}><DatePicker max={overview.today} onChange={(e) => setCreate({ ...create, start: e.target.value })} value={create.start} /></FormField>
                        <FormField error={action.fieldError('period_end')} field="period_end" label={t('mtc.rep.to')}><DatePicker max={overview.today} onChange={(e) => setCreate({ ...create, end: e.target.value })} value={create.end} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setRate(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={action.busy} onClick={() => void doSave(false)} type="button" variant="outline">{t('hr.appr.saveDraft')}</Button><Button loading={action.busy} onClick={() => void doSave(true)} type="button">{t('hr.appr.saveAndSign')}</Button></>}
                onClose={() => setRate(null)}
                open={rate !== null}
                title={rate === null ? '' : t('hr.appr.rateTitle', { name: rate.a.employee.name })}
            >
                {rate !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <p className="text-sm text-muted-foreground">{t('hr.appr.rateHint')}</p>
                        {rate.a.criteria.map((c) => (
                            <FormField error={action.fieldError('scores')} field={`score_${c.key}`} key={c.key} label={`${c.label} (${c.weight}%)`}>
                                <Select onChange={(e) => setRate({ ...rate, scores: { ...rate.scores, [c.key]: e.target.value } })} value={rate.scores[c.key] ?? ''}><option value="">—</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n} · {label('hr.appr.scale', String(n))}</option>)}</Select>
                            </FormField>
                        ))}
                        <FormField error={action.fieldError('comment')} field="comment" label={t('hr.att.reasonShort')}><textarea className="min-h-24 w-full border border-border bg-surface p-2 text-sm" maxLength={1000} onChange={(e) => setRate({ ...rate, comment: e.target.value })} value={rate.comment} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog footer={<Button onClick={() => setView(null)} type="button">{t('hr.run.close')}</Button>} onClose={() => setView(null)} open={view !== null} title={view === null ? '' : t('hr.appr.viewTitle', { name: view.employee.name, period: view.period_label })}>
                {view !== null && (
                    <div className="flex flex-col gap-3 text-sm" data-testid="hr-appr-view">
                        <p className="text-muted-foreground">{view.form_name} · {format.date(view.period_start)} → {format.date(view.period_end)}</p>
                        <ul>{view.criteria.map((c) => <li className="flex justify-between gap-4" key={c.key}><span>{c.label} ({c.weight}%)</span><span>{view.scores[c.key] ?? '—'}</span></li>)}</ul>
                        {view.rating !== null ? <p className="font-semibold">{t('hr.appr.overall')}: {score(view.overall_x100)} · {label('hr.appr.rating', view.rating)}</p> : null}
                        {view.comment !== null ? <p className="whitespace-pre-line">{view.comment}</p> : null}
                        {view.metrics !== null ? <p className="text-xs text-muted-foreground">{t('hr.appr.metrics', { attendance: view.metrics.attendance ?? '—', punctuality: view.metrics.punctuality ?? '—', sop: view.metrics.sop_items, complaints: view.metrics.complaints.total })}</p> : null}
                        {view.appraiser_signed_at !== null ? <p className="text-xs text-muted-foreground">{t('hr.appr.signedBy', { time: format.instant(view.appraiser_signed_at), hash: (view.appraiser_hash ?? '').slice(0, 12) })}</p> : null}
                        {view.employee_signed_at !== null ? <p className="text-xs text-muted-foreground">{t(view.employee_agrees === true ? 'hr.appr.employeeAgreed' : 'hr.appr.employeeDisagreed', { time: format.instant(view.employee_signed_at), hash: (view.employee_hash ?? '').slice(0, 12) })}{view.employee_comment !== null ? ` · ${view.employee_comment}` : ''}</p> : null}
                        {view.cancel_reason !== null ? <p className="text-xs text-muted-foreground">{t('hr.conduct.revokedBecause', { reason: view.cancel_reason })}</p> : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setSign(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={sign === null || (!sign.agrees && sign.comment.trim() === '')} loading={action.busy} onClick={() => void doSignEmployee()} type="button">{t('hr.appr.signYours')}</Button></>}
                onClose={() => setSign(null)}
                open={sign !== null}
                title={t('hr.appr.signYours')}
            >
                {sign !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <p className="text-sm text-muted-foreground">{t('hr.appr.signHint', { overall: score(sign.a.overall_x100), rating: sign.a.rating === null ? '' : label('hr.appr.rating', sign.a.rating) })}</p>
                        <label className="flex items-center gap-2 text-sm"><input checked={sign.agrees} onChange={(e) => setSign({ ...sign, agrees: e.target.checked })} type="checkbox" />{t('hr.appr.agree')}</label>
                        <FormField error={action.fieldError('comment')} field="comment" hint={sign.agrees ? undefined : t('hr.appr.disagreeHint')} label={t('hr.appr.yourComment')}><Input maxLength={500} onChange={(e) => setSign({ ...sign, comment: e.target.value })} value={sign.comment} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setCancel(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={cancel?.reason.trim() === ''} loading={action.busy} onClick={() => void doCancel()} type="button">{t('hr.att.cancelRequest')}</Button></>}
                onClose={() => setCancel(null)}
                open={cancel !== null}
                title={t('hr.att.cancelRequest')}
            >
                {cancel !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setCancel({ ...cancel, reason: e.target.value })} value={cancel.reason} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form === null || form.name.trim() === '' || weightSum !== 100} loading={action.busy} onClick={() => void doForm()} type="button">{t('hr.appr.saveForm')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('hr.appr.addForm')}
            >
                {form !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <FormField error={action.fieldError('name')} field="name" label={t('hr.shift.name')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                        {form.rows.map((r, i) => (
                            <div className="grid grid-cols-[1fr_6rem_auto] items-end gap-2" key={i}>
                                <FormField label={t('hr.appr.criterion')}><Input maxLength={80} onChange={(e) => setForm({ ...form, rows: form.rows.map((x, n) => (n === i ? { ...x, label: e.target.value } : x)) })} value={r.label} /></FormField>
                                <FormField label={t('hr.appr.weight')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, rows: form.rows.map((x, n) => (n === i ? { ...x, weight: e.target.value.replace(/\D/g, '') } : x)) })} value={r.weight} /></FormField>
                                <Button disabled={form.rows.length <= 1} onClick={() => setForm({ ...form, rows: form.rows.filter((_, n) => n !== i) })} size="sm" type="button" variant="outline">{t('hr.pay.removeRow')}</Button>
                            </div>
                        ))}
                        <div className="flex items-center gap-3"><Button disabled={form.rows.length >= 12} onClick={() => setForm({ ...form, rows: [...form.rows, { label: '', weight: '0' }] })} size="sm" type="button" variant="outline">{t('hr.appr.addCriterion')}</Button><span className={weightSum === 100 ? 'text-sm' : 'text-sm text-danger'}>{t('hr.appr.weightSum', { n: weightSum })}</span></div>
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
