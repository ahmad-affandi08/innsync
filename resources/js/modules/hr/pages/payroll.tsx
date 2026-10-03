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
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { PayComponent, PayPerson, PayrollOverview, PayrollSettings } from '@/modules/hr/lib/hr';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { minorToMajorText, parseMajorToMinor } from '@/shared/money/money';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const KINDS = ['basic', 'fixed_allowance', 'variable_allowance', 'meal', 'transport'] as const;
const BLANK_KIND = { id: '', code: '', name: '', kind: 'basic' as string, taxable: true, social: false, lock: 0 };
const BP = ['health_employee_bp', 'health_employer_bp', 'jht_employee_bp', 'jht_employer_bp', 'jp_employee_bp', 'jp_employer_bp', 'jkk_employer_bp', 'jkm_employer_bp', 'job_cost_bp', 'no_npwp_surcharge_bp'] as const;
const MONEY = ['health_cap_minor', 'jp_cap_minor', 'job_cost_cap_year_minor', 'late_minute_deduction_minor'] as const;

const percent = (bp: number) => String(bp / 100);
const toBp = (value: string) => Math.round(Number(value.replace(',', '.')) * 100);

type Form = Record<string, string> & { lock: string };

function formOf(s: PayrollSettings, currency: string): Form {
    const form: Record<string, string> = { lock: s.lock_version === null ? '' : String(s.lock_version), overtime_divisor: String(s.overtime_divisor), overtime_first: String(s.overtime_first_x100 / 100), overtime_next: String(s.overtime_next_x100 / 100), absence_divisor: String(s.absence_divisor) };
    for (const k of BP) form[k] = percent(s[k]);
    for (const k of MONEY) form[k] = minorToMajorText(s[k], currency);
    for (const [status, amount] of Object.entries(s.ptkp)) form[`ptkp_${status}`] = minorToMajorText(amount, currency);
    s.brackets.forEach((b, i) => { form[`upto_${i}`] = b.upto_minor === null ? '' : minorToMajorText(b.upto_minor, currency); form[`rate_${i}`] = percent(b.rate_bp); });
    form.count = String(s.brackets.length);

    return form as Form;
}

