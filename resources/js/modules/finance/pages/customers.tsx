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
import { useCustomerKindLabel } from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Customer = {
    id: string; code: string; name: string; kind: 'company' | 'agent' | 'ota' | 'other'; terms_days: number; from_company: boolean; active: boolean; lock_version: number;
    owed_minor: number; overdue_minor: number; open_receivables: number;
};
type Overview = { customers: Customer[]; kinds: string[]; may: { manage: boolean } };
type Form = { id: string | null; code: string; name: string; kind: string; terms: string; active: boolean; lock_version: number; kindLabel: string };

const FALLBACK_CURRENCY = 'IDR';

/** The parties that owe the property money, and the days they have to pay. */
export default function CustomersPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const kindLabel = useCustomerKindLabel();
    const [form, setForm] = useState<Form | null>(null);
    const [badTerms, setBadTerms] = useState(false);
    const money = (minor: number) => format.money(minor, FALLBACK_CURRENCY);
    const reload = ['overview'];

    function openNew() {
        action.clear();
        setBadTerms(false);
        setForm({ id: null, code: '', name: '', kind: overview.kinds[0] ?? 'other', terms: '30', active: true, lock_version: 0, kindLabel: '' });
    }

    function openEdit(c: Customer) {
        action.clear();
        setBadTerms(false);
        setForm({ id: c.id, code: c.code, name: c.name, kind: c.kind, terms: String(c.terms_days), active: c.active, lock_version: c.lock_version, kindLabel: kindLabel(c.kind) });
    }

    async function save() {
        if (form === null) return;
        const terms = /^\d{1,3}$/.test(form.terms.trim()) ? Number(form.terms.trim()) : null;

        setBadTerms(terms === null || terms > 180);
        if (terms === null || terms > 180) return;
        const done = form.id === null
            ? await action.run('/finance/customers', { body: { code: form.code.trim(), name: form.name.trim(), kind: form.kind, terms_days: terms }, reload })
            : await action.run(`/finance/customers/${form.id}`, { body: { name: form.name.trim(), terms_days: terms, active: form.active, lock_version: form.lock_version }, reload });
        if (done !== null) setForm(null);
    }

    const columns: DataGridColumn<Customer>[] = [
        { id: 'code', label: t('fin.arc.code'), value: (c) => c.code, rowHeader: true },
        { id: 'name', label: t('fin.arc.name'), value: (c) => c.name },
        { id: 'kind', label: t('fin.arc.kind'), value: (c) => c.kind, filter: 'select', filterLabel: kindLabel, cell: (c) => kindLabel(c.kind) },
        { id: 'terms', label: t('fin.arc.terms'), align: 'right', value: (c) => c.terms_days, cell: (c) => t('fin.arc.termsDays', { days: c.terms_days }) },
        { id: 'open', label: t('fin.arc.openReceivables'), align: 'right', value: (c) => c.open_receivables, cell: (c) => format.number(c.open_receivables) },
        { id: 'owed', label: t('fin.arc.owed'), align: 'right', value: (c) => c.owed_minor, cell: (c) => money(c.owed_minor) },
        { id: 'overdue', label: t('fin.arc.overdue'), align: 'right', value: (c) => c.overdue_minor, cell: (c) => money(c.overdue_minor) },
        {
            id: 'state', label: t('inv.col.status'), value: (c) => (c.active ? 'active' : 'inactive'), filter: 'select', filterLabel: (v) => t(v === 'active' ? 'fin.arc.active' : 'fin.arc.inactive'),
            cell: (c) => <StatusBadge label={t(c.active ? 'fin.arc.active' : 'fin.arc.inactive')} tone={c.active ? 'success' : 'neutral'} />,
        },
        ...(overview.may.manage ? [{ id: 'actions', label: t('inv.col.actions'), cell: (c: Customer) => <Button onClick={() => openEdit(c)} size="sm" type="button" variant="outline">{t('fin.arc.edit')}</Button> }] : []),
    ];

    return (
        <FinanceShell actions={overview.may.manage ? <Button onClick={openNew} type="button">{t('fin.arc.new')}</Button> : undefined} description={t('fin.arc.description')} title={t('fin.arc.title')} wide>
            <DataGrid caption={t('fin.arc.title')} columns={columns} empty={<EmptyState title={t('fin.arc.empty')} />} getRowId={(c) => c.id} id="fin.customers" rows={overview.customers} testId="customers" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('fin.arc.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form?.id === null || form === null ? t('fin.arc.new') : t('fin.arc.editTitle', { code: form.code })}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        {form.id === null ? (
                            <>
                                <FormField error={action.fieldError('code')} field="code" hint={t('fin.arc.codeHint')} label={t('fin.arc.code')}>
                                    <Input maxLength={20} onChange={(e) => setForm({ ...form, code: e.target.value })} value={form.code} />
                                </FormField>
                                <FormField error={action.fieldError('kind')} field="kind" hint={t('fin.arc.kindHint')} label={t('fin.arc.kind')}>
                                    <Select onChange={(e) => setForm({ ...form, kind: e.target.value })} searchable={false} value={form.kind}>
                                        {overview.kinds.map((k) => <option key={k} value={k}>{kindLabel(k)}</option>)}
                                    </Select>
                                </FormField>
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.arc.fixed', { code: form.code, kind: form.kindLabel })}</p>
                        )}
                        <FormField error={action.fieldError('name')} field="name" label={t('fin.arc.name')}>
                            <Input maxLength={120} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} />
                        </FormField>
                        <FormField error={badTerms ? t('fin.arc.badTerms') : action.fieldError('terms_days')} field="terms_days" hint={t('fin.arc.termsHint')} label={t('fin.arc.termsField')}>
                            <Input inputMode="numeric" onChange={(e) => { setBadTerms(false); setForm({ ...form, terms: e.target.value }); }} value={form.terms} />
                        </FormField>
                        {form.id !== null ? (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />
                                {t('fin.arc.activeField')}
                            </label>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
