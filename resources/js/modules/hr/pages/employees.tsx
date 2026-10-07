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
import { CsvImportDialog } from '@/components/csv-import-dialog';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { Employee, EmployeeDetail, HrDocument, HrOverview, HrSettings, HrWarning } from '@/modules/hr/lib/hr';
import { apiRequest, newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const WARNING_TONE: Record<HrWarning['status'], StatusTone> = { expired: 'danger', expiring: 'warning', missing: 'unknown' };
const BLANK = { id: '', fullName: '', department: 'general', position: '', joinedOn: '', contractType: 'permanent', contractEnd: '', supervisorId: '', userId: '', phone: '', email: '', lock: 0 };
const OFFBOARD = { kind: 'resigned', on: '', reason: '', reassignTo: '', items: [{ item: '', returned: 'yes', note: '' }] };
const PAPER = { kind: 'contract', title: '', issuedOn: '', validUntil: '', replacesId: '' };

/** The people who work here: their records, personnel papers, the warnings of what lapses, and offboarding. */
export default function EmployeesPage({ overview }: { overview: HrOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [importing, setImporting] = useState(false);
    const [form, setForm] = useState<typeof BLANK | null>(null);
    const [detail, setDetail] = useState<EmployeeDetail | null>(null);
    const [papers, setPapers] = useState<HrDocument[]>([]);
    const [paper, setPaper] = useState(PAPER);
    const [paperFile, setPaperFile] = useState<File | null>(null);
    const [fileKey, setFileKey] = useState(0);
    const [off, setOff] = useState(OFFBOARD);
    const [settings, setSettings] = useState<{ warnDays: string; required: string[]; lock: number | null } | null>(null);
    const [loadFailed, setLoadFailed] = useState(false);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const d = detail;

    async function open(e: { id: string }) {
        action.clear();
        setLoadFailed(false);
        try {
            const next = await apiRequest<EmployeeDetail>(`/hr/employees/${e.id}`, { method: 'GET' });

            setDetail(next);
            setPaper(PAPER);
            setPaperFile(null);
            setOff({ ...OFFBOARD, on: overview.business_date, items: [{ item: '', returned: 'yes', note: '' }] });
            setPapers(next.may.documents ? (await apiRequest<{ documents: HrDocument[] }>(`/hr/employees/${e.id}/documents`, { method: 'GET' })).documents : []);
        } catch {
            setLoadFailed(true);
        }
    }

    function taken(next: EmployeeDetail | null) {
        if (next !== null) {
            setDetail(next);
            router.reload({ only: ['overview'] });
        }

        return next;
    }

    async function saveEmployee() {
        if (form === null) return;
        const body = {
            full_name: form.fullName.trim(), department: form.department, position: form.position.trim(), joined_on: form.joinedOn, contract_type: form.contractType, contract_end_on: form.contractType === 'permanent' || form.contractEnd === '' ? null : form.contractEnd,
            supervisor_id: form.supervisorId === '' ? null : form.supervisorId, user_id: form.userId === '' ? null : form.userId, phone: form.phone.trim() === '' ? null : form.phone.trim(), email: form.email.trim() === '' ? null : form.email.trim(),
        };
        const result = form.id === ''
            ? await action.run<EmployeeDetail>('/hr/employees', { idempotencyKey: newIdempotencyKey(), body })
            : await action.run<EmployeeDetail>(`/hr/employees/${form.id}`, { body: { ...body, lock_version: form.lock } });

        if (taken(result) !== null) {
            setForm(null);
            if (result !== null) void open(result);
        }
    }

    async function addPaper() {
        if (d === null) return;
        const body = new FormData();

        body.set('kind', paper.kind);
        body.set('title', paper.title.trim());
        if (paper.issuedOn !== '') body.set('issued_on', paper.issuedOn);
        if (paper.validUntil !== '') body.set('valid_until', paper.validUntil);
        if (paper.replacesId !== '') body.set('replaces_id', paper.replacesId);
        if (paperFile !== null) body.set('file', paperFile);
        const result = await action.run<{ documents: HrDocument[] }>(`/hr/employees/${d.id}/documents`, { body, reload: ['overview'] });

        if (result !== null) {
            setPapers(result.documents);
            setPaper(PAPER);
            setPaperFile(null);
            setFileKey((k) => k + 1);
        }
    }

    async function offboard() {
        if (d === null) return;
        const body = {
            kind: off.kind, offboarded_on: off.on, reason: off.reason.trim() === '' ? null : off.reason.trim(), reassign_to: off.reassignTo === '' ? null : off.reassignTo, lock_version: d.lock_version,
            items: off.items.filter((i) => i.item.trim() !== '').map((i) => ({ item: i.item.trim(), returned: i.returned === 'yes', note: i.note.trim() === '' ? null : i.note.trim() })),
        };

        taken(await action.run<EmployeeDetail>(`/hr/employees/${d.id}/offboard`, { body }));
    }

    async function saveSettings() {
        if (settings === null) return;
        const result = await action.run<HrSettings>('/hr/settings', { body: { warn_days: Number(settings.warnDays), required_kinds: settings.required, lock_version: settings.lock }, reload: ['overview'] });

        if (result !== null) setSettings(null);
    }

    const edit = (e: Employee) => { action.clear(); setForm({ id: e.id, fullName: e.full_name, department: e.department, position: e.position, joinedOn: e.joined_on, contractType: e.contract_type, contractEnd: e.contract_end_on ?? '', supervisorId: e.supervisor_id ?? '', userId: e.user_id ?? '', phone: e.phone ?? '', email: e.email ?? '', lock: e.lock_version }); };
    const warningText = (w: HrWarning) => (w.type === 'contract_end' ? t('hr.warn.contract') : w.type === 'missing' ? t('hr.warn.missing', { kind: label('hr.kind', w.kind) }) : t('hr.warn.document', { kind: label('hr.kind', w.kind), title: w.title ?? '' }));
    const supervisors = overview.employees.filter((e) => e.status === 'active' && e.id !== form?.id);

    const columns: DataGridColumn<Employee>[] = [
        { id: 'number', label: t('hr.col.number'), value: (e) => e.number, rowHeader: true },
        { id: 'name', label: t('hr.col.name'), value: (e) => e.full_name, searchText: (e) => `${e.full_name} ${e.number} ${e.position}`, cell: (e) => <span>{e.full_name}<span className="block text-xs text-muted-foreground">{e.position}</span></span> },
        { id: 'department', label: t('hr.col.department'), value: (e) => e.department, filter: 'select', filterLabel: (v) => label('hr.department', v), cell: (e) => label('hr.department', e.department) },
        { id: 'contract', label: t('hr.col.contract'), value: (e) => e.contract_type, filter: 'select', filterLabel: (v) => label('hr.contract', v), cell: (e) => <span>{label('hr.contract', e.contract_type)}{e.contract_end_on !== null ? <span className="block text-xs text-muted-foreground">{t('hr.until', { date: format.date(e.contract_end_on) })}</span> : null}</span> },
        { id: 'supervisor', label: t('hr.col.supervisor'), value: (e) => e.supervisor ?? '', cell: (e) => e.supervisor ?? '—' },
        { id: 'joined', label: t('hr.col.joined'), value: (e) => e.joined_on, cell: (e) => format.date(e.joined_on) },
        { id: 'status', label: t('hr.col.status'), value: (e) => e.status, filter: 'select', filterLabel: (v) => label('hr.status', v), cell: (e) => <StatusBadge label={label('hr.status', e.status)} tone={e.status === 'active' ? 'success' : 'neutral'} /> },
        { id: 'open', label: '', value: () => '', sortable: false, cell: (e) => <Button onClick={() => void open(e)} size="sm" type="button" variant="outline">{t('hr.open')}</Button> },
    ];
    const warningColumns: DataGridColumn<HrWarning>[] = [
        { id: 'status', label: t('hr.col.status'), value: (w) => w.status, filter: 'select', filterLabel: (v) => label('hr.warn.status', v), cell: (w) => <StatusBadge label={label('hr.warn.status', w.status)} tone={WARNING_TONE[w.status]} /> },
        { id: 'who', label: t('hr.col.name'), value: (w) => w.name, rowHeader: true, cell: (w) => <span>{w.name}<span className="block text-xs text-muted-foreground">{w.number} · {label('hr.department', w.department)}</span></span> },
        { id: 'what', label: t('hr.warn.what'), value: (w) => warningText(w), cell: (w) => warningText(w) },
        { id: 'date', label: t('hr.warn.date'), value: (w) => w.date ?? '', cell: (w) => (w.date === null ? '—' : <span>{format.date(w.date)}<span className="block text-xs text-muted-foreground">{w.days === null ? '' : w.days < 0 ? t('hr.warn.ago', { n: -w.days }) : t('hr.warn.in', { n: w.days })}</span></span>) },
    ];

    return (
        <HrShell
            actions={overview.may.manage ? <><Button onClick={() => setImporting(true)} type="button" variant="outline">{t('import.open')}</Button><Button onClick={() => { action.clear(); setSettings({ warnDays: String(overview.settings.warn_days), required: overview.settings.required_kinds, lock: overview.settings.lock_version }); }} type="button" variant="outline">{t('hr.settings')}</Button><Button onClick={() => { action.clear(); setForm({ ...BLANK, joinedOn: overview.business_date }); }} type="button">{t('hr.new')}</Button></> : undefined}
            description={t('hr.description')}
            title={t('hr.title')}
        >
            {loadFailed ? <Alert title={t('hr.loadFailed')} tone="danger" /> : null}
            {failure !== null && form === null && detail === null && settings === null ? failure : null}
            <div className="grid gap-3 sm:grid-cols-3" data-testid="hr-counts">
                <Metric label={t('hr.status.active')} value={String(overview.counts.active)} />
                <Metric label={t('hr.status.offboarded')} value={String(overview.counts.offboarded)} />
                {overview.may.manage || overview.may.documents ? <Metric label={t('hr.warnings')} value={String(overview.warnings.length)} /> : null}
            </div>
            <Tabs defaultValue="employees">
                <TabsList aria-label={t('hr.title')}>
                    <TabsTrigger value="employees">{t('hr.tab.employees')}</TabsTrigger>
                    {overview.may.manage || overview.may.documents ? <TabsTrigger value="warnings">{t('hr.tab.warnings', { count: overview.warnings.length })}</TabsTrigger> : null}
                </TabsList>
                <TabsContent className="flex flex-col gap-3" value="employees"><DataGrid caption={t('hr.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('hr.empty')} />} getRowId={(e) => e.id} id="hr.employees" rows={overview.employees} testId="hr-employees" /></TabsContent>
                {overview.may.manage || overview.may.documents ? <TabsContent className="flex flex-col gap-3" value="warnings"><p className="text-sm text-muted-foreground">{t('hr.warn.hint', { days: overview.settings.warn_days, kinds: overview.settings.required_kinds.map((k) => label('hr.kind', k)).join(', ') })}</p><DataGrid caption={t('hr.warnings')} columns={warningColumns} empty={<EmptyState illustration="checklist" title={t('hr.warn.none')} />} getRowId={(w) => `${w.employee_id}-${w.type}-${w.kind}-${w.title ?? ''}`} id="hr.warnings" rows={overview.warnings} testId="hr-warnings" /></TabsContent> : null}
            </Tabs>

            <Dialog
                className="w-[min(46rem,calc(100vw-2rem))]"
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form?.fullName.trim() === '' || form?.position.trim() === '' || form?.joinedOn === ''} loading={action.busy} onClick={() => void saveEmployee()} type="button">{t('hr.save')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form?.id === '' ? t('hr.new') : t('hr.edit')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('full_name')} field="full_name" label={t('hr.col.name')}><Input maxLength={120} onChange={(e) => setForm({ ...form, fullName: e.target.value })} value={form.fullName} /></FormField></div>
                        <FormField error={action.fieldError('department')} field="department" label={t('hr.col.department')}><Select onChange={(e) => setForm({ ...form, department: e.target.value })} value={form.department}>{overview.departments.map((x) => <option key={x} value={x}>{label('hr.department', x)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('position')} field="position" label={t('hr.col.position')}><Input maxLength={80} onChange={(e) => setForm({ ...form, position: e.target.value })} value={form.position} /></FormField>
                        <FormField error={action.fieldError('joined_on')} field="joined_on" label={t('hr.col.joined')}><DatePicker onChange={(e) => setForm({ ...form, joinedOn: e.target.value })} value={form.joinedOn} /></FormField>
                        <FormField error={action.fieldError('contract_type')} field="contract_type" label={t('hr.col.contract')}><Select onChange={(e) => setForm({ ...form, contractType: e.target.value })} value={form.contractType}>{overview.contracts.map((x) => <option key={x} value={x}>{label('hr.contract', x)}</option>)}</Select></FormField>
                        {form.contractType !== 'permanent' ? <FormField error={action.fieldError('contract_end_on')} field="contract_end_on" label={t('hr.contractEnd')}><DatePicker onChange={(e) => setForm({ ...form, contractEnd: e.target.value })} value={form.contractEnd} /></FormField> : null}
                        <FormField error={action.fieldError('supervisor_id')} field="supervisor_id" label={t('hr.col.supervisor')}><Select onChange={(e) => setForm({ ...form, supervisorId: e.target.value })} value={form.supervisorId}><option value="">—</option>{supervisors.map((s) => <option key={s.id} value={s.id}>{s.number} · {s.full_name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('user_id')} field="user_id" hint={t('hr.accountHint')} label={t('hr.account')}><Select onChange={(e) => setForm({ ...form, userId: e.target.value })} value={form.userId}><option value="">—</option>{form.userId !== '' && !overview.accounts.some((a) => a.id === form.userId) ? <option value={form.userId}>{overview.employees.find((x) => x.user_id === form.userId)?.account ?? form.userId}</option> : null}{overview.accounts.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('phone')} field="phone" label={t('hr.phone')}><Input inputMode="tel" maxLength={30} onChange={(e) => setForm({ ...form, phone: e.target.value })} value={form.phone} /></FormField>
                        <FormField error={action.fieldError('email')} field="email" label={t('hr.email')}><Input maxLength={120} onChange={(e) => setForm({ ...form, email: e.target.value })} type="email" value={form.email} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                className="w-[min(48rem,calc(100vw-2rem))]"
                footer={<Button onClick={() => setDetail(null)} type="button" variant="outline">{t('hr.close')}</Button>}
                onClose={() => setDetail(null)}
                open={detail !== null}
                title={d === null ? '' : `${d.number} · ${d.full_name}`}
            >
                {d !== null && (
                    <div className="flex flex-col gap-4" data-testid="hr-detail">
                        {failure}
                        <div className="flex flex-wrap items-center gap-2"><StatusBadge label={label('hr.status', d.status)} tone={d.status === 'active' ? 'success' : 'neutral'} />{d.may.edit ? <Button onClick={() => edit(d)} size="sm" type="button" variant="outline">{t('hr.edit')}</Button> : null}</div>
                        <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                            <div><dt className="text-muted-foreground">{t('hr.col.department')}</dt><dd>{label('hr.department', d.department)} · {d.position}</dd></div>
                            <div><dt className="text-muted-foreground">{t('hr.col.supervisor')}</dt><dd>{d.supervisor ?? '—'}</dd></div>
                            <div><dt className="text-muted-foreground">{t('hr.col.joined')}</dt><dd>{format.date(d.joined_on)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('hr.col.contract')}</dt><dd>{label('hr.contract', d.contract_type)}{d.contract_end_on !== null ? ` · ${t('hr.until', { date: format.date(d.contract_end_on) })}` : ''}</dd></div>
                            <div><dt className="text-muted-foreground">{t('hr.account')}</dt><dd>{d.account ?? '—'}</dd></div>
                            <div><dt className="text-muted-foreground">{t('hr.phone')}</dt><dd>{d.phone ?? '—'}</dd></div>
                            {d.status === 'offboarded' ? <div className="sm:col-span-2"><dt className="text-muted-foreground">{t('hr.left')}</dt><dd>{d.offboarded_on === null ? '' : format.date(d.offboarded_on)} · {d.offboard_kind === null ? '' : label('hr.leave', d.offboard_kind)}{d.offboard_reason !== null ? ` · ${d.offboard_reason}` : ''}{d.offboarded_by !== null ? ` · ${d.offboarded_by}` : ''}</dd></div> : null}
                        </dl>
                        {d.offboard_items.length > 0 ? <ul className="text-sm" data-testid="hr-handback">{d.offboard_items.map((i, n) => <li key={n}>{i.item} · {i.returned ? t('hr.returned') : t('hr.notReturned')}{i.note !== null ? ` · ${i.note}` : ''}</li>)}</ul> : null}

                        {d.may.documents ? (
                            <section aria-labelledby="hr-papers-h" className="flex flex-col gap-3 border-t border-border pt-3" data-testid="hr-papers">
                                <h3 className="text-sm font-semibold" id="hr-papers-h">{t('hr.papers')}</h3>
                                {papers.length === 0 ? <p className="text-sm text-muted-foreground">{t('hr.noPapers')}</p> : (
                                    <ul className="flex flex-col gap-1 text-sm">
                                        {papers.map((p) => <li className="flex flex-wrap items-center gap-2" key={p.id}>{label('hr.kind', p.kind)} · {p.title}{p.valid_until !== null ? <span className="text-muted-foreground">{t('hr.until', { date: format.date(p.valid_until) })}</span> : null}{!p.current ? <StatusBadge label={t('hr.replaced')} tone="neutral" /> : p.expired ? <StatusBadge label={t('hr.warn.status.expired')} tone="danger" /> : null}<Button asChild size="sm" variant="outline"><a href={`/hr/documents/${p.id}/file`} rel="noreferrer" target="_blank">{t('hr.openPaper')}</a></Button></li>)}
                                    </ul>
                                )}
                                {d.status === 'active' ? (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <FormField error={action.fieldError('kind')} field="kind" label={t('hr.paperKind')}><Select onChange={(e) => setPaper({ ...paper, kind: e.target.value })} value={paper.kind}>{overview.document_kinds.map((k) => <option key={k} value={k}>{label('hr.kind', k)}</option>)}</Select></FormField>
                                        <FormField error={action.fieldError('title')} field="title" label={t('hr.paperTitle')}><Input maxLength={120} onChange={(e) => setPaper({ ...paper, title: e.target.value })} value={paper.title} /></FormField>
                                        <FormField error={action.fieldError('issued_on')} field="issued_on" label={t('hr.issuedOn')}><DatePicker onChange={(e) => setPaper({ ...paper, issuedOn: e.target.value })} value={paper.issuedOn} /></FormField>
                                        <FormField error={action.fieldError('valid_until')} field="valid_until" label={t('hr.validUntil')}><DatePicker onChange={(e) => setPaper({ ...paper, validUntil: e.target.value })} value={paper.validUntil} /></FormField>
                                        <FormField error={action.fieldError('replaces_id')} field="replaces_id" label={t('hr.replaces')}><Select onChange={(e) => setPaper({ ...paper, replacesId: e.target.value })} value={paper.replacesId}><option value="">—</option>{papers.filter((p) => p.current).map((p) => <option key={p.id} value={p.id}>{label('hr.kind', p.kind)} · {p.title}</option>)}</Select></FormField>
                                        <FormField error={action.fieldError('file')} field="file" label={t('hr.file')}><Input accept="application/pdf,image/jpeg,image/png" key={fileKey} onChange={(e) => setPaperFile(e.target.files?.[0] ?? null)} type="file" /></FormField>
                                        <div><Button disabled={action.busy || paper.title.trim() === '' || paperFile === null} onClick={() => void addPaper()} size="sm" type="button">{t('hr.addPaper')}</Button></div>
                                    </div>
                                ) : null}
                            </section>
                        ) : null}

                        {d.may.offboard ? (
                            <section aria-labelledby="hr-off-h" className="flex flex-col gap-3 border-t border-border pt-3" data-testid="hr-offboard">
                                <h3 className="text-sm font-semibold" id="hr-off-h">{t('hr.offboard')}</h3>
                                <p className="text-xs text-muted-foreground">{t('hr.offboardHint')}</p>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField error={action.fieldError('kind')} field="kind" label={t('hr.leaveKind')}><Select onChange={(e) => setOff({ ...off, kind: e.target.value })} value={off.kind}>{overview.offboard_kinds.map((k) => <option key={k} value={k}>{label('hr.leave', k)}</option>)}</Select></FormField>
                                    <FormField error={action.fieldError('offboarded_on')} field="offboarded_on" label={t('hr.leftOn')}><DatePicker onChange={(e) => setOff({ ...off, on: e.target.value })} value={off.on} /></FormField>
                                    <div className="sm:col-span-2"><FormField error={action.fieldError('reason')} field="reason" label={t('hr.leaveReason')}><Input maxLength={200} onChange={(e) => setOff({ ...off, reason: e.target.value })} value={off.reason} /></FormField></div>
                                    {d.subordinates > 0 ? <div className="sm:col-span-2"><FormField error={action.fieldError('reassign_to')} field="reassign_to" hint={t('hr.reassignHint', { n: d.subordinates })} label={t('hr.reassign')}><Select onChange={(e) => setOff({ ...off, reassignTo: e.target.value })} value={off.reassignTo}><option value="">—</option>{d.supervisors.map((s) => <option key={s.id} value={s.id}>{s.number} · {s.name}</option>)}</Select></FormField></div> : null}
                                </div>
                                <div className="flex flex-col gap-2">
                                    <h4 className="text-sm font-semibold">{t('hr.handBack')}</h4>
                                    {off.items.map((it, i) => (
                                        <div className="grid gap-2 sm:grid-cols-[2fr_1fr_2fr_auto]" key={i}>
                                            <FormField error={i === 0 ? action.fieldError('items') : undefined} label={t('hr.item')}><Input maxLength={120} onChange={(e) => setOff({ ...off, items: off.items.map((x, n) => (n === i ? { ...x, item: e.target.value } : x)) })} value={it.item} /></FormField>
                                            <FormField label={t('hr.returnedQ')}><Select onChange={(e) => setOff({ ...off, items: off.items.map((x, n) => (n === i ? { ...x, returned: e.target.value } : x)) })} value={it.returned}><option value="yes">{t('hr.returned')}</option><option value="no">{t('hr.notReturned')}</option></Select></FormField>
                                            <FormField label={t('hr.itemNote')}><Input maxLength={200} onChange={(e) => setOff({ ...off, items: off.items.map((x, n) => (n === i ? { ...x, note: e.target.value } : x)) })} value={it.note} /></FormField>
                                            <div className="flex items-end">{off.items.length > 1 ? <Button aria-label={t('hr.removeItem')} onClick={() => setOff({ ...off, items: off.items.filter((_, n) => n !== i) })} size="sm" type="button" variant="outline">×</Button> : null}</div>
                                        </div>
                                    ))}
                                    <div><Button disabled={off.items.length >= 20} onClick={() => setOff({ ...off, items: [...off.items, { item: '', returned: 'yes', note: '' }] })} size="sm" type="button" variant="outline">{t('hr.addItem')}</Button></div>
                                </div>
                                <div><Button disabled={action.busy || off.on === ''} onClick={() => void offboard()} size="sm" type="button" variant="outline">{t('hr.offboardDo')}</Button></div>
                            </section>
                        ) : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setSettings(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveSettings()} type="button">{t('hr.saveSettings')}</Button></>}
                onClose={() => setSettings(null)}
                open={settings !== null}
                title={t('hr.settings')}
            >
                {settings !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{overview.settings.is_baseline ? t('hr.settingsBaseline') : t('hr.settingsHint')}</p>
                        {failure}
                        <FormField error={action.fieldError('warn_days')} field="warn_days" label={t('hr.warnDays')}><Input inputMode="numeric" onChange={(e) => setSettings({ ...settings, warnDays: e.target.value })} value={settings.warnDays} /></FormField>
                        <fieldset className="flex flex-col gap-1">
                            <legend className="text-sm font-medium">{t('hr.requiredKinds')}</legend>
                            {overview.document_kinds.map((k) => <label className="flex items-center gap-2 text-sm" key={k}><input checked={settings.required.includes(k)} onChange={(e) => setSettings({ ...settings, required: e.target.checked ? [...settings.required, k] : settings.required.filter((x) => x !== k) })} type="checkbox" />{label('hr.kind', k)}</label>)}
                        </fieldset>
                    </div>
                )}
            </Dialog>
            <CsvImportDialog endpoint="/hr/employees/import" hint={t('import.emp.hint')} onClose={() => setImporting(false)} open={importing} title={t('import.emp.title')} />
        </HrShell>
    );
}
