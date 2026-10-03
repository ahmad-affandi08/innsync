import { router } from '@inertiajs/react';
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
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { RosterOverview, ShiftPattern } from '@/modules/hr/lib/hr';
import { apiRequest } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const BLANK = { id: '', code: '', name: '', off: false, starts: '07:00', ends: '15:00', starts2: '', ends2: '', lock: 0 };
const addDays = (date: string, n: number) => new Date(Date.parse(`${date}T00:00:00Z`) + n * 86_400_000).toISOString().slice(0, 10);
const hours = (minutes: number) => `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;

/** The roster: who works which shift on which day, the shifts themselves, and what each department needs on a shift. */
export default function RosterPage({ overview }: { overview: RosterOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<typeof BLANK | null>(null);
    const [minDept, setMinDept] = useState(overview.department ?? overview.departments[0] ?? 'general');
    const [mins, setMins] = useState<Record<string, string>>({});
    const [notice, setNotice] = useState<string | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const active = overview.patterns.filter((p) => p.active);
    const worked = overview.patterns.filter((p) => !p.off && p.active);
    const span = Math.round((Date.parse(overview.to) - Date.parse(overview.from)) / 86_400_000) + 1;
    const go = (from: string, to: string, department: string | null = overview.department) => router.get('/hr/roster', { from, to, ...(department === null ? {} : { department }) });
    const timesOf = (p: ShiftPattern) => (p.off ? '—' : `${p.starts_at}–${p.ends_at}${p.starts2_at !== null ? ` · ${p.starts2_at}–${p.ends2_at}` : ''}`);
    const minimumsFor = (dept: string) => Object.fromEntries(worked.map((p) => [p.id, String(overview.minimums.find((m) => m.department === dept && m.pattern_id === p.id)?.minimum ?? 0)]));

    async function put(employeeId: string, date: string, patternId: string) {
        const result = await action.run('/hr/roster/assign', { body: { employee_ids: [employeeId], dates: [date], pattern_id: patternId === '' ? null : patternId } });

        if (result !== null) router.reload({ only: ['overview'] });
    }

    async function copyWeek() {
        const result = await action.run<{ copied: number; skipped: number }>('/hr/roster/copy', { body: { from_start: addDays(overview.from, -7), to_start: overview.from, department: overview.department }, reload: ['overview'] });

        if (result !== null) setNotice(t('hr.roster.copied', { copied: result.copied, skipped: result.skipped }));
    }

    async function savePattern() {
        if (form === null) return;
        const times = { starts_at: form.off ? null : form.starts, ends_at: form.off ? null : form.ends, starts2_at: form.off || form.starts2 === '' ? null : form.starts2, ends2_at: form.off || form.ends2 === '' ? null : form.ends2 };
        const result = form.id === ''
            ? await action.run<ShiftPattern>('/hr/shift-patterns', { body: { code: form.code.trim(), name: form.name.trim(), off: form.off, ...times }, reload: ['overview'] })
            : await action.run<ShiftPattern>(`/hr/shift-patterns/${form.id}`, { body: { name: form.name.trim(), ...times, lock_version: form.lock }, reload: ['overview'] });

        if (result !== null) setForm(null);
    }

    async function saveMinimums() {
        const result = await action.run('/hr/roster/minimums', { body: { department: minDept, minimums: Object.fromEntries(Object.entries(mins).map(([k, v]) => [k, Number(v)])) }, reload: ['overview'] });

        if (result !== null) setNotice(t('hr.roster.minimumsSaved'));
    }

    const patternColumns: DataGridColumn<ShiftPattern>[] = [
        { id: 'code', label: t('hr.shift.code'), value: (p) => p.code, rowHeader: true },
        { id: 'name', label: t('hr.shift.name'), value: (p) => p.name },
        { id: 'times', label: t('hr.shift.times'), value: (p) => timesOf(p) },
        { id: 'hours', label: t('hr.shift.hours'), align: 'right', value: (p) => p.minutes, cell: (p) => (p.off ? '—' : hours(p.minutes)) },
        { id: 'status', label: t('hr.col.status'), value: (p) => (p.active ? 'active' : 'retired'), cell: (p) => <StatusBadge label={t(p.active ? 'hr.shift.active' : 'hr.shift.retired')} tone={p.active ? 'success' : 'neutral'} /> },
        { id: 'edit', label: '', value: () => '', sortable: false, cell: (p) => overview.may.roster ? (
            <span className="flex gap-1">
                {p.active ? <Button onClick={() => { action.clear(); setForm({ id: p.id, code: p.code, name: p.name, off: p.off, starts: p.starts_at ?? '07:00', ends: p.ends_at ?? '15:00', starts2: p.starts2_at ?? '', ends2: p.ends2_at ?? '', lock: p.lock_version }); }} size="sm" type="button" variant="outline">{t('hr.edit')}</Button> : null}
                <Button onClick={() => void action.run(`/hr/shift-patterns/${p.id}/active`, { body: { active: !p.active, lock_version: p.lock_version }, reload: ['overview'] })} size="sm" type="button" variant="outline">{t(p.active ? 'hr.shift.retire' : 'hr.shift.resume')}</Button>
            </span>
        ) : null },
    ];

    const rosterTab = (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end gap-2" data-testid="hr-roster-controls">
                <FormField label={t('hr.col.department')}><Select onChange={(e) => go(overview.from, overview.to, e.target.value === '' ? null : e.target.value)} value={overview.department ?? ''}><option value="">{t('hr.roster.allDepartments')}</option>{overview.departments.map((d) => <option key={d} value={d}>{label('hr.department', d)}</option>)}</Select></FormField>
                <Button onClick={() => go(addDays(overview.from, -span), addDays(overview.to, -span))} type="button" variant="outline">{t('hr.roster.previous')}</Button>
                <Button onClick={() => go(addDays(overview.from, span), addDays(overview.to, span))} type="button" variant="outline">{t('hr.roster.next')}</Button>
                <Button onClick={() => { const d = new Date(Date.parse(`${overview.business_date}T00:00:00Z`)); const m = (d.getUTCDay() + 6) % 7; const from = addDays(overview.business_date, -m); go(from, addDays(from, 6)); }} type="button" variant="outline">{t('hr.roster.week')}</Button>
                <Button onClick={() => { const first = `${overview.business_date.slice(0, 8)}01`; const d = new Date(Date.parse(`${first}T00:00:00Z`)); d.setUTCMonth(d.getUTCMonth() + 1); go(first, addDays(d.toISOString().slice(0, 10), -1)); }} type="button" variant="outline">{t('hr.roster.month')}</Button>
                {overview.may.roster && span === 7 ? <Button disabled={action.busy} onClick={() => void copyWeek()} type="button" variant="outline">{t('hr.roster.copy')}</Button> : null}
            </div>
            <p className="text-sm text-muted-foreground">{format.date(overview.from)} – {format.date(overview.to)}</p>
            {notice !== null ? <Alert title={notice} tone="success" /> : null}
            {overview.shortages.length > 0 ? (
                <div className="flex flex-col gap-1 border border-border bg-surface p-3" data-testid="hr-shortages">
                    <h3 className="text-sm font-semibold">{t('hr.roster.shortages', { count: overview.shortages.length })}</h3>
                    <ul className="text-sm">{overview.shortages.slice(0, 20).map((s) => <li key={`${s.date}-${s.department}-${s.pattern_id}`}>{format.date(s.date)} · {label('hr.department', s.department)} · {s.code}: {t('hr.roster.haveNeed', { have: s.have, need: s.need })}</li>)}</ul>
                </div>
            ) : null}
            {overview.employees.length === 0 ? <EmptyState illustration="checklist" title={t('hr.roster.noEmployees')} /> : (
                <div className="overflow-x-auto border border-border" data-testid="hr-roster-grid">
                    <table className="w-full min-w-max border-collapse text-sm">
                        <thead><tr className="bg-primary text-primary-foreground"><th className="sticky left-0 bg-primary p-2 text-left">{t('hr.col.name')}</th>{overview.days.map((d) => <th className="p-2 text-center font-medium" key={d}>{label('mtc.duty.weekday', String(((new Date(`${d}T00:00:00Z`).getUTCDay() + 6) % 7) + 1)).slice(0, 3)}<span className="block text-xs font-normal">{format.date(d, 'short')}</span></th>)}</tr></thead>
                        <tbody>
                            {overview.employees.map((e) => (
                                <tr className="border-t border-border" key={e.id}>
                                    <th className="sticky left-0 bg-surface p-2 text-left font-normal" scope="row">{e.name}<span className="block text-xs text-muted-foreground">{e.number} · {label('hr.department', e.department)}</span></th>
                                    {overview.days.map((d) => {
                                        const cell = overview.cells[e.id]?.[d];
                                        const leave = overview.leave[e.id]?.[d];
                                        const locked = !overview.may.roster || d < overview.business_date || d < e.joined_on || (e.contract_end_on !== null && d > e.contract_end_on);

                                        return (
                                            <td className="p-1 text-center" key={d}>
                                                {leave !== undefined ? <span className="text-info" title={t('hr.roster.onLeave')}>{leave}</span> : locked ? <span className={cell?.off ? 'text-muted-foreground' : ''}>{cell?.code ?? '·'}</span> : (
                                                    <select aria-label={`${e.name} ${d}`} className="min-h-9 w-16 border border-input bg-surface px-1 text-sm" disabled={action.busy} onChange={(ev) => void put(e.id, d, ev.target.value)} value={cell?.pattern_id ?? ''}>
                                                        <option value="">·</option>
                                                        {active.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}
                                                        {cell !== undefined && !active.some((p) => p.id === cell.pattern_id) ? <option value={cell.pattern_id}>{cell.code}</option> : null}
                                                    </select>
                                                )}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <p className="text-xs text-muted-foreground">{t('hr.roster.legend', { shifts: active.map((p) => `${p.code} ${timesOf(p)}`).join(' · ') })}</p>
        </div>
    );

    return (
        <HrShell description={t('hr.roster.description')} title={t('hr.roster.title')}>
            {failure !== null && form === null ? failure : null}
            <Tabs defaultValue="roster">
                <TabsList aria-label={t('hr.roster.title')}>
                    <TabsTrigger value="roster">{t('hr.roster.tab')}</TabsTrigger>
                    <TabsTrigger value="shifts">{t('hr.roster.shiftsTab')}</TabsTrigger>
                </TabsList>
                <TabsContent className="flex flex-col gap-3" value="roster">{rosterTab}</TabsContent>
                <TabsContent className="flex flex-col gap-4" value="shifts">
                    <div className="flex flex-wrap gap-2">
                        {overview.may.roster ? <Button onClick={() => { action.clear(); setForm({ ...BLANK }); }} type="button">{t('hr.shift.new')}</Button> : null}
                        {overview.may.roster && overview.patterns.length === 0 ? <Button disabled={action.busy} onClick={() => void apiRequest('/hr/shift-patterns/baseline', { method: 'POST' }).then(() => router.reload({ only: ['overview'] }))} type="button" variant="outline">{t('hr.shift.baseline')}</Button> : null}
                    </div>
                    <DataGrid caption={t('hr.roster.shiftsTab')} columns={patternColumns} empty={<EmptyState illustration="checklist" title={t('hr.shift.none')} />} getRowId={(p) => p.id} id="hr.shifts" rows={overview.patterns} testId="hr-shifts" />
                    <section aria-labelledby="hr-min-h" className="flex flex-col gap-2 border border-border bg-surface p-3" data-testid="hr-minimums">
                        <h3 className="font-semibold" id="hr-min-h">{t('hr.roster.minimums')}</h3>
                        <p className="text-xs text-muted-foreground">{t('hr.roster.minimumsHint')}</p>
                        <FormField label={t('hr.col.department')}><Select onChange={(e) => { setMinDept(e.target.value); setMins(minimumsFor(e.target.value)); }} value={minDept}>{overview.departments.map((d) => <option key={d} value={d}>{label('hr.department', d)}</option>)}</Select></FormField>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {worked.map((p) => <FormField error={action.fieldError('minimums')} key={p.id} label={`${p.code} · ${p.name}`}><Input inputMode="numeric" onChange={(e) => setMins({ ...(Object.keys(mins).length === 0 ? minimumsFor(minDept) : mins), [p.id]: e.target.value })} value={(Object.keys(mins).length === 0 ? minimumsFor(minDept) : mins)[p.id] ?? '0'} /></FormField>)}
                        </div>
                        {overview.may.roster ? <div><Button disabled={action.busy || worked.length === 0} onClick={() => void saveMinimums()} size="sm" type="button">{t('hr.roster.saveMinimums')}</Button></div> : null}
                    </section>
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form?.name.trim() === '' || (form?.id === '' && form.code.trim() === '')} loading={action.busy} onClick={() => void savePattern()} type="button">{t('hr.shift.save')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form?.id === '' ? t('hr.shift.new') : t('hr.edit')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('code')} field="code" label={t('hr.shift.code')}><Input disabled={form.id !== ''} maxLength={8} onChange={(e) => setForm({ ...form, code: e.target.value })} value={form.code} /></FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('hr.shift.name')}><Input maxLength={40} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                        {form.id === '' ? <label className="flex items-center gap-2 text-sm sm:col-span-2"><input checked={form.off} onChange={(e) => setForm({ ...form, off: e.target.checked })} type="checkbox" />{t('hr.shift.off')}</label> : null}
                        {!form.off ? (
                            <>
                                <FormField error={action.fieldError('starts_at')} field="starts_at" hint={t('hr.shift.timeHint')} label={t('hr.shift.starts')}><Input maxLength={5} onChange={(e) => setForm({ ...form, starts: e.target.value })} placeholder="07:00" value={form.starts} /></FormField>
                                <FormField error={action.fieldError('ends_at')} field="ends_at" hint={t('hr.shift.nightHint')} label={t('hr.shift.ends')}><Input maxLength={5} onChange={(e) => setForm({ ...form, ends: e.target.value })} placeholder="15:00" value={form.ends} /></FormField>
                                <FormField error={action.fieldError('starts2_at')} field="starts2_at" hint={t('hr.shift.splitHint')} label={t('hr.shift.starts2')}><Input maxLength={5} onChange={(e) => setForm({ ...form, starts2: e.target.value })} value={form.starts2} /></FormField>
                                <FormField error={action.fieldError('ends2_at')} field="ends2_at" label={t('hr.shift.ends2')}><Input maxLength={5} onChange={(e) => setForm({ ...form, ends2: e.target.value })} value={form.ends2} /></FormField>
                            </>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
