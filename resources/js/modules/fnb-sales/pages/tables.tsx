import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import type { Table, TablesOverview } from '@/modules/fnb-sales/lib/fnb';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Form = { id: string | null; code: string; area: string; seats: string; active: boolean; lock_version: number };

/** The tables of one outlet, with their area and seats. */
export default function TablesPage({ overview }: { overview: TablesOverview }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form | null>(null);
    const reload = ['overview'];

    function openNew() {
        action.clear();
        setForm({ id: null, code: '', area: '', seats: '4', active: true, lock_version: 0 });
    }

    function openEdit(row: Table) {
        action.clear();
        setForm({ id: row.id, code: row.code, area: row.area ?? '', seats: String(row.seats), active: row.is_active, lock_version: row.lock_version });
    }

    async function save() {
        if (form === null) return;
        const seats = Number.parseInt(form.seats, 10);
        const done = form.id === null
            ? await action.run(`/fnb/outlets/${overview.outlet.id}/tables`, { body: { code: form.code, area: form.area.trim() === '' ? null : form.area.trim(), seats: Number.isNaN(seats) ? 0 : seats }, reload })
            : await action.run(`/fnb/tables/${form.id}`, { body: { area: form.area.trim() === '' ? null : form.area.trim(), seats: Number.isNaN(seats) ? 0 : seats, active: form.active, lock_version: form.lock_version }, reload });
        if (done !== null) setForm(null);
    }

    const columns: DataGridColumn<Table>[] = [
        { id: 'code', label: t('fnb.tbl.code'), value: (r) => r.code, rowHeader: true },
        { id: 'area', label: t('fnb.tbl.area'), value: (r) => r.area ?? '—', filter: 'select' },
        { id: 'seats', label: t('fnb.tbl.seats'), align: 'right', value: (r) => r.seats },
        {
            id: 'state', label: t('inv.col.status'), value: (r) => (r.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: (v) => t(v === 'active' ? 'fnb.out.active' : 'fnb.out.inactive'),
            cell: (r) => <StatusBadge label={t(r.is_active ? 'fnb.out.active' : 'fnb.out.inactive')} tone={r.is_active ? 'success' : 'neutral'} />,
        },
        ...(overview.may.manage ? [{ id: 'actions', label: t('inv.col.actions'), cell: (r: Table) => <Button onClick={() => openEdit(r)} size="sm" type="button" variant="outline">{t('fnb.out.edit')}</Button> }] : []),
    ];

    return (
        <FnbShell
            actions={<>
                <Button asChild variant="outline"><Link href="/fnb/outlets">{t('fnb.tbl.back')}</Link></Button>
                {overview.may.manage ? <Button onClick={openNew} type="button">{t('fnb.tbl.new')}</Button> : null}
            </>}
            description={t('fnb.tbl.description')}
            title={t('fnb.tbl.title', { outlet: overview.outlet.name })}
            wide
        >
            <DataGrid caption={t('fnb.tbl.title', { outlet: overview.outlet.name })} columns={columns} empty={<EmptyState illustration="armchair" title={t('fnb.tbl.empty')} />} getRowId={(r) => r.id} id="fnb.tables" rows={overview.tables} testId="tables" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('fnb.tbl.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form === null || form.id === null ? t('fnb.tbl.new') : t('fnb.tbl.editTitle', { code: form.code })}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        {form.id === null ? (
                            <FormField error={action.fieldError('code')} field="code" hint={t('fnb.tbl.codeHint')} label={t('fnb.tbl.code')}>
                                <Input maxLength={8} onChange={(e) => setForm({ ...form, code: e.target.value })} value={form.code} />
                            </FormField>
                        ) : null}
                        <FormField error={action.fieldError('area')} field="area" hint={t('fnb.tbl.areaHint')} label={t('fnb.tbl.area')}>
                            <Input maxLength={40} onChange={(e) => setForm({ ...form, area: e.target.value })} value={form.area} />
                        </FormField>
                        <FormField error={action.fieldError('seats')} field="seats" label={t('fnb.tbl.seats')}>
                            <Input inputMode="numeric" onChange={(e) => setForm({ ...form, seats: e.target.value })} value={form.seats} />
                        </FormField>
                        {form.id !== null ? (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />
                                {t('fnb.tbl.activeField')}
                            </label>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </FnbShell>
    );
}
