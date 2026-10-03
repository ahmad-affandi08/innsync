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
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { LeaveBalance, LeaveBalanceItem, LeaveKind, LeaveOverview, LeaveRequest, LeaveStatus } from '@/modules/hr/lib/hr';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<LeaveStatus, StatusTone> = { pending_approval: 'pending', approved: 'success', rejected: 'danger', cancelled: 'neutral' };
const BLANK_KIND = { id: '', code: '', name: '', deducts: false, days: '12', months: '12', evidence: '', paid: true, lock: 0 };

/** Leave: the person's own balance and requests, and for those who manage it every request, every balance and the kinds of leave. */
export default function LeavePage({ overview, kinds }: { overview: LeaveOverview; kinds: LeaveKind[] | null }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [ask, setAsk] = useState<{ employeeId: string; typeId: string; from: string; to: string; reason: string } | null>(null);
    const [file, setFile] = useState<File | null>(null);
    const [fileKey, setFileKey] = useState(0);
    const [adjust, setAdjust] = useState<{ employeeId: string; typeId: string; days: string; reason: string } | null>(null);
    const [kind, setKind] = useState<typeof BLANK_KIND | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const manage = overview.may.manage;
    const reload = ['overview', 'kinds'];
    const period = (r: LeaveRequest) => (r.from_date === r.to_date ? format.date(r.from_date) : `${format.date(r.from_date)} → ${format.date(r.to_date)}`);
    const picked = overview.types.find((x) => x.id === ask?.typeId);

    function openAsk() {
        action.clear();
        setFile(null);
        setAsk({ employeeId: '', typeId: overview.types[0]?.id ?? '', from: overview.today, to: overview.today, reason: '' });
    }

    async function send() {
        if (ask === null) return;
        const body = new FormData();
        if (ask.employeeId !== '') body.set('employee_id', ask.employeeId);
        body.set('leave_type_id', ask.typeId);
        body.set('from_date', ask.from);
        body.set('to_date', ask.to);
        body.set('reason', ask.reason.trim());
        if (file !== null) body.set('evidence', file);
        const result = await action.run('/hr/leave', { idempotencyKey: newIdempotencyKey(), body, reload });

        if (result !== null) {
            setAsk(null);
            setFile(null);
            setFileKey((k) => k + 1);
        }
    }

    async function saveAdjust() {
        if (adjust === null) return;
        const result = await action.run('/hr/leave/adjust', { idempotencyKey: newIdempotencyKey(), body: { employee_id: adjust.employeeId, leave_type_id: adjust.typeId, year: overview.year, days: Number(adjust.days), reason: adjust.reason.trim() }, reload });

        if (result !== null) setAdjust(null);
    }

    async function saveKind() {
        if (kind === null) return;
        const evidence = kind.evidence.trim() === '' ? null : Number(kind.evidence);
        const result = kind.id === ''
            ? await action.run<LeaveKind>('/hr/leave/types', { body: { code: kind.code.trim(), name: kind.name.trim(), deducts_balance: kind.deducts, paid: kind.paid, entitlement_days: kind.deducts ? Number(kind.days) : 0, eligible_after_months: kind.deducts ? Number(kind.months) : 0, evidence_after_days: evidence }, reload })
            : await action.run<LeaveKind>(`/hr/leave/types/${kind.id}`, { body: { name: kind.name.trim(), paid: kind.paid, entitlement_days: kind.deducts ? Number(kind.days) : 0, eligible_after_months: kind.deducts ? Number(kind.months) : 0, evidence_after_days: evidence, lock_version: kind.lock }, reload });

        if (result !== null) setKind(null);
    }

    const requestColumns = (withName: boolean): DataGridColumn<LeaveRequest>[] => [
        ...(withName ? [{ id: 'name', label: t('hr.col.name'), value: (r: LeaveRequest) => r.employee.name, rowHeader: true, cell: (r: LeaveRequest) => <span>{r.employee.name}<span className="block text-xs text-muted-foreground">{r.employee.number} · {label('hr.department', r.employee.department)}</span></span> }] : []),
        { id: 'type', label: t('hr.leave.type'), value: (r) => r.type.code, filter: 'select', filterLabel: (v) => v, cell: (r) => <span>{r.type.name}<span className="block text-xs text-muted-foreground">{r.type.code}</span></span> },
        { id: 'period', label: t('hr.leave.period'), value: (r) => r.from_date, cell: (r) => period(r) },
        { id: 'days', label: t('hr.leave.days'), align: 'right', value: (r) => r.days },
        { id: 'status', label: t('hr.col.status'), value: (r) => r.status, filter: 'select', filterLabel: (v) => label('hr.leave.status', v), cell: (r) => <StatusBadge label={label('hr.leave.status', r.status)} tone={TONE[r.status]} /> },
        { id: 'reason', label: t('hr.att.reasonShort'), value: (r) => r.reason },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (r) => (
                <span className="flex flex-wrap gap-2">
                    {r.has_evidence ? <Button asChild size="sm" variant="outline"><a href={`/hr/leave/${r.id}/evidence`} rel="noreferrer" target="_blank">{t('hr.leave.paper')}</a></Button> : null}
                    {r.may.release ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/leave/${r.id}/release`, { body: {}, reload })} size="sm" type="button">{t('hr.att.take')}</Button> : null}
                    {r.may.cancel ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/leave/${r.id}/cancel`, { body: {}, reload })} size="sm" type="button" variant="outline">{t('hr.att.cancelRequest')}</Button> : null}
                </span>
            ),
        },
    ];

    type BalanceRow = LeaveBalanceItem & { employee: LeaveBalance['employee'] };
    const balanceRows: BalanceRow[] = (overview.balances ?? []).flatMap((b) => b.items.map((i) => ({ ...i, employee: b.employee })));
    const balanceColumns: DataGridColumn<BalanceRow>[] = [
        { id: 'name', label: t('hr.col.name'), value: (r) => r.employee.name, rowHeader: true, cell: (r) => <span>{r.employee.name}<span className="block text-xs text-muted-foreground">{r.employee.number} · {label('hr.department', r.employee.department)}</span></span> },
        { id: 'type', label: t('hr.leave.type'), value: (r) => r.code, cell: (r) => `${r.name} (${r.code})` },
        { id: 'ent', label: t('hr.leave.entitlement'), align: 'right', value: (r) => r.entitlement, cell: (r) => (r.entitlement === 0 ? <span className="text-muted-foreground" title={t('hr.leave.eligibleOn', { date: format.date(r.eligible_on) })}>0 · {format.date(r.eligible_on, 'short')}</span> : r.entitlement) },
        { id: 'adj', label: t('hr.leave.adjusted'), align: 'right', value: (r) => r.adjusted },
        { id: 'taken', label: t('hr.leave.taken'), align: 'right', value: (r) => r.taken },
        { id: 'pending', label: t('hr.leave.pending'), align: 'right', value: (r) => r.pending },
        { id: 'left', label: t('hr.leave.remaining'), align: 'right', value: (r) => r.remaining, cell: (r) => <strong>{r.remaining}</strong> },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (r) => <Button onClick={() => { action.clear(); setAdjust({ employeeId: r.employee.id, typeId: r.type_id, days: '', reason: '' }); }} size="sm" type="button" variant="outline">{t('hr.leave.adjust')}</Button> },
    ];
    const kindColumns: DataGridColumn<LeaveKind>[] = [
        { id: 'code', label: t('hr.shift.code'), value: (k) => k.code, rowHeader: true },
        { id: 'name', label: t('hr.shift.name'), value: (k) => k.name },
        { id: 'rule', label: t('hr.leave.rule'), value: (k) => (k.deducts_balance ? k.entitlement_days : 0), sortable: false, cell: (k) => (k.deducts_balance ? t('hr.leave.ruleBalance', { days: k.entitlement_days, months: k.eligible_after_months }) : t('hr.leave.ruleNone')) },
        { id: 'evidence', label: t('hr.leave.evidence'), value: (k) => k.evidence_after_days ?? -1, cell: (k) => (k.evidence_after_days === null ? '—' : k.evidence_after_days === 0 ? t('hr.leave.evidenceAlways') : t('hr.leave.evidenceAfter', { n: k.evidence_after_days })) },
        { id: 'paid', label: t('hr.leave.paid'), value: (k) => (k.paid ? 1 : 0), cell: (k) => (k.paid ? t('hr.leave.yes') : t('hr.leave.no')) },
        { id: 'status', label: t('hr.col.status'), value: (k) => (k.active ? 'active' : 'retired'), cell: (k) => <StatusBadge label={k.active ? t('hr.shift.active') : t('hr.shift.retired')} tone={k.active ? 'success' : 'neutral'} /> },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (k) => (
                <span className="flex gap-2">
                    <Button onClick={() => { action.clear(); setKind({ id: k.id, code: k.code, name: k.name, deducts: k.deducts_balance, days: String(k.entitlement_days), months: String(k.eligible_after_months), evidence: k.evidence_after_days === null ? '' : String(k.evidence_after_days), paid: k.paid, lock: k.lock_version }); }} size="sm" type="button" variant="outline">{t('hr.edit')}</Button>
                    <Button disabled={action.busy} onClick={() => void action.run(`/hr/leave/types/${k.id}/active`, { body: { active: !k.active, lock_version: k.lock_version }, reload })} size="sm" type="button" variant="outline">{k.active ? t('hr.shift.retire') : t('hr.shift.resume')}</Button>
                </span>
            ),
        },
    ];

    return (
        <HrShell
            actions={overview.mine !== null || manage ? <Button disabled={overview.types.length === 0} onClick={openAsk} type="button">{t('hr.leave.ask')}</Button> : undefined}
            description={t('hr.leave.description')}
            title={t('hr.leave.title')}
        >
            {action.error !== null && ask === null && adjust === null && kind === null ? failure : null}
            {overview.types.length === 0 ? <Alert title={t('hr.leave.noKinds')} tone="warning" /> : null}
            <Tabs defaultValue={overview.mine !== null ? 'mine' : 'requests'}>
                <TabsList aria-label={t('hr.leave.title')}>
                    {overview.mine !== null ? <TabsTrigger value="mine">{t('hr.leave.mineTab')}</TabsTrigger> : null}
                    {manage ? <TabsTrigger value="requests">{t('hr.leave.requestsTab')}</TabsTrigger> : null}
                    {manage ? <TabsTrigger value="balances">{t('hr.leave.balancesTab')}</TabsTrigger> : null}
                    {manage ? <TabsTrigger value="kinds">{t('hr.leave.kindsTab')}</TabsTrigger> : null}
                </TabsList>
                {overview.mine !== null ? (
                    <TabsContent className="flex flex-col gap-3" value="mine">
                        <div className="flex flex-wrap gap-3" data-testid="hr-leave-mine">
                            {overview.mine.balances.map((b) => (
                                <section className="flex min-w-48 flex-col gap-1 border border-border bg-surface p-3" key={b.type_id}>
                                    <h2 className="text-sm text-muted-foreground">{b.name} · {overview.year}</h2>
                                    <p className="text-2xl font-semibold">{t('hr.leave.daysLeft', { n: b.remaining })}</p>
                                    <p className="text-xs text-muted-foreground">{t('hr.leave.balanceLine', { entitlement: b.entitlement, adjusted: b.adjusted, taken: b.taken, pending: b.pending })}</p>
                                    {b.entitlement === 0 ? <p className="text-xs text-muted-foreground">{t('hr.leave.eligibleOn', { date: format.date(b.eligible_on) })}</p> : null}
                                </section>
                            ))}
                        </div>
                        <DataGrid caption={t('hr.leave.mineTab')} columns={requestColumns(false)} empty={<EmptyState illustration="checklist" title={t('hr.leave.noRequests')} />} getRowId={(r) => r.id} id="hr.leave.mine" rows={overview.mine.requests} testId="hr-leave-mine-requests" />
                    </TabsContent>
                ) : null}
                {manage ? (
                    <TabsContent className="flex flex-col gap-3" value="requests">
                        <DataGrid caption={t('hr.leave.requestsTab')} columns={requestColumns(true)} empty={<EmptyState illustration="checklist" title={t('hr.leave.noRequests')} />} getRowId={(r) => r.id} id="hr.leave.requests" rows={overview.requests ?? []} testId="hr-leave-requests" />
                    </TabsContent>
                ) : null}
                {manage ? (
                    <TabsContent className="flex flex-col gap-3" value="balances">
                        <div className="flex flex-wrap items-end gap-2">
                            <FormField label={t('hr.leave.year')}><Select onChange={(e) => router.get('/hr/leave', { year: e.target.value })} value={String(overview.year)}>{overview.years.map((y) => <option key={y} value={y}>{y}</option>)}</Select></FormField>
                        </div>
                        <p className="text-xs text-muted-foreground">{t('hr.leave.balanceHint')}</p>
                        <DataGrid caption={t('hr.leave.balancesTab')} columns={balanceColumns} empty={<EmptyState illustration="checklist" title={t('hr.leave.noBalances')} />} getRowId={(r) => `${r.employee.id}-${r.type_id}`} id="hr.leave.balances" rows={balanceRows} testId="hr-leave-balances" />
                        {(overview.adjustments ?? []).length > 0 ? (
                            <section className="flex flex-col gap-1 text-sm">
                                <h2 className="font-semibold">{t('hr.leave.adjustments')}</h2>
                                <ul>{(overview.adjustments ?? []).map((a) => <li key={a.id}>{a.employee.name} · {a.type_code} · {a.days > 0 ? `+${a.days}` : a.days} · {a.reason}</li>)}</ul>
                            </section>
                        ) : null}
                    </TabsContent>
                ) : null}
                {manage ? (
                    <TabsContent className="flex flex-col gap-3" value="kinds">
                        <div className="flex gap-2">
                            <Button onClick={() => { action.clear(); setKind({ ...BLANK_KIND }); }} type="button">{t('hr.leave.addKind')}</Button>
                            {(kinds ?? []).length === 0 ? <Button disabled={action.busy} onClick={() => void action.run('/hr/leave/types/baseline', { body: {}, reload })} type="button" variant="outline">{t('hr.leave.baseline')}</Button> : null}
                        </div>
                        <p className="text-xs text-muted-foreground">{t('hr.leave.kindsHint')}</p>
                        <DataGrid caption={t('hr.leave.kindsTab')} columns={kindColumns} empty={<EmptyState illustration="checklist" title={t('hr.leave.noKinds')} />} getRowId={(k) => k.id} id="hr.leave.kinds" rows={kinds ?? []} testId="hr-leave-kinds" />
                    </TabsContent>
                ) : null}
            </Tabs>
            {overview.mine === null && !manage ? <Alert title={t('hr.att.noEmployee')} tone="warning" /> : null}

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAsk(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={ask?.typeId === '' || ask?.reason.trim() === '' || ask?.from === '' || ask?.to === ''} loading={action.busy} onClick={() => void send()} type="button">{t('hr.leave.askDo')}</Button></>}
                onClose={() => setAsk(null)}
                open={ask !== null}
                title={t('hr.leave.ask')}
            >
                {ask !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        {manage ? <div className="sm:col-span-2"><FormField error={action.fieldError('employee_id')} field="employee_id" hint={t('hr.leave.forHint')} label={t('hr.col.name')}><Select onChange={(e) => setAsk({ ...ask, employeeId: e.target.value })} value={ask.employeeId}>{overview.mine !== null ? <option value="">{t('hr.leave.myself')}</option> : null}{(overview.employees ?? []).map((e) => <option key={e.id} value={e.id}>{e.name} · {e.number}</option>)}</Select></FormField></div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('leave_type_id')} field="leave_type_id" label={t('hr.leave.type')}><Select onChange={(e) => setAsk({ ...ask, typeId: e.target.value })} value={ask.typeId}>{overview.types.map((x) => <option key={x.id} value={x.id}>{x.name} ({x.code})</option>)}</Select></FormField></div>
                        <FormField error={action.fieldError('from_date')} field="from_date" label={t('hr.leave.from')}><DatePicker onChange={(e) => setAsk({ ...ask, from: e.target.value, to: ask.to < e.target.value ? e.target.value : ask.to })} value={ask.from} /></FormField>
                        <FormField error={action.fieldError('to_date')} field="to_date" label={t('hr.leave.to')}><DatePicker onChange={(e) => setAsk({ ...ask, to: e.target.value })} value={ask.to} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setAsk({ ...ask, reason: e.target.value })} value={ask.reason} /></FormField></div>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('evidence')} field="evidence" hint={picked?.evidence_after_days === null || picked === undefined ? t('hr.leave.evidenceOptional') : t('hr.leave.evidenceRule', { n: picked.evidence_after_days })} label={t('hr.leave.paper')}><Input accept="application/pdf,image/jpeg,image/png" key={fileKey} onChange={(e) => setFile(e.target.files?.[0] ?? null)} type="file" /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAdjust(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={adjust?.days.trim() === '' || adjust?.reason.trim() === ''} loading={action.busy} onClick={() => void saveAdjust()} type="button">{t('hr.leave.adjustDo')}</Button></>}
                onClose={() => setAdjust(null)}
                open={adjust !== null}
                title={t('hr.leave.adjust')}
            >
                {adjust !== null && (
                    <div className="grid gap-3">
                        <p className="text-sm text-muted-foreground">{t('hr.leave.adjustHint', { year: overview.year })}</p>
                        {failure}
                        <FormField error={action.fieldError('days')} field="days" label={t('hr.leave.adjustDays')}><Input inputMode="numeric" onChange={(e) => setAdjust({ ...adjust, days: e.target.value })} placeholder="+3" value={adjust.days} /></FormField>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setAdjust({ ...adjust, reason: e.target.value })} value={adjust.reason} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setKind(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={kind?.name.trim() === '' || (kind?.id === '' && kind.code.trim() === '')} loading={action.busy} onClick={() => void saveKind()} type="button">{t('hr.leave.kindSave')}</Button></>}
                onClose={() => setKind(null)}
                open={kind !== null}
                title={t('hr.leave.kindTitle')}
            >
                {kind !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('code')} field="code" label={t('hr.shift.code')}><Input disabled={kind.id !== ''} maxLength={8} onChange={(e) => setKind({ ...kind, code: e.target.value })} value={kind.code} /></FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('hr.shift.name')}><Input maxLength={60} onChange={(e) => setKind({ ...kind, name: e.target.value })} value={kind.name} /></FormField>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2"><input checked={kind.deducts} disabled={kind.id !== ''} onChange={(e) => setKind({ ...kind, deducts: e.target.checked })} type="checkbox" />{t('hr.leave.deducts')}</label>
                        {kind.deducts ? <FormField error={action.fieldError('entitlement_days')} field="entitlement_days" label={t('hr.leave.entitlementDays')}><Input inputMode="numeric" onChange={(e) => setKind({ ...kind, days: e.target.value })} value={kind.days} /></FormField> : null}
                        {kind.deducts ? <FormField error={action.fieldError('eligible_after_months')} field="eligible_after_months" label={t('hr.leave.eligibleMonths')}><Input inputMode="numeric" onChange={(e) => setKind({ ...kind, months: e.target.value })} value={kind.months} /></FormField> : null}
                        <FormField error={action.fieldError('evidence_after_days')} field="evidence_after_days" hint={t('hr.leave.evidenceFieldHint')} label={t('hr.leave.evidenceAfterField')}><Input inputMode="numeric" onChange={(e) => setKind({ ...kind, evidence: e.target.value })} value={kind.evidence} /></FormField>
                        <label className="flex items-center gap-2 self-end text-sm"><input checked={kind.paid} onChange={(e) => setKind({ ...kind, paid: e.target.checked })} type="checkbox" />{t('hr.leave.paid')}</label>
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
