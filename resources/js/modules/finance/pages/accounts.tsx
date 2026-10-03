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
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Account = { id: string; code: string; name: string; department: string; category: string; is_active: boolean; lock_version: number };
type Overview = { accounts: Account[]; departments: string[]; categories: string[]; may: { manage: boolean } };
type Form = { id: string | null; code: string; name: string; department: string; category: string; active: boolean; lock_version: number };

/** The expense accounts a payable is classified under. */
export default function AccountsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const department = (d: string) => t(`inv.dept.${d}` as MessageKey);
    const category = (c: string) => t(`fin.cat.${c}` as MessageKey);
    const reload = ['overview'];

    function openNew() {
        action.clear();
        setForm({ id: null, code: '', name: '', department: overview.departments[0] ?? '', category: overview.categories[0] ?? '', active: true, lock_version: 0 });
    }

    function openEdit(a: Account) {
        action.clear();
        setForm({ id: a.id, code: a.code, name: a.name, department: a.department, category: a.category, active: a.is_active, lock_version: a.lock_version });
    }

    async function save() {
        if (form === null) return;
        const done = form.id === null
            ? await action.run('/finance/accounts', { body: { code: form.code, name: form.name, department: form.department, category: form.category }, reload })
            : await action.run(`/finance/accounts/${form.id}`, { body: { name: form.name, department: form.department, category: form.category, active: form.active, lock_version: form.lock_version }, reload });
        if (done !== null) setForm(null);
    }

    const columns: DataGridColumn<Account>[] = [
        { id: 'code', label: t('fin.acc.code'), value: (a) => a.code, rowHeader: true },
        { id: 'name', label: t('fin.acc.name'), value: (a) => a.name },
        { id: 'department', label: t('fin.acc.department'), value: (a) => a.department, filter: 'select', filterLabel: department, cell: (a) => department(a.department) },
        { id: 'category', label: t('fin.acc.category'), value: (a) => a.category, filter: 'select', filterLabel: category, cell: (a) => category(a.category) },
        {
            id: 'state', label: t('inv.col.status'), value: (a) => (a.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: (v) => t(v === 'active' ? 'fin.acc.active' : 'fin.acc.inactive'),
            cell: (a) => <StatusBadge label={t(a.is_active ? 'fin.acc.active' : 'fin.acc.inactive')} tone={a.is_active ? 'success' : 'neutral'} />,
        },
        ...(overview.may.manage ? [{ id: 'actions', label: t('inv.col.actions'), cell: (a: Account) => <Button onClick={() => openEdit(a)} size="sm" type="button" variant="outline">{t('fin.acc.edit')}</Button> }] : []),
    ];

    return (
        <FinanceShell actions={overview.may.manage ? <Button onClick={openNew} type="button">{t('fin.acc.new')}</Button> : undefined} description={t('fin.acc.description')} title={t('fin.acc.title')} wide>
            <DataGrid caption={t('fin.acc.title')} columns={columns} empty={<EmptyState title={t('fin.acc.empty')} />} getRowId={(a) => a.id} id="fin.accounts" rows={overview.accounts} testId="accounts" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('fin.acc.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form?.id === null || form === null ? t('fin.acc.new') : t('fin.acc.editTitle', { code: form.code })}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        {form.id === null ? (
                            <FormField error={action.fieldError('code')} field="code" hint={t('fin.acc.codeHint')} label={t('fin.acc.code')}>
                                <Input maxLength={12} onChange={(e) => setForm({ ...form, code: e.target.value })} value={form.code} />
                            </FormField>
                        ) : null}
                        <FormField error={action.fieldError('name')} field="name" label={t('fin.acc.name')}>
                            <Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} />
                        </FormField>
                        <FormField error={action.fieldError('department')} field="department" label={t('fin.acc.department')}>
                            <Select onChange={(e) => setForm({ ...form, department: e.target.value })} value={form.department}>
                                {overview.departments.map((d) => <option key={d} value={d}>{department(d)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('category')} field="category" label={t('fin.acc.category')}>
                            <Select onChange={(e) => setForm({ ...form, category: e.target.value })} value={form.category}>
                                {overview.categories.map((c) => <option key={c} value={c}>{category(c)}</option>)}
                            </Select>
                        </FormField>
                        {form.id !== null ? (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />
                                {t('fin.acc.activeField')}
                            </label>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
