import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

export type JournalAccount = { key: string; code: string; name: string; baseline_code: string | null; baseline_name: string | null; changed: boolean; lock_version: number | null };
export type JournalAccounts = { accounts: JournalAccount[]; may_manage: boolean };

const FIXED = new Set(['cash', 'bank', 'petty_cash', 'accounts_receivable', 'input_tax', 'accounts_payable', 'guest_ledger', 'service_charge', 'tax_payable', 'revenue.rooms', 'revenue.laundry', 'revenue.other', 'expense.other', 'purchase_returns']);

type Form = { key: string; kind: 'fixed' | 'revenue' | 'payment'; value: string; code: string; name: string; lock_version: number | null };

/** The account codes written on the journal lines of the accounting export. The baseline is a common chart for a small hotel; the property changes what its accounting software uses. */
export function JournalAccountsPanel({ journal }: { journal: JournalAccounts }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const label = (key: string) => (FIXED.has(key) ? t(`fin.exp.acc.${key}` as MessageKey) : key.startsWith('revenue.') ? t('fin.exp.acc.revenueOf', { code: key.slice(8) }) : t('fin.exp.acc.paymentOf', { method: key.slice(8) }));
    const reload = ['journal'];

    function edit(a: JournalAccount) {
        action.clear();
        setForm({ key: a.key, kind: 'fixed', value: '', code: a.code, name: a.name, lock_version: a.lock_version });
    }

    function add() {
        action.clear();
        setForm({ key: '', kind: 'revenue', value: '', code: '', name: '', lock_version: null });
    }

    async function save() {
        if (form === null) return;
        const key = form.kind === 'fixed' ? form.key : `${form.kind}.${form.value.trim().toLowerCase()}`;
        const done = await action.run('/finance/export/accounts', { body: { key, code: form.code, name: form.name, ...(form.lock_version === null ? {} : { lock_version: form.lock_version }) }, reload });
        if (done !== null) setForm(null);
    }

    async function reset(a: JournalAccount) {
        await action.run('/finance/export/accounts/reset', { body: { key: a.key, lock_version: a.lock_version ?? 0 }, reload });
    }

    const columns: DataGridColumn<JournalAccount>[] = [
        { id: 'account', label: t('fin.exp.acc.account'), value: (a) => label(a.key), rowHeader: true },
        { id: 'code', label: t('fin.acc.code'), value: (a) => a.code },
        { id: 'name', label: t('fin.acc.name'), value: (a) => a.name },
        { id: 'state', label: t('inv.col.status'), value: (a) => (a.changed ? 'own' : 'baseline'), cell: (a) => <StatusBadge label={t(a.changed ? 'fin.exp.acc.own' : 'fin.exp.acc.baseline')} tone={a.changed ? 'info' : 'neutral'} /> },
        ...(journal.may_manage
            ? [{
                id: 'actions', label: t('inv.col.actions'),
                cell: (a: JournalAccount) => (
                    <span className="flex gap-2">
                        <Button onClick={() => edit(a)} size="sm" type="button" variant="outline">{t('fin.acc.edit')}</Button>
                        {a.changed ? <Button disabled={action.busy} onClick={() => void reset(a)} size="sm" type="button" variant="ghost">{t('fin.exp.acc.reset')}</Button> : null}
                    </span>
                ),
            }]
            : []),
    ];

    return (
        <section aria-labelledby="journal-accounts-title" className="flex flex-col gap-3" data-testid="journal-accounts">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2 className="font-semibold" id="journal-accounts-title">{t('fin.exp.acc.title')}</h2>
                    <p className="text-sm text-muted-foreground">{t('fin.exp.acc.hint')}</p>
                </div>
                {journal.may_manage ? <Button onClick={add} type="button" variant="outline">{t('fin.exp.acc.add')}</Button> : null}
            </div>
            {action.error !== null && form === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <DataGrid caption={t('fin.exp.acc.title')} columns={columns} empty={<EmptyState title={t('fin.exp.acc.title')} />} getRowId={(a) => a.key} id="fin.journal-accounts" rows={journal.accounts} testId="journal-accounts-grid" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('fin.acc.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form === null || form.kind !== 'fixed' ? t('fin.exp.acc.add') : label(form.key)}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        {form.kind !== 'fixed' ? (
                            <>
                                <FormField error={action.fieldError('key')} label={t('fin.exp.acc.kind')}>
                                    <Select onChange={(e) => setForm({ ...form, kind: e.target.value as 'revenue' | 'payment' })} value={form.kind}>
                                        <option value="revenue">{t('fin.exp.acc.kindRevenue')}</option>
                                        <option value="payment">{t('fin.exp.acc.kindPayment')}</option>
                                    </Select>
                                </FormField>
                                <FormField error={action.fieldError('key')} hint={t('fin.exp.acc.valueHint')} label={t('fin.exp.acc.value')}>
                                    <Input maxLength={20} onChange={(e) => setForm({ ...form, value: e.target.value })} value={form.value} />
                                </FormField>
                            </>
                        ) : null}
                        <FormField error={action.fieldError('code')} field="code" hint={t('fin.exp.acc.codeHint')} label={t('fin.acc.code')}>
                            <Input maxLength={30} onChange={(e) => setForm({ ...form, code: e.target.value })} value={form.code} />
                        </FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('fin.acc.name')}>
                            <Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </section>
    );
}
