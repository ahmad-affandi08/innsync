import { Link } from '@inertiajs/react';
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
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import type { Outlet, OutletsOverview } from '@/modules/fnb-sales/lib/fnb';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Form = { id: string | null; code: string; name: string; kind: string; scope: string; include: boolean; active: boolean; lock_version: number };

/** The outlets that sell food and drink and the scheme of service charge and tax each follows. */
export default function OutletsPage({ overview }: { overview: OutletsOverview }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const kind = (k: string) => t(`fnb.kind.${k}` as MessageKey);
    const scope = (s: string) => t(`tax.scope.${s}` as MessageKey);
    const reload = ['overview'];

    function openNew() {
        action.clear();
        setForm({ id: null, code: '', name: '', kind: overview.kinds[0] ?? 'restaurant', scope: overview.scopes[0] ?? 'fnb', include: false, active: true, lock_version: 0 });
    }

    function openEdit(o: Outlet) {
        action.clear();
        setForm({ id: o.id, code: o.code, name: o.name, kind: o.kind, scope: o.charge_scope, include: o.prices_include_charges, active: o.is_active, lock_version: o.lock_version });
    }

    async function save() {
        if (form === null) return;
        const done = form.id === null
            ? await action.run('/fnb/outlets', { body: { code: form.code, name: form.name, kind: form.kind, charge_scope: form.scope, prices_include_charges: form.include }, reload })
            : await action.run(`/fnb/outlets/${form.id}`, { body: { name: form.name, kind: form.kind, charge_scope: form.scope, prices_include_charges: form.include, active: form.active, lock_version: form.lock_version }, reload });
        if (done !== null) setForm(null);
    }

    const columns: DataGridColumn<Outlet>[] = [
        { id: 'code', label: t('fnb.out.code'), value: (o) => o.code, rowHeader: true },
        { id: 'name', label: t('fnb.out.name'), value: (o) => o.name },
        { id: 'kind', label: t('fnb.out.kind'), value: (o) => o.kind, filter: 'select', filterLabel: kind, cell: (o) => kind(o.kind) },
        { id: 'charges', label: t('fnb.out.colCharges'), value: (o) => `${scope(o.charge_scope)} · ${t(o.prices_include_charges ? 'fnb.out.chargesIncluded' : 'fnb.out.chargesAdded')}` },
        { id: 'tables', label: t('fnb.out.tables'), align: 'right', value: (o) => o.tables ?? 0, cell: (o) => <Link className="font-medium underline-offset-2 hover:underline" href={`/fnb/outlets/${o.id}/tables`}>{t('fnb.out.openTables', { count: o.tables ?? 0 })}</Link> },
        {
            id: 'state', label: t('inv.col.status'), value: (o) => (o.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: (v) => t(v === 'active' ? 'fnb.out.active' : 'fnb.out.inactive'),
            cell: (o) => <StatusBadge label={t(o.is_active ? 'fnb.out.active' : 'fnb.out.inactive')} tone={o.is_active ? 'success' : 'neutral'} />,
        },
        {
            id: 'actions', label: t('inv.col.actions'),
            cell: (o) => (
                <span className="flex gap-2">
                    <Button asChild size="sm" variant="outline"><Link href={`/fnb/menu?outlet=${o.id}`}>{t('fnb.out.menu')}</Link></Button>
                    {overview.may.manage ? <Button onClick={() => openEdit(o)} size="sm" type="button" variant="outline">{t('fnb.out.edit')}</Button> : null}
                </span>
            ),
        },
    ];

    return (
        <FnbShell actions={overview.may.manage ? <Button onClick={openNew} type="button">{t('fnb.out.new')}</Button> : undefined} description={t('fnb.out.description')} title={t('fnb.out.title')} wide>
            <DataGrid caption={t('fnb.out.title')} columns={columns} empty={<EmptyState illustration="reception" title={t('fnb.out.empty')} />} getRowId={(o) => o.id} id="fnb.outlets" rows={overview.outlets} testId="outlets" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('fnb.out.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form === null || form.id === null ? t('fnb.out.new') : t('fnb.out.editTitle', { code: form.code })}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        {form.id === null ? (
                            <FormField error={action.fieldError('code')} field="code" hint={t('fnb.out.codeHint')} label={t('fnb.out.code')}>
                                <Input maxLength={12} onChange={(e) => setForm({ ...form, code: e.target.value })} value={form.code} />
                            </FormField>
                        ) : null}
                        <FormField error={action.fieldError('name')} field="name" label={t('fnb.out.name')}>
                            <Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} />
                        </FormField>
                        <FormField error={action.fieldError('kind')} field="kind" label={t('fnb.out.kind')}>
                            <Select onChange={(e) => setForm({ ...form, kind: e.target.value })} value={form.kind}>
                                {overview.kinds.map((k) => <option key={k} value={k}>{kind(k)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('charge_scope')} field="charge_scope" hint={t('fnb.out.scopeHint')} label={t('fnb.out.scope')}>
                            <Select onChange={(e) => setForm({ ...form, scope: e.target.value })} value={form.scope}>
                                {overview.scopes.map((s) => <option key={s} value={s}>{scope(s)}</option>)}
                            </Select>
                        </FormField>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input checked={form.include} onChange={(e) => setForm({ ...form, include: e.target.checked })} type="checkbox" />
                            {t('fnb.out.includeCharges')}
                        </label>
                        {form.id !== null ? (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />
                                {t('fnb.out.activeField')}
                            </label>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </FnbShell>
    );
}
