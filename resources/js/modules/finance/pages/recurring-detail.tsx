import { Link } from '@inertiajs/react';
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
import { MoneyInput } from '@/components/ui/money-input';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { RecurringSettleDialog } from '@/modules/finance/components/recurring-settle-dialog';
import {
    canSettle, DEFAULT_CURRENCY, minorToMajorText, RecurringDays, RecurringDifference, RecurringStateBadge, useDepartmentLabel, useRecurringSchedule,
    type RecurringAccount, type RecurringHistory, type RecurringItem,
} from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Item = RecurringItem & { methods: string[]; history: RecurringHistory[]; accounts: RecurringAccount[]; may: { manage: boolean } };
type EditForm = { name: string; expense_account_id: string; payee: string; amount: string; remind_days: string; end_date: string; active: boolean };

/** One recurring expense: its schedule and what is expected, every due date that was settled, and the settling and editing of it. */
export default function RecurringDetailPage({ item }: { item: Item }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const department = useDepartmentLabel();
    const schedule = useRecurringSchedule();
    const edit = useServerAction();
    const [form, setForm] = useState<EditForm | null>(null);
    const [settling, setSettling] = useState(false);
    const [badAmount, setBadAmount] = useState(false);
    const [badRemind, setBadRemind] = useState(false);
    const currency = DEFAULT_CURRENCY;
    const money = (minor: number) => format.money(minor, currency);
    const methodLabel = (m: string) => t(`fin.method.${m}` as MessageKey);
    const reload = ['item'];
    const accounts = item.accounts.some((a) => a.id === item.expense_account_id)
        ? item.accounts
        : [{ id: item.expense_account_id, code: item.account_code, name: item.account_name, department: item.department }, ...item.accounts];
    // The page does not send the business date: it is the next due date less the days to it.
    const today = item.next_due === null || item.days_to_due === null ? '' : new Date(Date.parse(item.next_due) - item.days_to_due * 86_400_000).toISOString().slice(0, 10);

    function openEdit() {
        edit.clear();
        setBadAmount(false);
        setBadRemind(false);
        setForm({
            name: item.name, expense_account_id: item.expense_account_id, payee: item.payee ?? '', amount: minorToMajorText(item.amount_minor, currency), remind_days: String(item.remind_days),
            end_date: item.end_date ?? '', active: item.active,
        });
    }

    async function saveEdit() {
        if (form === null) return;
        const minor = parseMajorToMinor(form.amount, currency);
        const remind = /^\d{1,2}$/.test(form.remind_days.trim()) ? Number(form.remind_days) : null;
        const invalidAmount = minor === null || minor < 1;
        const invalidRemind = remind === null || remind > 60;

        setBadAmount(invalidAmount);
        setBadRemind(invalidRemind);
        if (invalidAmount || invalidRemind) return;
        const done = await edit.run(`/finance/recurring/${item.id}`, {
            body: {
                name: form.name.trim(), expense_account_id: form.expense_account_id, payee: form.payee.trim() || null, amount_minor: minor, remind_days: remind,
                end_date: form.end_date || null, active: form.active, lock_version: item.lock_version,
            },
            reload,
        });
        if (done !== null) setForm(null);
    }

    const historyColumns: DataGridColumn<RecurringHistory>[] = [
        { id: 'due', label: t('fin.rec.h.due'), value: (h) => h.due_date, cell: (h) => format.date(h.due_date), rowHeader: true },
        {
            id: 'outcome', label: t('fin.rec.h.outcome'), value: (h) => h.status, filter: 'select', filterLabel: (v) => t(`fin.rec.outcome.${v}` as MessageKey),
            cell: (h) => <StatusBadge label={t(`fin.rec.outcome.${h.status}` as MessageKey)} tone={h.status === 'paid' ? 'success' : 'neutral'} />,
        },
        { id: 'paidOn', label: t('fin.rec.h.paidOn'), value: (h) => h.paid_on ?? '', cell: (h) => (h.paid_on === null ? '—' : format.date(h.paid_on)) },
        { id: 'amount', label: t('fin.rec.h.amount'), align: 'right', value: (h) => h.amount_minor ?? -1, cell: (h) => (h.amount_minor === null ? '—' : money(h.amount_minor)) },
        {
            id: 'difference', label: t('fin.rec.h.difference'), value: (h) => (h.amount_minor === null ? 0 : h.amount_minor - item.amount_minor),
            cell: (h) => (h.amount_minor === null ? <span className="text-sm text-muted-foreground">—</span> : <RecurringDifference currency={currency} minor={h.amount_minor - item.amount_minor} />),
        },
        {
            id: 'method', label: t('fin.rec.h.method'), value: (h) => h.method ?? '', searchText: (h) => `${h.method ?? ''} ${h.reference ?? ''}`,
            cell: (h) => (h.method === null ? '—' : <span className="flex flex-col"><span>{methodLabel(h.method)}</span>{h.reference !== null ? <span className="text-xs text-muted-foreground">{h.reference}</span> : null}</span>),
        },
        { id: 'note', label: t('fin.rec.h.note'), value: (h) => h.note ?? '', cell: (h) => h.note ?? '—' },
        { id: 'by', label: t('fin.rec.h.by'), value: (h) => h.by ?? '', cell: (h) => h.by ?? '—', hidden: true },
    ];

    const actions = (
        <div className="flex flex-wrap gap-2 print:hidden">
            <Button asChild variant="outline"><Link href="/finance/recurring">{t('fin.rec.back')}</Link></Button>
            {item.may.manage ? <Button onClick={openEdit} type="button" variant="outline">{t('fin.rec.edit')}</Button> : null}
            {item.may.manage && canSettle(item) ? <Button onClick={() => setSettling(true)} type="button">{t('fin.rec.settle.open')}</Button> : null}
        </div>
    );

    return (
        <FinanceShell actions={actions} description={t('fin.rec.detailDescription', { account: `${item.account_code} · ${item.account_name}`, department: department(item.department) })} title={item.name} wide>
            {item.state === 'paused' ? <Alert title={t('fin.rec.pausedTitle')} tone="info"><p>{t('fin.rec.pausedBody')}</p></Alert> : null}
            {item.state === 'finished' ? <Alert title={t('fin.rec.finishedTitle')} tone="info"><p>{t('fin.rec.finishedBody')}</p></Alert> : null}

            <section aria-label={item.name} className="grid gap-3 sm:grid-cols-3" data-testid="recurring-kpis">
                <Metric label={t('fin.rec.col.amount')} value={money(item.amount_minor)} />
                <Metric detail={<RecurringDays item={item} />} label={t('fin.rec.col.nextDue')} value={item.next_due === null ? '—' : format.date(item.next_due)} />
                <Metric label={t('inv.col.status')} value={<RecurringStateBadge state={item.state} />} />
            </section>

            <section aria-labelledby="fin-rec-facts-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-rec-facts-h">{t('fin.rec.facts')}</h2>
                <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2" data-testid="recurring-facts">
                    <div><dt className="text-xs font-medium text-muted-foreground">{t('fin.rec.col.schedule')}</dt><dd className="mt-0.5">{schedule(item)}</dd></div>
                    <div><dt className="text-xs font-medium text-muted-foreground">{t('fin.rec.col.account')}</dt><dd className="mt-0.5">{`${item.account_code} · ${item.account_name}`}</dd></div>
                    <div><dt className="text-xs font-medium text-muted-foreground">{t('fin.acc.department')}</dt><dd className="mt-0.5">{department(item.department)}</dd></div>
                    <div><dt className="text-xs font-medium text-muted-foreground">{t('fin.rec.payee')}</dt><dd className="mt-0.5">{item.payee ?? '—'}</dd></div>
                    <div><dt className="text-xs font-medium text-muted-foreground">{t('fin.rec.remind')}</dt><dd className="mt-0.5">{t('fin.rec.remindValue', { days: item.remind_days })}</dd></div>
                    <div><dt className="text-xs font-medium text-muted-foreground">{t('fin.rec.endDate')}</dt><dd className="mt-0.5">{item.end_date === null ? t('fin.rec.noEnd') : format.date(item.end_date)}</dd></div>
                    <div className="sm:col-span-2">
                        <dt className="text-xs font-medium text-muted-foreground">{t('fin.rec.upcoming')}</dt>
                        <dd className="mt-0.5">
                            {item.upcoming.length === 0 ? <span className="text-muted-foreground">{t('fin.rec.noUpcoming')}</span> : (
                                <ul className="flex flex-wrap gap-x-6 gap-y-1">
                                    {item.upcoming.map((date) => <li key={date}>{`${format.date(date)} · ${money(item.amount_minor)}`}</li>)}
                                </ul>
                            )}
                        </dd>
                    </div>
                </dl>
            </section>

            <section aria-labelledby="fin-rec-history-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-rec-history-h">{t('fin.rec.history')}</h2>
                <p className="text-sm text-muted-foreground">{t('fin.rec.historyHint')}</p>
                <DataGrid
                    caption={t('fin.rec.history')} columns={historyColumns} empty={<EmptyState title={t('fin.rec.historyEmpty')} />} getRowId={(h) => h.due_date}
                    id="fin.recurring.history" rows={item.history} testId="recurring-history"
                />
            </section>

            <RecurringSettleDialog currency={currency} item={settling ? item : null} key={settling ? item.id : 'none'} methods={item.methods} onClose={() => setSettling(false)} reload={reload} today={today} />

            <Dialog
                footer={<>
                    <Button disabled={edit.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={edit.busy} onClick={() => void saveEdit()} type="button">{t('fin.rec.editSave')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.rec.editTitle', { name: item.name })}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.rec.scheduleLocked', { schedule: schedule(item) })}</p>
                        {edit.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={edit.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={edit.fieldError('name')} field="name" label={t('fin.rec.col.name')}>
                            <Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} />
                        </FormField>
                        <FormField error={edit.fieldError('payee')} field="payee" hint={t('fin.rec.payeeHint')} label={t('fin.rec.payee')}>
                            <Input maxLength={120} onChange={(e) => setForm({ ...form, payee: e.target.value })} value={form.payee} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={edit.fieldError('expense_account_id')} field="expense_account_id" hint={t('fin.rec.accountHint')} label={t('fin.rec.col.account')}>
                                <Select onChange={(e) => setForm({ ...form, expense_account_id: e.target.value })} value={form.expense_account_id}>
                                    {accounts.map((a) => <option key={a.id} value={a.id}>{`${a.code} · ${a.name}`}</option>)}
                                </Select>
                            </FormField>
                        </div>
                        <FormField error={badAmount ? t('fin.petty.badAmount') : edit.fieldError('amount_minor')} field="amount_minor" hint={t('fin.rec.amountEditHint')} label={t('fin.rec.amountField', { currency })} required>
                            <MoneyInput onChange={(e) => { setBadAmount(false); setForm({ ...form, amount: e.target.value }); }} value={form.amount} />
                        </FormField>
                        <FormField error={badRemind ? t('fin.rec.badRemind') : edit.fieldError('remind_days')} field="remind_days" hint={t('fin.rec.remindEditHint')} label={t('fin.rec.remind')}>
                            <Input inputMode="numeric" max={60} min={0} onChange={(e) => { setBadRemind(false); setForm({ ...form, remind_days: e.target.value }); }} type="number" value={form.remind_days} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={edit.fieldError('end_date')} field="end_date" hint={t('fin.rec.endHint')} label={t('fin.rec.endDate')}>
                                <DatePicker min={`${item.start_month}-01`} onChange={(e) => setForm({ ...form, end_date: e.target.value })} value={form.end_date} />
                            </FormField>
                        </div>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />
                            {t('fin.rec.activeField')}
                        </label>
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.rec.activeHint')}</p>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
