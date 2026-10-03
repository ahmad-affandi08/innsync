import { Link, router } from '@inertiajs/react';
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
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { PayrollAdjustment, PayrollLine, PayrollRun, PayrollRunOverview, PayrollRunStatus } from '@/modules/hr/lib/hr';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { parseMajorToMinor } from '@/shared/money/money';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<PayrollRunStatus, StatusTone> = { draft: 'neutral', calculated: 'info', reviewed: 'info', approved: 'success', paid: 'success', locked: 'neutral' };
const ADJUSTMENT_TONE: Record<PayrollAdjustment['status'], StatusTone> = { open: 'pending', applied: 'success', cancelled: 'neutral' };
const BLANK_ADJUSTMENT = { employeeId: '', amount: '', label: '', taxable: true, reason: '', sourceRunId: '' };

/** The payroll of a month: the steps of a run from draft to approved (paid and locked follow in Finance), the pay of each person, and the adjustments for later periods. */
export default function PayrollRunsPage({ overview }: { overview: PayrollRunOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [create, setCreate] = useState<string | null>(null);
    const [reopen, setReopen] = useState<string | null>(null);
    const [detail, setDetail] = useState<PayrollLine | null>(null);
    const [adjust, setAdjust] = useState<typeof BLANK_ADJUSTMENT | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const money = (minor: number) => format.money(minor, overview.currency);
    const run = overview.run;
    const reload = ['overview'];

    async function createRun() {
        if (create === null) return;
        const result = await action.run('/hr/payroll/runs', { body: { period: create }, reload });

        if (result !== null) setCreate(null);
    }

    // A minus sign in front means the adjustment takes from the pay; the amount itself is read like any amount of money.
    const adjustAmount = (text: string) => {
        const minor = parseMajorToMinor(text.trim().replace(/^-/, ''), overview.currency);

        return minor === null ? 0 : text.trim().startsWith('-') ? -minor : minor;
    };

    async function saveAdjustment() {
        if (adjust === null) return;
        const result = await action.run('/hr/payroll/adjustments', { body: { employee_id: adjust.employeeId, amount_minor: adjustAmount(adjust.amount), label: adjust.label.trim(), taxable: adjust.taxable, reason: adjust.reason.trim(), ...(adjust.sourceRunId === '' ? {} : { source_run_id: adjust.sourceRunId }) }, reload });

        if (result !== null) setAdjust(null);
    }

    async function doReopen() {
        if (run === null || reopen === null) return;
        const result = await action.run(`/hr/payroll/runs/${run.id}/reopen`, { body: { reason: reopen.trim(), lock_version: run.lock_version }, reload });

        if (result !== null) setReopen(null);
    }

    const approveText = (r: PayrollRun) => (r.approval === null ? t('hr.run.askApproval') : r.approval.status === 'approved' ? t('hr.run.takeApproval') : r.approval.status === 'pending' ? t('hr.run.checkApproval') : t('hr.run.takeRefusal'));
    const lineColumns: DataGridColumn<PayrollLine>[] = [
        { id: 'name', label: t('hr.col.name'), value: (l) => l.employee.name, rowHeader: true, cell: (l) => <span>{l.employee.name}<span className="block text-xs text-muted-foreground">{l.employee.number} · {label('hr.department', l.employee.department)}</span></span> },
        { id: 'days', label: t('hr.run.days'), align: 'right', value: (l) => l.present_days, cell: (l) => t('hr.perf.presentOf', { present: l.present_days, scheduled: l.scheduled_days }) },
        { id: 'gross', label: t('hr.run.gross'), align: 'right', value: (l) => l.gross_minor, cell: (l) => money(l.gross_minor) },
        { id: 'social', label: t('hr.run.social'), align: 'right', value: (l) => l.employee_social_minor, cell: (l) => money(l.employee_social_minor) },
        { id: 'tax', label: t('hr.run.tax'), align: 'right', value: (l) => l.tax_minor, cell: (l) => money(l.tax_minor) },
        { id: 'other', label: t('hr.run.otherDeductions'), align: 'right', value: (l) => l.other_deductions_minor, cell: (l) => money(l.other_deductions_minor) },
        { id: 'net', label: t('hr.run.net'), align: 'right', value: (l) => l.net_minor, cell: (l) => <strong>{money(l.net_minor)}</strong> },
        { id: 'warn', label: t('hr.run.warnings'), value: (l) => l.warnings.length, sortable: false, cell: (l) => (l.warnings.length === 0 ? '—' : <span className="text-xs text-warning">{l.warnings.map((w) => label('hr.run.warning', w)).join(' · ')}</span>) },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (l) => <span className="flex gap-2"><Button onClick={() => setDetail(l)} size="sm" type="button" variant="outline">{t('hr.run.details')}</Button>{run !== null ? <Button asChild size="sm" variant="outline"><Link href={`/hr/payroll/runs/${run.id}/payslips/${l.employee.id}`}>{t('hr.run.payslip')}</Link></Button> : null}</span> },
    ];
    const adjustmentColumns: DataGridColumn<PayrollAdjustment>[] = [
        { id: 'name', label: t('hr.col.name'), value: (a) => a.employee.name, rowHeader: true, cell: (a) => <span>{a.employee.name}<span className="block text-xs text-muted-foreground">{a.employee.number}</span></span> },
        { id: 'label', label: t('hr.run.adjustLabel'), value: (a) => a.label, cell: (a) => <span>{a.label}<span className="block text-xs text-muted-foreground">{a.reason}</span></span> },
        { id: 'amount', label: t('hr.pay.amount'), align: 'right', value: (a) => a.amount_minor, cell: (a) => money(a.amount_minor) },
        { id: 'source', label: t('hr.run.corrects'), value: (a) => a.source_period ?? '', cell: (a) => a.source_period ?? '—' },
        { id: 'status', label: t('hr.col.status'), value: (a) => a.status, cell: (a) => <StatusBadge label={label('hr.run.adjustStatus', a.status)} tone={ADJUSTMENT_TONE[a.status]} /> },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (a) => (a.status === 'open' ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/payroll/adjustments/${a.id}/cancel`, { body: { lock_version: a.lock_version }, reload })} size="sm" type="button" variant="outline">{t('hr.att.cancelRequest')}</Button> : null) },
    ];

    const step = (name: 'calculate' | 'review' | 'approve') => (run === null ? Promise.resolve(null) : action.run(`/hr/payroll/runs/${run.id}/${name}`, { body: { lock_version: run.lock_version }, reload }));

    return (
        <HrShell
            actions={<Button onClick={() => { action.clear(); setCreate(overview.period); }} type="button">{t('hr.run.new')}</Button>}
            description={t('hr.run.description')}
            title={t('hr.run.title')}
        >
            {action.error !== null && create === null && reopen === null && adjust === null ? failure : null}
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <FormField label={t('hr.run.period')}>
                    <Select onChange={(e) => router.get('/hr/payroll/runs', { run: e.target.value })} value={run?.id ?? ''}>
                        {overview.runs.length === 0 ? <option value="">—</option> : null}
                        {overview.runs.map((r) => <option key={r.id} value={r.id}>{r.period} · {label('hr.run.status', r.status)}</option>)}
                    </Select>
                </FormField>
            </div>
            <Tabs defaultValue="run">
                <TabsList aria-label={t('hr.run.title')}>
                    <TabsTrigger value="run">{t('hr.run.runTab')}</TabsTrigger>
                    <TabsTrigger value="adjustments">{t('hr.run.adjustmentsTab')}</TabsTrigger>
                </TabsList>
                <TabsContent className="flex flex-col gap-3" value="run">
                    {run === null ? <EmptyState illustration="checklist" title={t('hr.run.none')} /> : (
                        <>
                            <div className="flex flex-wrap items-center gap-3" data-testid="hr-run-summary">
                                <StatusBadge label={label('hr.run.status', run.status)} tone={TONE[run.status]} />
                                <span className="text-sm text-muted-foreground">{run.number}{run.revision > 0 ? ` · ${t('hr.run.revision', { n: run.revision })}` : ''}</span>
                                <span className="text-sm">{t('hr.run.totals', { employees: run.employees, gross: money(run.gross_minor), net: money(run.net_minor) })}</span>
                                <span className="text-xs text-muted-foreground">{t('hr.run.totalsMore', { tax: money(run.tax_minor), social: money(run.employee_social_minor), employer: money(run.employer_social_minor) })}</span>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {run.may.calculate ? <Button disabled={action.busy} onClick={() => void step('calculate')} type="button">{run.status === 'draft' ? t('hr.run.calculate') : t('hr.run.recalculate')}</Button> : null}
                                {run.may.review ? <Button disabled={action.busy} onClick={() => void step('review')} type="button" variant="outline">{t('hr.run.review')}</Button> : null}
                                {run.may.approve ? <Button disabled={action.busy} onClick={() => void step('approve')} type="button" variant="outline">{approveText(run)}</Button> : null}
                                {run.may.reopen ? <Button onClick={() => { action.clear(); setReopen(''); }} type="button" variant="outline">{t('hr.run.reopen')}</Button> : null}
                                {run.may.lock ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/payroll/runs/${run.id}/lock`, { body: { lock_version: run.lock_version }, reload })} type="button" variant="outline">{t('hr.run.lock')}</Button> : null}
                                {['approved', 'paid', 'locked'].includes(run.status) ? <Button asChild variant="outline"><a href={`/hr/payroll/runs/${run.id}/export`}>{t('hr.run.export')}</a></Button> : null}
                                {run.may.discard ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/payroll/runs/${run.id}/discard`, { body: {}, reload })} type="button" variant="outline">{t('hr.run.discard')}</Button> : null}
                            </div>
                            {run.approval !== null && !run.approval.consumed ? <Alert title={label('hr.run.approvalState', run.approval.status)} tone="info" /> : null}
                            {overview.without_pay.length > 0 ? <Alert title={t('hr.run.withoutPay', { names: overview.without_pay.map((p) => p.name).join(', ') })} tone="warning" /> : null}
                            <DataGrid caption={t('hr.run.runTab')} columns={lineColumns} empty={<EmptyState illustration="checklist" title={t('hr.run.noLines')} />} getRowId={(l) => l.id} id="hr.run.lines" rows={run.lines} testId="hr-run-lines" />
                        </>
                    )}
                </TabsContent>
                <TabsContent className="flex flex-col gap-3" value="adjustments">
                    <div className="flex gap-2"><Button onClick={() => { action.clear(); setAdjust({ ...BLANK_ADJUSTMENT, employeeId: overview.employees[0]?.id ?? '' }); }} type="button">{t('hr.run.addAdjustment')}</Button></div>
                    <p className="text-xs text-muted-foreground">{t('hr.run.adjustHint')}</p>
                    <DataGrid caption={t('hr.run.adjustmentsTab')} columns={adjustmentColumns} empty={<EmptyState illustration="checklist" title={t('hr.run.noAdjustments')} />} getRowId={(a) => a.id} id="hr.run.adjustments" rows={overview.adjustments} testId="hr-run-adjustments" />
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setCreate(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={create === null || !/^\d{4}-\d{2}$/.test(create)} loading={action.busy} onClick={() => void createRun()} type="button">{t('hr.run.create')}</Button></>}
                onClose={() => setCreate(null)}
                open={create !== null}
                title={t('hr.run.new')}
            >
                {create !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <FormField error={action.fieldError('period')} field="period" hint={t('hr.run.periodHint')} label={t('hr.run.period')}><Input onChange={(e) => setCreate(e.target.value)} type="month" value={create} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setReopen(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={reopen === null || reopen.trim() === ''} loading={action.busy} onClick={() => void doReopen()} type="button">{t('hr.run.reopen')}</Button></>}
                onClose={() => setReopen(null)}
                open={reopen !== null}
                title={t('hr.run.reopen')}
            >
                {reopen !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <p className="text-sm text-muted-foreground">{t('hr.run.reopenHint')}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setReopen(e.target.value)} value={reopen} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAdjust(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={adjust === null || adjust.employeeId === '' || adjustAmount(adjust.amount) === 0 || adjust.label.trim() === '' || adjust.reason.trim() === ''} loading={action.busy} onClick={() => void saveAdjustment()} type="button">{t('hr.run.saveAdjustment')}</Button></>}
                onClose={() => setAdjust(null)}
                open={adjust !== null}
                title={t('hr.run.addAdjustment')}
            >
                {adjust !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('employee_id')} field="employee_id" label={t('hr.col.name')}><Select onChange={(e) => setAdjust({ ...adjust, employeeId: e.target.value })} value={adjust.employeeId}>{overview.employees.map((e) => <option key={e.id} value={e.id}>{e.name} · {e.number}</option>)}</Select></FormField></div>
                        <FormField error={action.fieldError('amount_minor')} field="amount_minor" hint={t('hr.run.amountHint')} label={t('hr.pay.amount')}><Input inputMode="numeric" onChange={(e) => setAdjust({ ...adjust, amount: e.target.value })} value={adjust.amount} /></FormField>
                        <FormField error={action.fieldError('label')} field="label" label={t('hr.run.adjustLabel')}><Input maxLength={60} onChange={(e) => setAdjust({ ...adjust, label: e.target.value })} value={adjust.label} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('source_run_id')} field="source_run_id" hint={t('hr.run.sourceHint')} label={t('hr.run.corrects')}><Select onChange={(e) => setAdjust({ ...adjust, sourceRunId: e.target.value })} value={adjust.sourceRunId}><option value="">{t('hr.run.noSource')}</option>{overview.runs.filter((r) => ['approved', 'paid', 'locked'].includes(r.status)).map((r) => <option key={r.id} value={r.id}>{r.period}</option>)}</Select></FormField></div>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setAdjust({ ...adjust, reason: e.target.value })} value={adjust.reason} /></FormField></div>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2"><input checked={adjust.taxable} onChange={(e) => setAdjust({ ...adjust, taxable: e.target.checked })} type="checkbox" />{t('hr.pay.taxable')}</label>
                    </div>
                )}
            </Dialog>

            <Dialog footer={<Button onClick={() => setDetail(null)} type="button">{t('hr.run.close')}</Button>} onClose={() => setDetail(null)} open={detail !== null} title={detail === null ? '' : t('hr.run.detailsOf', { name: detail.employee.name })}>
                {detail !== null && (
                    <div className="flex flex-col gap-3 text-sm" data-testid="hr-run-detail">
                        <p className="text-muted-foreground">{t('hr.run.detailCounts', { absent: detail.absent_days, late: detail.late_minutes, overtime: detail.overtime_minutes, unpaid: detail.unpaid_leave_days, status: detail.ptkp_status })}</p>
                        {(['earning', 'deduction', 'employer'] as const).map((kind) => (
                            <section key={kind}>
                                <h3 className="font-semibold">{label('hr.run.itemKind', kind)}</h3>
                                <ul>{detail.items.filter((i) => i.kind === kind).map((i, n) => <li className="flex justify-between gap-4" key={`${i.code}-${n}`}><span>{i.label}</span><span>{money(i.amount_minor)}</span></li>)}</ul>
                            </section>
                        ))}
                        <p className="flex justify-between border-t border-border pt-2 font-semibold"><span>{t('hr.run.net')}</span><span>{money(detail.net_minor)}</span></p>
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
