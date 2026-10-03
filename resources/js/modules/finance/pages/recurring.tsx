import { Link } from '@inertiajs/react';
import { useState } from 'react';

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
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { MonthPicker } from '@/modules/finance/components/month-picker';
import { RecurringSettleDialog } from '@/modules/finance/components/recurring-settle-dialog';
import {
    canSettle, DEFAULT_CURRENCY, RECURRING_STATES, RecurringDays, RecurringStateBadge, useDepartmentLabel, useRecurringSchedule,
    type RecurringAccount, type RecurringItem,
} from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Overview = {
    today: string; items: RecurringItem[]; accounts: RecurringAccount[]; overdue_count: number; due_soon_count: number; expected_30_days_minor: number;
    frequencies: string[]; methods: string[]; default_remind_days: number; may: { manage: boolean };
};
type Form = {
    name: string; expense_account_id: string; payee: string; amount: string; frequency: string; due_day: string; start_month: string; end_date: string; remind_days: string;
};

/** The fixed costs that come round (rent, electricity, water, subscriptions): what is due, what is late, and what is expected in the next 30 days. */
export default function RecurringPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const department = useDepartmentLabel();
    const schedule = useRecurringSchedule();
    const create = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const [settleFor, setSettleFor] = useState<RecurringItem | null>(null);
    const [badAmount, setBadAmount] = useState(false);
    const [badDay, setBadDay] = useState(false);
    const [badRemind, setBadRemind] = useState(false);
    const currency = DEFAULT_CURRENCY;
    const thisYear = Number(overview.today.slice(0, 4));
    const money = (minor: number) => format.money(minor, currency);
    const stateLabel = (s: string) => t(`fin.rec.state.${s}` as MessageKey);
    const frequencyLabel = (f: string) => t(`fin.rec.freq.${f}` as MessageKey);
    const chosenAccount = form === null ? undefined : overview.accounts.find((a) => a.id === form.expense_account_id);
    const reload = ['overview'];

    function openNew() {
        create.clear();
        setBadAmount(false);
        setBadDay(false);
        setBadRemind(false);
        setForm({
            name: '', expense_account_id: '', payee: '', amount: '', frequency: overview.frequencies[0] ?? 'monthly', due_day: '', start_month: overview.today.slice(0, 7), end_date: '',
            remind_days: String(overview.default_remind_days),
        });
    }

    async function saveNew() {
        if (form === null) return;
        const minor = parseMajorToMinor(form.amount, currency);
        const day = /^\d{1,2}$/.test(form.due_day.trim()) ? Number(form.due_day) : null;
        const remind = form.remind_days.trim() === '' ? null : /^\d{1,2}$/.test(form.remind_days.trim()) ? Number(form.remind_days) : -1;
        const invalidDay = day === null || day < 1 || day > 31;
        const invalidRemind = remind !== null && (remind < 0 || remind > 60);
        const invalidAmount = minor === null || minor < 1;

        setBadAmount(invalidAmount);
        setBadDay(invalidDay);
        setBadRemind(invalidRemind);
        if (invalidAmount || invalidDay || invalidRemind) return;
        const done = await create.run('/finance/recurring', {
            body: {
                name: form.name.trim(), expense_account_id: form.expense_account_id, payee: form.payee.trim() || null, amount_minor: minor, frequency: form.frequency, due_day: day,
                start_month: form.start_month, end_date: form.end_date || null, remind_days: remind,
            },
            reload,
        });
        if (done !== null) setForm(null);
    }

    const columns: DataGridColumn<RecurringItem>[] = [
        {
            id: 'name', label: t('fin.rec.col.name'), value: (r) => r.name, searchText: (r) => `${r.name} ${r.payee ?? ''}`, rowHeader: true,
            cell: (r) => (
                <span className="flex flex-col">
                    <Link className="font-medium underline-offset-2 hover:underline" href={`/finance/recurring/${r.id}`}>{r.name}</Link>
                    {r.payee !== null ? <span className="text-xs text-muted-foreground">{r.payee}</span> : null}
                </span>
            ),
        },
        {
            id: 'account', label: t('fin.rec.col.account'), value: (r) => r.account_code, searchText: (r) => `${r.account_code} ${r.account_name}`,
            cell: (r) => <span className="flex flex-col"><span>{`${r.account_code} · ${r.account_name}`}</span><span className="text-xs text-muted-foreground">{department(r.department)}</span></span>,
        },
        { id: 'department', label: t('fin.acc.department'), value: (r) => r.department, filter: 'select', filterLabel: department, cell: (r) => department(r.department), hidden: true },
        { id: 'schedule', label: t('fin.rec.col.schedule'), value: (r) => r.frequency, filter: 'select', filterLabel: frequencyLabel, searchText: schedule, cell: schedule },
        { id: 'amount', label: t('fin.rec.col.amount'), align: 'right', value: (r) => r.amount_minor, cell: (r) => money(r.amount_minor) },
        {
            id: 'next', label: t('fin.rec.col.nextDue'), value: (r) => r.next_due ?? '9999-12-31',
            cell: (r) => <span className="flex flex-col"><span>{r.next_due === null ? '—' : format.date(r.next_due)}</span><RecurringDays item={r} /></span>,
        },
        {
            id: 'state', label: t('inv.col.status'), value: (r) => r.state, filter: 'select', filterLabel: stateLabel,
            cell: (r) => <RecurringStateBadge state={r.state} />,
        },
        {
            id: 'actions', label: t('inv.col.actions'),
            cell: (r) => (
                <span className="flex flex-wrap gap-2">
                    {overview.may.manage && canSettle(r) ? <Button onClick={() => setSettleFor(r)} size="sm" type="button">{t('fin.rec.settle.open')}</Button> : null}
                    <Button asChild size="sm" variant="outline"><Link href={`/finance/recurring/${r.id}`}>{t('fin.rec.open')}</Link></Button>
                </span>
            ),
        },
    ];
    const rows = [...overview.items].sort((a, b) => RECURRING_STATES.indexOf(a.state) - RECURRING_STATES.indexOf(b.state) || (a.next_due ?? '9999').localeCompare(b.next_due ?? '9999'));

    return (
        <FinanceShell actions={overview.may.manage ? <Button onClick={openNew} type="button">{t('fin.rec.new')}</Button> : undefined} description={t('fin.rec.description')} title={t('fin.rec.title')} wide>
            <p className="text-sm text-muted-foreground">{t('fin.rec.explain')}</p>

            <section aria-label={t('fin.rec.title')} className="grid gap-3 sm:grid-cols-3" data-testid="recurring-kpis">
                <Metric detail={t('fin.rec.kpi.overdueHint')} label={t('fin.rec.kpi.overdue')} value={<span className={overview.overdue_count > 0 ? 'text-danger' : undefined}>{format.number(overview.overdue_count)}</span>} />
                <Metric detail={t('fin.rec.kpi.dueSoonHint')} label={t('fin.rec.kpi.dueSoon')} value={format.number(overview.due_soon_count)} />
                <Metric detail={t('fin.rec.kpi.expectedHint')} label={t('fin.rec.kpi.expected')} value={money(overview.expected_30_days_minor)} />
            </section>

            <DataGrid caption={t('fin.rec.title')} columns={columns} empty={<EmptyState title={t('fin.rec.empty')} />} getRowId={(r) => r.id} id="fin.recurring" rows={rows} testId="recurring" />

            <RecurringSettleDialog currency={currency} item={settleFor} key={settleFor?.id ?? 'none'} methods={overview.methods} onClose={() => setSettleFor(null)} reload={reload} today={overview.today} />

            <Dialog
                footer={<>
                    <Button disabled={create.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={create.busy} onClick={() => void saveNew()} type="button">{t('fin.rec.create')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.rec.new')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.rec.newHint')}</p>
                        {create.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={create.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={create.fieldError('name')} field="name" hint={t('fin.rec.nameHint')} label={t('fin.rec.col.name')}>
                            <Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} />
                        </FormField>
                        <FormField error={create.fieldError('payee')} field="payee" hint={t('fin.rec.payeeHint')} label={t('fin.rec.payee')}>
                            <Input maxLength={120} onChange={(e) => setForm({ ...form, payee: e.target.value })} value={form.payee} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField
                                error={create.fieldError('expense_account_id')}
                                field="expense_account_id"
                                hint={chosenAccount === undefined ? t('fin.rec.accountHint') : t('fin.rec.accountDepartment', { department: department(chosenAccount.department) })}
                                label={t('fin.rec.col.account')}
                            >
                                <Select onChange={(e) => setForm({ ...form, expense_account_id: e.target.value })} value={form.expense_account_id}>
                                    <option value="">{t('fin.rec.chooseAccount')}</option>
                                    {overview.accounts.map((a) => <option key={a.id} value={a.id}>{`${a.code} · ${a.name}`}</option>)}
                                </Select>
                            </FormField>
                        </div>
                        <FormField error={badAmount ? t('fin.petty.badAmount') : create.fieldError('amount_minor')} field="amount_minor" hint={t('fin.rec.amountHint')} label={t('fin.rec.amountField', { currency })} required>
                            <Input inputMode="decimal" onChange={(e) => { setBadAmount(false); setForm({ ...form, amount: e.target.value }); }} value={form.amount} />
                        </FormField>
                        <FormField error={create.fieldError('frequency')} field="frequency" label={t('fin.rec.frequency')}>
                            <Select onChange={(e) => setForm({ ...form, frequency: e.target.value })} searchable={false} value={form.frequency}>
                                {overview.frequencies.map((f) => <option key={f} value={f}>{frequencyLabel(f)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={badDay ? t('fin.rec.badDay') : create.fieldError('due_day')} field="due_day" hint={t('fin.rec.dueDayHint')} label={t('fin.rec.dueDay')}>
                            <Input inputMode="numeric" max={31} min={1} onChange={(e) => { setBadDay(false); setForm({ ...form, due_day: e.target.value }); }} type="number" value={form.due_day} />
                        </FormField>
                        <FormField error={create.fieldError('start_month')} field="start_month" hint={t('fin.rec.startHint')} label={t('fin.rec.startMonth')}>
                            <MonthPicker onChange={(month) => setForm({ ...form, start_month: month })} value={form.start_month} years={[thisYear - 1, thisYear, thisYear + 1, thisYear + 2, thisYear + 3]} />
                        </FormField>
                        <FormField error={create.fieldError('end_date')} field="end_date" hint={t('fin.rec.endHint')} label={t('fin.rec.endDate')}>
                            <DatePicker min={`${form.start_month}-01`} onChange={(e) => setForm({ ...form, end_date: e.target.value })} value={form.end_date} />
                        </FormField>
                        <FormField error={badRemind ? t('fin.rec.badRemind') : create.fieldError('remind_days')} field="remind_days" hint={t('fin.rec.remindHint', { days: overview.default_remind_days })} label={t('fin.rec.remind')}>
                            <Input inputMode="numeric" max={60} min={0} onChange={(e) => { setBadRemind(false); setForm({ ...form, remind_days: e.target.value }); }} type="number" value={form.remind_days} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
