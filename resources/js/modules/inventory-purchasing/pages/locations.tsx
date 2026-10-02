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
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Location = { id: string; code: string; name: string; kind: string; is_active: boolean; lock_version: number };
type Category = { id: string; code: string; name: string; is_active: boolean; lock_version: number };
type Catalog = { categories: Category[]; locations: Location[]; kinds: string[]; may: { manage: boolean } };

type Editing =
    | { what: 'location'; id: string | null; code: string; name: string; kind: string; active: boolean; lock: number }
    | { what: 'category'; id: string | null; code: string; name: string; active: boolean; lock: number };

export default function LocationsPage({ catalog }: { catalog: Catalog }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [edit, setEdit] = useState<Editing | null>(null);
    const reload = ['catalog'];
    const kind = (k: string) => t(`inv.kind.${k}` as MessageKey);

    function open(next: Editing) {
        action.clear();
        setEdit(next);
    }

    async function save() {
        if (edit === null) return;
        let done: unknown;

        if (edit.what === 'location') {
            done = edit.id === null
                ? await action.run('/inventory/locations', { body: { code: edit.code, name: edit.name, kind: edit.kind }, reload })
                : await action.run(`/inventory/locations/${edit.id}`, { body: { name: edit.name, kind: edit.kind, active: edit.active, lock_version: edit.lock }, reload });
        } else {
            done = edit.id === null
                ? await action.run('/inventory/categories', { body: { code: edit.code, name: edit.name }, reload })
                : await action.run(`/inventory/categories/${edit.id}`, { body: { name: edit.name, active: edit.active, lock_version: edit.lock }, reload });
        }

        if (done !== null) setEdit(null);
    }

    const state = <T extends { is_active: boolean }>(): DataGridColumn<T> => ({
        id: 'state', label: t('inv.col.status'), value: (x) => (x.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: (v) => t(`inv.status.${v}` as MessageKey),
        cell: (x) => <StatusBadge label={t(x.is_active ? 'inv.status.active' : 'inv.status.inactive')} tone={x.is_active ? 'success' : 'neutral'} />,
    });
    const locationColumns: DataGridColumn<Location>[] = [
        { id: 'code', label: t('inv.col.code'), value: (l) => l.code, rowHeader: true },
        { id: 'name', label: t('inv.col.name'), value: (l) => l.name },
        { id: 'kind', label: t('inv.col.kind'), value: (l) => l.kind, filter: 'select', filterLabel: kind, cell: (l) => kind(l.kind) },
        state<Location>(),
        ...(catalog.may.manage ? [{ id: 'actions', label: t('inv.col.actions'), cell: (l: Location) => <Button onClick={() => open({ what: 'location', id: l.id, code: l.code, name: l.name, kind: l.kind, active: l.is_active, lock: l.lock_version })} size="sm" type="button" variant="outline">{t('inv.action.edit')}</Button> }] : []),
    ];
    const categoryColumns: DataGridColumn<Category>[] = [
        { id: 'code', label: t('inv.col.code'), value: (c) => c.code, rowHeader: true },
        { id: 'name', label: t('inv.col.name'), value: (c) => c.name },
        state<Category>(),
        ...(catalog.may.manage ? [{ id: 'actions', label: t('inv.col.actions'), cell: (c: Category) => <Button onClick={() => open({ what: 'category', id: c.id, code: c.code, name: c.name, active: c.is_active, lock: c.lock_version })} size="sm" type="button" variant="outline">{t('inv.action.edit')}</Button> }] : []),
    ];
    const title = edit === null ? '' : t(edit.what === 'location' ? (edit.id === null ? 'inv.loc.addLocation' : 'inv.loc.editLocation') : (edit.id === null ? 'inv.loc.addCategory' : 'inv.loc.editCategory'));

    return (
        <InventoryShell description={t('inv.loc.description')} title={t('inv.loc.title')} wide>
            <section aria-labelledby="loc-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="loc-h">{t('inv.loc.locations')}</h2>
                    {catalog.may.manage ? <Button onClick={() => open({ what: 'location', id: null, code: '', name: '', kind: 'main', active: true, lock: 0 })} type="button">{t('inv.loc.addLocation')}</Button> : null}
                </div>
                <DataGrid caption={t('inv.loc.locations')} columns={locationColumns} empty={<EmptyState title={t('inv.loc.emptyLocations')} />} getRowId={(l) => l.id} id="inv.locations" rows={catalog.locations} />
            </section>

            <section aria-labelledby="cat-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="cat-h">{t('inv.loc.categories')}</h2>
                    {catalog.may.manage ? <Button onClick={() => open({ what: 'category', id: null, code: '', name: '', active: true, lock: 0 })} type="button">{t('inv.loc.addCategory')}</Button> : null}
                </div>
                <DataGrid caption={t('inv.loc.categories')} columns={categoryColumns} empty={<EmptyState title={t('inv.loc.emptyCategories')} />} getRowId={(c) => c.id} id="inv.categories" rows={catalog.categories} />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setEdit(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setEdit(null)}
                open={edit !== null}
                title={title}
            >
                {edit !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('code')} field="code" label={t('inv.col.code')}>
                            <Input disabled={edit.id !== null} maxLength={12} onChange={(e) => setEdit({ ...edit, code: e.target.value })} value={edit.code} />
                        </FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('inv.col.name')}>
                            <Input maxLength={80} onChange={(e) => setEdit({ ...edit, name: e.target.value })} value={edit.name} />
                        </FormField>
                        {edit.what === 'location' ? (
                            <FormField error={action.fieldError('kind')} field="kind" label={t('inv.col.kind')}>
                                <Select onChange={(e) => setEdit({ ...edit, kind: e.target.value })} value={edit.kind}>
                                    {catalog.kinds.map((k) => <option key={k} value={k}>{kind(k)}</option>)}
                                </Select>
                            </FormField>
                        ) : null}
                        {edit.id !== null ? (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input checked={edit.active} onChange={(e) => setEdit({ ...edit, active: e.target.checked })} type="checkbox" />
                                {t('inv.loc.inUse')}
                            </label>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
