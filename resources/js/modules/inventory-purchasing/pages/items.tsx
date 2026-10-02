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
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Version = { id: string; version: number; factor_milli: number; reason: string; created_at: string };
type UnitRow = { unit: string; factor_milli: number; version: number; history: Version[] };
type Item = { id: string; code: string; name: string; category_id: string; category_name: string; department: string; base_unit: string; is_active: boolean; lock_version: number; units: UnitRow[] };
type Category = { id: string; code: string; name: string; is_active: boolean };
type Catalog = { categories: Category[]; items: Item[]; departments: string[]; may: { manage: boolean; stock: boolean } };

const BLANK = { code: '', name: '', category_id: '', department: 'general', base_unit: '', active: true };

export default function ItemsPage({ catalog }: { catalog: Catalog }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<(typeof BLANK & { id: string | null; lock: number }) | null>(null);
    const [unitsOf, setUnitsOf] = useState<string | null>(null);
    const [conv, setConv] = useState({ unit: '', factor: '', reason: '' });
    const [saved, setSaved] = useState(false);
    const reload = ['catalog'];
    const dept = (d: string) => t(`inv.dept.${d}` as MessageKey);
    const unitsItem = catalog.items.find((i) => i.id === unitsOf) ?? null;

    function openNew() {
        action.clear();
        setForm({ ...BLANK, category_id: catalog.categories.find((c) => c.is_active)?.id ?? '', id: null, lock: 0 });
    }

    function openEdit(i: Item) {
        action.clear();
        setForm({ code: i.code, name: i.name, category_id: i.category_id, department: i.department, base_unit: i.base_unit, active: i.is_active, id: i.id, lock: i.lock_version });
    }

    async function save() {
        if (form === null) return;
        const done = form.id === null
            ? await action.run('/inventory/items', { body: { code: form.code, name: form.name, category_id: form.category_id, department: form.department, base_unit: form.base_unit }, reload })
            : await action.run(`/inventory/items/${form.id}`, { body: { name: form.name, category_id: form.category_id, department: form.department, active: form.active, lock_version: form.lock }, reload });
        if (done !== null) setForm(null);
    }

    async function addConversion() {
        if (unitsItem === null) return;
        setSaved(false);
        const done = await action.run(`/inventory/items/${unitsItem.id}/units`, { body: { unit: conv.unit, factor: conv.factor, reason: conv.reason }, reload });
        if (done !== null) { setConv({ unit: '', factor: '', reason: '' }); setSaved(true); }
    }

    const columns: DataGridColumn<Item>[] = [
        { id: 'code', label: t('inv.col.code'), value: (i) => i.code, rowHeader: true },
        { id: 'name', label: t('inv.col.name'), value: (i) => i.name },
        { id: 'category', label: t('inv.col.category'), value: (i) => i.category_name, filter: 'select' },
        { id: 'department', label: t('inv.col.department'), value: (i) => i.department, filter: 'select', filterLabel: dept, cell: (i) => dept(i.department) },
        { id: 'base', label: t('inv.col.baseUnit'), value: (i) => i.base_unit },
        { id: 'units', label: t('inv.col.units'), value: (i) => i.units.map((u) => u.unit).join(' '), cell: (i) => (i.units.length === 0 ? '—' : i.units.map((u) => `${u.unit} × ${formatMilli(u.factor_milli, locale)}`).join(', ')) },
        { id: 'state', label: t('inv.col.status'), value: (i) => (i.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: (v) => t(`inv.status.${v}` as MessageKey), cell: (i) => <StatusBadge label={t(i.is_active ? 'inv.status.active' : 'inv.status.inactive')} tone={i.is_active ? 'success' : 'neutral'} /> },
        {
            id: 'actions', label: t('inv.col.actions'), cell: (i) => (
                <div className="flex flex-wrap gap-2">
                    <Button onClick={() => { action.clear(); setSaved(false); setUnitsOf(i.id); }} size="sm" type="button" variant="outline">{t('inv.action.units')}</Button>
                    {catalog.may.manage ? <Button onClick={() => openEdit(i)} size="sm" type="button" variant="outline">{t('inv.action.edit')}</Button> : null}
                </div>
            ),
        },
    ];

    return (
        <InventoryShell actions={catalog.may.manage ? <Button onClick={openNew} type="button">{t('inv.items.add')}</Button> : undefined} description={t('inv.items.description')} title={t('inv.items.title')} wide>
            <DataGrid
                caption={t('inv.items.title')}
                columns={columns}
                empty={<EmptyState title={t('inv.items.empty')} />}
                getRowId={(i) => i.id}
                id="inv.items"
                rows={catalog.items}
            />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('inv.action.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form === null ? '' : t(form.id === null ? 'inv.item.new' : 'inv.item.edit')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('code')} field="code" hint={form.id === null ? t('inv.item.codeHint') : undefined} label={t('inv.col.code')}>
                            <Input disabled={form.id !== null} maxLength={20} onChange={(e) => setForm({ ...form, code: e.target.value })} value={form.code} />
                        </FormField>
                        <FormField error={action.fieldError('base_unit')} field="base_unit" hint={form.id === null ? t('inv.item.baseUnitHint') : undefined} label={t('inv.col.baseUnit')}>
                            <Input disabled={form.id !== null} maxLength={8} onChange={(e) => setForm({ ...form, base_unit: e.target.value })} value={form.base_unit} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('name')} field="name" label={t('inv.col.name')}>
                                <Input maxLength={120} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} />
                            </FormField>
                        </div>
                        <FormField error={action.fieldError('category_id')} field="category_id" label={t('inv.col.category')}>
                            <Select onChange={(e) => setForm({ ...form, category_id: e.target.value })} value={form.category_id}>
                                {catalog.categories.filter((c) => c.is_active || c.id === form.category_id).map((c) => <option key={c.id} value={c.id}>{c.code} · {c.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('department')} field="department" label={t('inv.col.department')}>
                            <Select onChange={(e) => setForm({ ...form, department: e.target.value })} value={form.department}>
                                {catalog.departments.map((d) => <option key={d} value={d}>{dept(d)}</option>)}
                            </Select>
                        </FormField>
                        {form.id !== null ? (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />
                                {t('inv.item.active')}
                            </label>
                        ) : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<Button onClick={() => setUnitsOf(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>}
                onClose={() => setUnitsOf(null)}
                open={unitsItem !== null}
                title={unitsItem === null ? '' : t('inv.units.title', { name: unitsItem.name })}
            >
                {unitsItem !== null && (
                    <div className="flex flex-col gap-4">
                        <p className="text-sm text-muted-foreground">{t('inv.units.description')}</p>
                        {unitsItem.units.length === 0 ? <p className="text-sm">{t('inv.units.none')}</p> : (
                            <ul className="flex flex-col gap-3 text-sm" data-testid="unit-versions">
                                {unitsItem.units.map((u) => (
                                    <li className="border border-border p-3" key={u.unit}>
                                        <p className="font-semibold">1 {u.unit} = {formatMilli(u.factor_milli, locale)} {unitsItem.base_unit}</p>
                                        <ul className="mt-1 text-xs text-muted-foreground">
                                            {[...u.history].reverse().map((v) => (
                                                <li key={v.id}>v{v.version} · ×{formatMilli(v.factor_milli, locale)} · {format.instant(v.created_at)} · {v.reason}{v.version === u.version ? ` · ${t('inv.units.current')}` : ''}</li>
                                            ))}
                                        </ul>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {catalog.may.manage ? (
                            <div className="grid gap-3 border-t border-border pt-3 sm:grid-cols-2">
                                <h3 className="text-sm font-semibold sm:col-span-2">{t('inv.units.add')}</h3>
                                {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                                {saved ? <div className="sm:col-span-2"><Alert title={t('inv.units.saved')} tone="success" /></div> : null}
                                <FormField error={action.fieldError('unit')} field="unit" label={t('inv.units.unit')}>
                                    <Input maxLength={8} onChange={(e) => setConv({ ...conv, unit: e.target.value })} value={conv.unit} />
                                </FormField>
                                <FormField error={action.fieldError('factor')} field="factor" hint={t('inv.units.factorHint')} label={t('inv.units.factor', { unit: unitsItem.base_unit })}>
                                    <Input inputMode="decimal" onChange={(e) => setConv({ ...conv, factor: e.target.value })} value={conv.factor} />
                                </FormField>
                                <div className="sm:col-span-2">
                                    <FormField error={action.fieldError('reason')} field="reason" label={t('inv.col.reason')}>
                                        <Input maxLength={200} onChange={(e) => setConv({ ...conv, reason: e.target.value })} value={conv.reason} />
                                    </FormField>
                                </div>
                                <div className="sm:col-span-2"><Button loading={action.busy} onClick={() => void addConversion()} type="button">{t('inv.action.save')}</Button></div>
                            </div>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