/** The payroll basis: what each person earns, their tax data, the kinds of earning, and the parameters of the tax and the social security. */
export default function PayrollPage({ overview }: { overview: PayrollOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [pay, setPay] = useState<{ employeeId: string; componentId: string; amount: string; from: string; reason: string } | null>(null);
    const [tax, setTax] = useState<{ employeeId: string; ptkp: string; npwp: boolean; health: boolean; employment: boolean; lock: number | null } | null>(null);
    const [kind, setKind] = useState<typeof BLANK_KIND | null>(null);
    const [settings, setSettings] = useState<Form | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const money = (minor: number) => format.money(minor, overview.currency);
    const reload = ['overview'];
    const active = overview.components.filter((c) => c.active);
    const named = (id: string) => overview.components.find((c) => c.id === id);
    const selected = overview.employees.find((e) => e.id === overview.selected) ?? null;

    function openPay(person: PayPerson) {
        action.clear();
        const first = active[0];
        setPay({ employeeId: person.id, componentId: first?.id ?? '', amount: first === undefined || person.pay[first.id] === undefined ? '' : minorToMajorText(person.pay[first.id].amount_minor, overview.currency), from: overview.today, reason: '' });
    }

    async function savePay() {
        if (pay === null) return;
        const result = await action.run('/hr/payroll/pay', { idempotencyKey: newIdempotencyKey(), body: { employee_id: pay.employeeId, component_id: pay.componentId, amount_minor: parseMajorToMinor(pay.amount, overview.currency) ?? 0, effective_from: pay.from, reason: pay.reason.trim() }, reload });

        if (result !== null) setPay(null);
    }

    async function saveTax() {
        if (tax === null) return;
        const result = await action.run('/hr/payroll/profile', { body: { employee_id: tax.employeeId, ptkp_status: tax.ptkp, has_npwp: tax.npwp, in_health: tax.health, in_employment: tax.employment, lock_version: tax.lock }, reload });

        if (result !== null) setTax(null);
    }

    async function saveKind() {
        if (kind === null) return;
        const result = kind.id === ''
            ? await action.run<PayComponent>('/hr/payroll/components', { body: { code: kind.code.trim(), name: kind.name.trim(), kind: kind.kind, taxable: kind.taxable, social_base: kind.social }, reload })
            : await action.run<PayComponent>(`/hr/payroll/components/${kind.id}`, { body: { name: kind.name.trim(), taxable: kind.taxable, social_base: kind.social, lock_version: kind.lock }, reload });

        if (result !== null) setKind(null);
    }

    async function saveSettings() {
        if (settings === null) return;
        const f = settings;
        const money = (key: string) => parseMajorToMinor(f[key] ?? '', overview.currency) ?? 0;
        const result = await action.run('/hr/payroll/settings', {
            body: {
                health_employee_bp: toBp(f.health_employee_bp), health_employer_bp: toBp(f.health_employer_bp), health_cap_minor: money('health_cap_minor'),
                jht_employee_bp: toBp(f.jht_employee_bp), jht_employer_bp: toBp(f.jht_employer_bp), jp_employee_bp: toBp(f.jp_employee_bp), jp_employer_bp: toBp(f.jp_employer_bp), jp_cap_minor: money('jp_cap_minor'),
                jkk_employer_bp: toBp(f.jkk_employer_bp), jkm_employer_bp: toBp(f.jkm_employer_bp), job_cost_bp: toBp(f.job_cost_bp), job_cost_cap_year_minor: money('job_cost_cap_year_minor'), no_npwp_surcharge_bp: toBp(f.no_npwp_surcharge_bp),
                ptkp: Object.fromEntries(overview.statuses.map((st) => [st, money(`ptkp_${st}`)])),
                brackets: Array.from({ length: Number(f.count) }, (_, i) => ({ upto_minor: i === Number(f.count) - 1 || f[`upto_${i}`] === '' ? null : money(`upto_${i}`), rate_bp: toBp(f[`rate_${i}`]) })),
                overtime_divisor: Number(f.overtime_divisor), overtime_first_x100: toBp(f.overtime_first), overtime_next_x100: toBp(f.overtime_next), absence_divisor: Number(f.absence_divisor), late_minute_deduction_minor: money('late_minute_deduction_minor'),
                lock_version: f.lock === '' ? null : Number(f.lock),
            },
            reload,
        });

        if (result !== null) setSettings(null);
    }

    const setField = (key: string, value: string) => settings !== null && setSettings({ ...settings, [key]: value });
    const field = (key: string, text: string, hint?: string, step = '0.01') => (
        <FormField error={action.fieldError(key)} field={key} hint={hint} label={text}><Input inputMode="decimal" onChange={(e) => setField(key, e.target.value)} step={step} value={settings?.[key] ?? ''} /></FormField>
    );

    const peopleColumns: DataGridColumn<PayPerson>[] = [
        { id: 'name', label: t('hr.col.name'), value: (r) => r.name, rowHeader: true, cell: (r) => <span>{r.name}<span className="block text-xs text-muted-foreground">{r.number} · {label('hr.department', r.department)}</span></span> },
        { id: 'monthly', label: t('hr.pay.monthly'), align: 'right', value: (r) => r.monthly_minor, cell: (r) => (r.monthly_minor === 0 ? <span className="text-warning">{t('hr.pay.notSet')}</span> : money(r.monthly_minor)) },
        { id: 'perDay', label: t('hr.pay.perDay'), align: 'right', value: (r) => r.per_day_minor, cell: (r) => money(r.per_day_minor) },
        { id: 'tax', label: t('hr.pay.taxStatus'), value: (r) => r.profile?.ptkp_status ?? '', cell: (r) => (r.profile === null ? <span className="text-warning">{t('hr.pay.notSet')}</span> : <span>{r.profile.ptkp_status}<span className="block text-xs text-muted-foreground">{[r.profile.has_npwp ? t('hr.pay.npwp') : t('hr.pay.noNpwp'), r.profile.in_health ? t('hr.pay.inHealth') : null, r.profile.in_employment ? t('hr.pay.inEmployment') : null].filter((x) => x !== null).join(' · ')}</span></span>) },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (r) => (
                <span className="flex flex-wrap gap-2">
                    <Button disabled={active.length === 0} onClick={() => openPay(r)} size="sm" type="button" variant="outline">{t('hr.pay.setPay')}</Button>
                    <Button onClick={() => { action.clear(); setTax({ employeeId: r.id, ptkp: r.profile?.ptkp_status ?? 'TK0', npwp: r.profile?.has_npwp ?? true, health: r.profile?.in_health ?? true, employment: r.profile?.in_employment ?? true, lock: r.profile?.lock_version ?? null }); }} size="sm" type="button" variant="outline">{t('hr.pay.setTax')}</Button>
                    <Button onClick={() => router.get('/hr/payroll', { employee: r.id }, { preserveScroll: true })} size="sm" type="button" variant="outline">{t('hr.pay.history')}</Button>
                </span>
            ),
        },
    ];
    const kindColumns: DataGridColumn<PayComponent>[] = [
        { id: 'code', label: t('hr.shift.code'), value: (k) => k.code, rowHeader: true },
        { id: 'name', label: t('hr.shift.name'), value: (k) => k.name },
        { id: 'kind', label: t('hr.pay.kind'), value: (k) => k.kind, cell: (k) => <span>{label('hr.pay.kinds', k.kind)}<span className="block text-xs text-muted-foreground">{label('hr.pay.basis', k.basis)}</span></span> },
        { id: 'taxable', label: t('hr.pay.taxable'), value: (k) => (k.taxable ? 1 : 0), cell: (k) => (k.taxable ? t('hr.leave.yes') : t('hr.leave.no')) },
        { id: 'social', label: t('hr.pay.socialBase'), value: (k) => (k.social_base ? 1 : 0), cell: (k) => (k.social_base ? t('hr.leave.yes') : t('hr.leave.no')) },
        { id: 'status', label: t('hr.col.status'), value: (k) => (k.active ? 'active' : 'retired'), cell: (k) => <StatusBadge label={k.active ? t('hr.shift.active') : t('hr.shift.retired')} tone={k.active ? 'success' : 'neutral'} /> },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (k) => (
                <span className="flex gap-2">
                    <Button onClick={() => { action.clear(); setKind({ id: k.id, code: k.code, name: k.name, kind: k.kind, taxable: k.taxable, social: k.social_base, lock: k.lock_version }); }} size="sm" type="button" variant="outline">{t('hr.edit')}</Button>
                    <Button disabled={action.busy} onClick={() => void action.run(`/hr/payroll/components/${k.id}/active`, { body: { active: !k.active, lock_version: k.lock_version }, reload })} size="sm" type="button" variant="outline">{k.active ? t('hr.shift.retire') : t('hr.shift.resume')}</Button>
                </span>
            ),
        },
    ];
    const s = overview.settings;

    return (
        <HrShell description={t('hr.pay.description')} title={t('hr.pay.title')}>
            {action.error !== null && pay === null && tax === null && kind === null && settings === null ? failure : null}
            {overview.components.length === 0 ? <Alert title={t('hr.pay.noKinds')} tone="warning" /> : null}
            <Tabs defaultValue="people">
                <TabsList aria-label={t('hr.pay.title')}>
                    <TabsTrigger value="people">{t('hr.pay.peopleTab')}</TabsTrigger>
                    <TabsTrigger value="kinds">{t('hr.pay.kindsTab')}</TabsTrigger>
                    <TabsTrigger value="parameters">{t('hr.pay.parametersTab')}</TabsTrigger>
                </TabsList>
                <TabsContent className="flex flex-col gap-3" value="people">
                    <DataGrid caption={t('hr.pay.peopleTab')} columns={peopleColumns} empty={<EmptyState illustration="checklist" title={t('hr.pay.noPeople')} />} getRowId={(r) => r.id} id="hr.pay.people" rows={overview.employees} testId="hr-pay-people" />
                    {selected !== null ? (
                        <section className="flex flex-col gap-2 text-sm" data-testid="hr-pay-history">
                            <h2 className="font-semibold">{t('hr.pay.historyOf', { name: selected.name })}</h2>
                            {overview.history.length === 0 ? <p className="text-muted-foreground">{t('hr.pay.noHistory')}</p> : (
                                <ul className="flex flex-col gap-1">{overview.history.map((h) => <li key={`${h.component_id}-${h.effective_from}`}>{format.date(h.effective_from)} · {named(h.component_id)?.name ?? h.component_id} · {money(h.amount_minor)} · {h.reason}</li>)}</ul>
                            )}
                        </section>
                    ) : null}
                </TabsContent>
                <TabsContent className="flex flex-col gap-3" value="kinds">
                    <div className="flex gap-2">
                        <Button onClick={() => { action.clear(); setKind({ ...BLANK_KIND }); }} type="button">{t('hr.pay.addKind')}</Button>
                        {overview.components.length === 0 ? <Button disabled={action.busy} onClick={() => void action.run('/hr/payroll/components/baseline', { body: {}, reload })} type="button" variant="outline">{t('hr.pay.baseline')}</Button> : null}
                    </div>
                    <p className="text-xs text-muted-foreground">{t('hr.pay.kindsHint')}</p>
                    <DataGrid caption={t('hr.pay.kindsTab')} columns={kindColumns} empty={<EmptyState illustration="checklist" title={t('hr.pay.noKinds')} />} getRowId={(k) => k.id} id="hr.pay.kinds" rows={overview.components} testId="hr-pay-kinds" />
                </TabsContent>
                <TabsContent className="flex flex-col gap-3" value="parameters">
                    <div className="flex gap-2"><Button onClick={() => { action.clear(); setSettings(formOf(s, overview.currency)); }} type="button">{t('hr.pay.editParameters')}</Button></div>
                    {s.is_baseline ? <Alert title={t('hr.pay.parametersBaseline')} tone="warning" /> : null}
                    <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2" data-testid="hr-pay-parameters">
                        <dt>{t('hr.pay.health')}</dt><dd>{t('hr.pay.shares', { employee: percent(s.health_employee_bp), employer: percent(s.health_employer_bp) })} · {t('hr.pay.cap', { amount: money(s.health_cap_minor) })}</dd>
                        <dt>{t('hr.pay.jht')}</dt><dd>{t('hr.pay.shares', { employee: percent(s.jht_employee_bp), employer: percent(s.jht_employer_bp) })}</dd>
                        <dt>{t('hr.pay.jp')}</dt><dd>{t('hr.pay.shares', { employee: percent(s.jp_employee_bp), employer: percent(s.jp_employer_bp) })} · {t('hr.pay.cap', { amount: money(s.jp_cap_minor) })}</dd>
                        <dt>{t('hr.pay.jkkJkm')}</dt><dd>{percent(s.jkk_employer_bp)}% + {percent(s.jkm_employer_bp)}%</dd>
                        <dt>{t('hr.pay.jobCost')}</dt><dd>{percent(s.job_cost_bp)}% · {t('hr.pay.capYear', { amount: money(s.job_cost_cap_year_minor) })}</dd>
                        <dt>{t('hr.pay.noNpwpSurcharge')}</dt><dd>{percent(s.no_npwp_surcharge_bp)}%</dd>
                        <dt>{t('hr.pay.ptkp')}</dt><dd>{overview.statuses.map((st) => `${st} ${money(s.ptkp[st] ?? 0)}`).join(' · ')}</dd>
                        <dt>{t('hr.pay.brackets')}</dt><dd>{s.brackets.map((b) => `${b.upto_minor === null ? t('hr.pay.above') : money(b.upto_minor)}: ${percent(b.rate_bp)}%`).join(' · ')}</dd>
                        <dt>{t('hr.pay.overtimeRule')}</dt><dd>{t('hr.pay.overtimeLine', { divisor: s.overtime_divisor, first: s.overtime_first_x100 / 100, next: s.overtime_next_x100 / 100 })}</dd>
                        <dt>{t('hr.pay.absenceRule')}</dt><dd>{t('hr.pay.absenceLine', { days: s.absence_divisor, late: money(s.late_minute_deduction_minor) })}</dd>
                    </dl>
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setPay(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={pay?.componentId === '' || parseMajorToMinor(pay?.amount ?? '', overview.currency) === null || pay?.reason.trim() === '' || pay?.from === ''} loading={action.busy} onClick={() => void savePay()} type="button">{t('hr.pay.savePay')}</Button></>}
                onClose={() => setPay(null)}
                open={pay !== null}
                title={t('hr.pay.setPay')}
            >
                {pay !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <div className="sm:col-span-2"><FormField error={action.fieldError('component_id')} field="component_id" label={t('hr.pay.kind')}><Select onChange={(e) => setPay({ ...pay, componentId: e.target.value, amount: ((m) => (m === undefined ? '' : minorToMajorText(m, overview.currency)))(overview.employees.find((x) => x.id === pay.employeeId)?.pay[e.target.value]?.amount_minor) })} value={pay.componentId}>{active.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}</Select></FormField></div>
                        <FormField error={action.fieldError('amount_minor')} field="amount_minor" hint={t('hr.pay.amountHint', { basis: label('hr.pay.basis', named(pay.componentId)?.basis ?? 'monthly') })} label={t('hr.pay.amount')}><Input inputMode="numeric" onChange={(e) => setPay({ ...pay, amount: e.target.value })} value={pay.amount} /></FormField>
                        <FormField error={action.fieldError('effective_from')} field="effective_from" hint={t('hr.pay.fromHint')} label={t('hr.pay.from')}><DatePicker min={overview.earliest} onChange={(e) => setPay({ ...pay, from: e.target.value })} value={pay.from} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setPay({ ...pay, reason: e.target.value })} value={pay.reason} /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setTax(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveTax()} type="button">{t('hr.pay.saveTax')}</Button></>}
                onClose={() => setTax(null)}
                open={tax !== null}
                title={t('hr.pay.setTax')}
            >
                {tax !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <FormField error={action.fieldError('ptkp_status')} field="ptkp_status" hint={t('hr.pay.ptkpHint')} label={t('hr.pay.taxStatus')}><Select onChange={(e) => setTax({ ...tax, ptkp: e.target.value })} value={tax.ptkp}>{overview.statuses.map((st) => <option key={st} value={st}>{st}</option>)}</Select></FormField>
                        <label className="flex items-center gap-2 text-sm"><input checked={tax.npwp} onChange={(e) => setTax({ ...tax, npwp: e.target.checked })} type="checkbox" />{t('hr.pay.hasNpwp')}</label>
                        <label className="flex items-center gap-2 text-sm"><input checked={tax.health} onChange={(e) => setTax({ ...tax, health: e.target.checked })} type="checkbox" />{t('hr.pay.inHealthLong')}</label>
                        <label className="flex items-center gap-2 text-sm"><input checked={tax.employment} onChange={(e) => setTax({ ...tax, employment: e.target.checked })} type="checkbox" />{t('hr.pay.inEmploymentLong')}</label>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setKind(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={kind?.name.trim() === '' || (kind?.id === '' && kind?.code.trim() === '')} loading={action.busy} onClick={() => void saveKind()} type="button">{t('hr.pay.saveKind')}</Button></>}
                onClose={() => setKind(null)}
                open={kind !== null}
                title={kind?.id === '' ? t('hr.pay.addKind') : t('hr.pay.editKind')}
            >
                {kind !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('code')} field="code" label={t('hr.shift.code')}><Input disabled={kind.id !== ''} maxLength={8} onChange={(e) => setKind({ ...kind, code: e.target.value })} value={kind.code} /></FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('hr.shift.name')}><Input maxLength={60} onChange={(e) => setKind({ ...kind, name: e.target.value })} value={kind.name} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('kind')} field="kind" hint={label('hr.pay.basisHint', kind.kind)} label={t('hr.pay.kind')}><Select disabled={kind.id !== ''} onChange={(e) => setKind({ ...kind, kind: e.target.value })} value={kind.kind}>{KINDS.map((k) => <option key={k} value={k}>{label('hr.pay.kinds', k)}</option>)}</Select></FormField></div>
                        <label className="flex items-center gap-2 text-sm"><input checked={kind.taxable} onChange={(e) => setKind({ ...kind, taxable: e.target.checked })} type="checkbox" />{t('hr.pay.taxable')}</label>
                        <label className="flex items-center gap-2 text-sm"><input checked={kind.social} onChange={(e) => setKind({ ...kind, social: e.target.checked })} type="checkbox" />{t('hr.pay.socialBase')}</label>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setSettings(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveSettings()} type="button">{t('hr.pay.saveParameters')}</Button></>}
                onClose={() => setSettings(null)}
                open={settings !== null}
                className="sm:max-w-3xl"
                title={t('hr.pay.editParameters')}
            >
                {settings !== null && (
                    <div className="grid gap-3 sm:grid-cols-3">
                        {failure !== null ? <div className="sm:col-span-3">{failure}</div> : null}
                        <p className="text-xs text-muted-foreground sm:col-span-3">{t('hr.pay.percentHint')}</p>
                        {field('health_employee_bp', t('hr.pay.healthEmployee'))}
                        {field('health_employer_bp', t('hr.pay.healthEmployer'))}
                        {field('health_cap_minor', t('hr.pay.healthCap'), undefined, '1')}
                        {field('jht_employee_bp', t('hr.pay.jhtEmployee'))}
                        {field('jht_employer_bp', t('hr.pay.jhtEmployer'))}
                        <span />
                        {field('jp_employee_bp', t('hr.pay.jpEmployee'))}
                        {field('jp_employer_bp', t('hr.pay.jpEmployer'))}
                        {field('jp_cap_minor', t('hr.pay.jpCap'), undefined, '1')}
                        {field('jkk_employer_bp', t('hr.pay.jkk'))}
                        {field('jkm_employer_bp', t('hr.pay.jkm'))}
                        <span />
                        {field('job_cost_bp', t('hr.pay.jobCostShare'))}
                        {field('job_cost_cap_year_minor', t('hr.pay.jobCostCap'), undefined, '1')}
                        {field('no_npwp_surcharge_bp', t('hr.pay.noNpwpSurcharge'), t('hr.pay.noNpwpHint'))}
                        {overview.statuses.map((st) => <div key={st}>{field(`ptkp_${st}`, `PTKP ${st}`, undefined, '1')}</div>)}
                        {Array.from({ length: Number(settings.count) }, (_, i) => (
                            <div className="grid gap-3 sm:col-span-3 sm:grid-cols-2" key={i}>
                                {i === Number(settings.count) - 1 ? <FormField label={t('hr.pay.bracketCeiling', { n: i + 1 })}><Input disabled value={t('hr.pay.above')} /></FormField> : field(`upto_${i}`, t('hr.pay.bracketCeiling', { n: i + 1 }), undefined, '1')}
                                {field(`rate_${i}`, t('hr.pay.bracketRate', { n: i + 1 }))}
                            </div>
                        ))}
                        <div className="flex gap-2 sm:col-span-3">
                            <Button disabled={Number(settings.count) >= 8} onClick={() => setSettings({ ...settings, count: String(Number(settings.count) + 1), [`upto_${Number(settings.count)}`]: '', [`rate_${Number(settings.count)}`]: '0' })} size="sm" type="button" variant="outline">{t('hr.pay.addBracket')}</Button>
                            <Button disabled={Number(settings.count) <= 1} onClick={() => setSettings({ ...settings, count: String(Number(settings.count) - 1) })} size="sm" type="button" variant="outline">{t('hr.pay.removeBracket')}</Button>
                        </div>
                        {field('overtime_divisor', t('hr.pay.overtimeDivisor'), undefined, '1')}
                        {field('overtime_first', t('hr.pay.overtimeFirst'), t('hr.pay.multipleHint'))}
                        {field('overtime_next', t('hr.pay.overtimeNext'), t('hr.pay.multipleHint'))}
                        {field('absence_divisor', t('hr.pay.absenceDivisor'), undefined, '1')}
                        {field('late_minute_deduction_minor', t('hr.pay.lateDeduction'), t('hr.pay.lateHint'), '1')}
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
