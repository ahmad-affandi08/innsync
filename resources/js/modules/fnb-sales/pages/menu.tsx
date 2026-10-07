import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import type { Category, MenuItem, MenuView, ModifierGroup } from '@/modules/fnb-sales/lib/fnb';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { minorToMajorText, parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type CategoryForm = { id: string | null; code: string; name: string; station: string; order: string; active: boolean; lock_version: number };
type Line = { id: string | null; name: string; price: string };
type ItemForm = { id: string | null; code: string; name: string; description: string; category: string; price: string; station: string; order: string; variants: Line[]; groups: string[]; active: boolean; lock_version: number };
type GroupForm = { id: string | null; code: string; name: string; min: string; max: string; choices: Line[]; active: boolean; lock_version: number };

const toInt = (text: string): number => {
    const n = Number.parseInt(text, 10);

    return Number.isNaN(n) ? -1 : n;
};

/** The menu of an outlet: categories and their stations, items with variants, and the groups of choices an item takes. */
export default function MenuPage({ menu }: { menu: MenuView }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [category, setCategory] = useState<CategoryForm | null>(null);
    const [item, setItem] = useState<ItemForm | null>(null);
    const [group, setGroup] = useState<GroupForm | null>(null);
    const [badPrice, setBadPrice] = useState(false);
    const currency = menu.currency;
    const money = (minor: number) => format.money(minor, currency);
    const station = (s: string) => t(`fnb.station.${s}` as MessageKey);
    const reload = ['menu'];
    const outlet = menu.outlet;
    const categoryName = (id: string) => menu.categories.find((c) => c.id === id)?.name ?? '';

    function pickOutlet(id: string) {
        router.get('/fnb/menu', { outlet: id }, { preserveScroll: true, preserveState: false });
    }

    // ---- categories ----
    function openCategory(c: Category | null) {
        action.clear();
        setCategory(c === null ? { id: null, code: '', name: '', station: 'kitchen', order: String(menu.categories.length), active: true, lock_version: 0 } : { id: c.id, code: c.code, name: c.name, station: c.station, order: String(c.sort_order), active: c.is_active, lock_version: c.lock_version });
    }

    async function saveCategory() {
        if (category === null || outlet === null) return;
        const common = { name: category.name, station: category.station, sort_order: toInt(category.order) };
        const done = category.id === null
            ? await action.run(`/fnb/outlets/${outlet.id}/categories`, { body: { code: category.code, ...common }, reload })
            : await action.run(`/fnb/categories/${category.id}`, { body: { ...common, active: category.active, lock_version: category.lock_version }, reload });
        if (done !== null) setCategory(null);
    }

    // ---- items ----
    function openItem(i: MenuItem | null) {
        action.clear();
        setBadPrice(false);
        setItem(i === null
            ? { id: null, code: '', name: '', description: '', category: menu.categories.find((c) => c.is_active)?.id ?? '', price: '', station: '', order: String(menu.items.length), variants: [], groups: [], active: true, lock_version: 0 }
            : {
                id: i.id, code: i.code, name: i.name, description: i.description ?? '', category: i.category_id, price: minorToMajorText(i.price_minor, currency), station: i.station ?? '', order: String(i.sort_order),
                variants: i.variants.filter((v) => v.is_active).map((v) => ({ id: v.id, name: v.name, price: minorToMajorText(v.price_minor, currency) })), groups: i.group_ids, active: i.is_active, lock_version: i.lock_version,
            });
    }

    async function saveItem() {
        if (item === null) return;
        const price = parseMajorToMinor(item.price, currency);
        const variants = item.variants.map((v) => ({ id: v.id, name: v.name, price_minor: parseMajorToMinor(v.price, currency) }));
        const bad = price === null || variants.some((v) => v.price_minor === null);

        setBadPrice(bad);
        if (bad) return;
        const common = {
            category_id: item.category, name: item.name, description: item.description.trim() === '' ? null : item.description.trim(), price_minor: price, station: item.station === '' ? null : item.station,
            sort_order: toInt(item.order), variants, group_ids: item.groups,
        };
        const done = item.id === null
            ? await action.run('/fnb/items', { body: { code: item.code, ...common }, reload })
            : await action.run(`/fnb/items/${item.id}`, { body: { ...common, active: item.active, lock_version: item.lock_version }, reload });
        if (done !== null) setItem(null);
    }

    async function toggleAvailable(i: MenuItem) {
        await action.run(`/fnb/items/${i.id}/availability`, { body: { available: !i.is_available, lock_version: i.lock_version }, reload });
    }

    async function sendPhoto(i: MenuItem, file: File | null) {
        if (file === null) return;
        const body = new FormData();
        body.set('photo', file);
        body.set('lock_version', String(i.lock_version));
        await action.run(`/fnb/items/${i.id}/photo`, { body, reload });
    }

    async function removePhoto(i: MenuItem) {
        await action.run(`/fnb/items/${i.id}/photo`, { method: 'DELETE', body: { lock_version: i.lock_version }, reload });
    }

    // ---- groups ----
    function openGroup(g: ModifierGroup | null) {
        action.clear();
        setBadPrice(false);
        setGroup(g === null
            ? { id: null, code: '', name: '', min: '0', max: '1', choices: [{ id: null, name: '', price: '0' }], active: true, lock_version: 0 }
            : { id: g.id, code: g.code, name: g.name, min: String(g.min_select), max: String(g.max_select), choices: g.modifiers.filter((m) => m.is_active).map((m) => ({ id: m.id, name: m.name, price: minorToMajorText(m.price_delta_minor, currency) })), active: g.is_active, lock_version: g.lock_version });
    }

    async function saveGroup() {
        if (group === null) return;
        const modifiers = group.choices.map((c) => ({ id: c.id, name: c.name, price_delta_minor: parseMajorToMinor(c.price === '' ? '0' : c.price, currency) }));
        const bad = modifiers.some((m) => m.price_delta_minor === null);

        setBadPrice(bad);
        if (bad) return;
        const common = { name: group.name, min_select: toInt(group.min), max_select: toInt(group.max), modifiers };
        const done = group.id === null
            ? await action.run('/fnb/modifier-groups', { body: { code: group.code, ...common }, reload })
            : await action.run(`/fnb/modifier-groups/${group.id}`, { body: { ...common, active: group.active, lock_version: group.lock_version }, reload });
        if (done !== null) setGroup(null);
    }

    const activeLabel = (v: string) => t(v === 'active' ? 'fnb.menu.inUse' : 'fnb.menu.notInUse');
    const stateBadge = (on: boolean) => <StatusBadge label={t(on ? 'fnb.menu.inUse' : 'fnb.menu.notInUse')} tone={on ? 'success' : 'neutral'} />;

    const categoryColumns: DataGridColumn<Category>[] = [
        { id: 'code', label: t('fnb.menu.code'), value: (c) => c.code, rowHeader: true },
        { id: 'name', label: t('fnb.menu.name'), value: (c) => c.name },
        { id: 'station', label: t('fnb.menu.colStation'), value: (c) => c.station, filter: 'select', filterLabel: station, cell: (c) => station(c.station) },
        { id: 'order', label: t('fnb.menu.order'), align: 'right', value: (c) => c.sort_order },
        { id: 'state', label: t('inv.col.status'), value: (c) => (c.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: activeLabel, cell: (c) => stateBadge(c.is_active) },
        ...(menu.may.manage ? [{ id: 'actions', label: t('inv.col.actions'), cell: (c: Category) => <Button onClick={() => openCategory(c)} size="sm" type="button" variant="outline">{t('fnb.menu.edit')}</Button> }] : []),
    ];

    const itemColumns: DataGridColumn<MenuItem>[] = [
        { id: 'code', label: t('fnb.menu.code'), value: (i) => i.code, rowHeader: true },
        { id: 'name', label: t('fnb.menu.name'), value: (i) => i.name, cell: (i) => (
            <span className="flex items-center gap-2">
                {i.has_photo ? <img alt="" className="size-8 object-cover" loading="lazy" src={`/fnb/items/${i.id}/photo?v=${i.lock_version}`} /> : null}
                {i.name}
            </span>
        ) },
        { id: 'category', label: t('fnb.menu.colCategory'), value: (i) => categoryName(i.category_id), filter: 'select' },
        { id: 'price', label: t('fnb.menu.price', { currency }), align: 'right', value: (i) => i.price_minor, cell: (i) => money(i.price_minor) },
        { id: 'variants', label: t('fnb.menu.colVariants'), align: 'right', value: (i) => i.variants.filter((v) => v.is_active).length },
        { id: 'station', label: t('fnb.menu.colStation'), value: (i) => i.effective_station, filter: 'select', filterLabel: station, cell: (i) => station(i.effective_station), hidden: true },
        {
            id: 'sale', label: t('fnb.menu.colSale'), value: (i) => (i.is_available ? 'on' : 'out'), filter: 'select', filterLabel: (v) => t(v === 'on' ? 'fnb.menu.onSale' : 'fnb.menu.soldOut'),
            cell: (i) => <StatusBadge label={t(i.is_available ? 'fnb.menu.onSale' : 'fnb.menu.soldOut')} tone={i.is_available ? 'success' : 'warning'} />,
        },
        { id: 'state', label: t('inv.col.status'), value: (i) => (i.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: activeLabel, cell: (i) => stateBadge(i.is_active) },
        {
            id: 'actions', label: t('inv.col.actions'),
            cell: (i) => (
                <span className="flex gap-2">
                    {menu.may.availability && i.is_active ? <Button disabled={action.busy} onClick={() => void toggleAvailable(i)} size="sm" type="button" variant="outline">{t(i.is_available ? 'fnb.menu.markSoldOut' : 'fnb.menu.markOnSale')}</Button> : null}
                    {menu.may.manage ? (
                        <label className="inline-flex cursor-pointer items-center border border-border px-3 py-1 text-sm font-medium hover:bg-muted">
                            {t(i.has_photo ? 'fnb.menu.changePhoto' : 'fnb.menu.addPhoto')}
                            <input accept="image/jpeg,image/png" className="sr-only" disabled={action.busy} onChange={(e) => { void sendPhoto(i, e.target.files?.[0] ?? null); e.target.value = ''; }} type="file" />
                        </label>
                    ) : null}
                    {menu.may.manage && i.has_photo ? <Button disabled={action.busy} onClick={() => void removePhoto(i)} size="sm" type="button" variant="outline">{t('fnb.menu.removePhoto')}</Button> : null}
                    {menu.may.manage ? <Button onClick={() => openItem(i)} size="sm" type="button" variant="outline">{t('fnb.menu.edit')}</Button> : null}
                </span>
            ),
        },
    ];

    const groupColumns: DataGridColumn<ModifierGroup>[] = [
        { id: 'code', label: t('fnb.menu.code'), value: (g) => g.code, rowHeader: true },
        { id: 'name', label: t('fnb.menu.name'), value: (g) => g.name },
        { id: 'range', label: t('fnb.menu.colRange'), value: (g) => t('fnb.menu.groupRange', { min: g.min_select, max: g.max_select }) },
        { id: 'choices', label: t('fnb.menu.colChoices'), value: (g) => g.modifiers.filter((m) => m.is_active).map((m) => m.name).join(', ') },
        { id: 'state', label: t('inv.col.status'), value: (g) => (g.is_active ? 'active' : 'inactive'), filter: 'select', filterLabel: activeLabel, cell: (g) => stateBadge(g.is_active) },
        ...(menu.may.manage ? [{ id: 'actions', label: t('inv.col.actions'), cell: (g: ModifierGroup) => <Button onClick={() => openGroup(g)} size="sm" type="button" variant="outline">{t('fnb.menu.edit')}</Button> }] : []),
    ];

    const saveBar = (busy: boolean, onCancel: () => void, onSave: () => void) => (
        <>
            <Button disabled={busy} onClick={onCancel} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
            <Button loading={busy} onClick={onSave} type="button">{t('fnb.menu.save')}</Button>
        </>
    );
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    function lines(list: Line[], set: (next: Line[]) => void, field: string, nameLabel: string, priceLabel: string, removeLabel: string, addLabel: string) {
        return (
            <div className="flex flex-col gap-2">
                {list.map((line, index) => (
                    <div className="grid grid-cols-[1fr_8rem_auto] items-end gap-2" key={line.id ?? `new-${index}`}>
                        <FormField label={nameLabel}>
                            <Input maxLength={40} onChange={(e) => set(list.map((l, k) => (k === index ? { ...l, name: e.target.value } : l)))} value={line.name} />
                        </FormField>
                        <FormField label={priceLabel}>
                            <Input inputMode="decimal" onChange={(e) => set(list.map((l, k) => (k === index ? { ...l, price: e.target.value } : l)))} value={line.price} />
                        </FormField>
                        <Button aria-label={removeLabel} onClick={() => set(list.filter((_, k) => k !== index))} size="icon" type="button" variant="outline"><Trash2 aria-hidden="true" className="size-4" /></Button>
                    </div>
                ))}
                {action.fieldError(field) ? <p className="text-sm text-danger" role="alert">{action.fieldError(field)}</p> : null}
                <div><Button onClick={() => set([...list, { id: null, name: '', price: field === 'modifiers' ? '0' : '' }])} size="sm" type="button" variant="outline"><Plus aria-hidden="true" className="size-4" />{addLabel}</Button></div>
            </div>
        );
    }

    return (
        <FnbShell description={t('fnb.menu.description')} title={t('fnb.menu.title')} wide>
            <div className="flex flex-wrap items-end gap-4 border border-border bg-surface p-4">
                <FormField label={t('fnb.menu.outlet')}>
                    <Select disabled={menu.outlets.length === 0} onChange={(e) => pickOutlet(e.target.value)} value={outlet?.id ?? ''}>
                        {menu.outlets.map((o) => <option key={o.id} value={o.id}>{o.name} ({o.code})</option>)}
                    </Select>
                </FormField>
                {outlet !== null ? <p className="pb-2 text-sm text-muted-foreground">{t('fnb.menu.pricesNote', { mode: t(outlet.prices_include_charges ? 'fnb.menu.pricesIncluded' : 'fnb.menu.pricesAdded') })}</p> : null}
            </div>

            {action.error !== null && category === null && item === null && group === null ? failure : null}

            {outlet === null ? <Alert title={t('fnb.menu.noOutlet')} tone="info" /> : null}

            <Tabs defaultValue={outlet === null ? 'groups' : 'items'}>
                <TabsList aria-label={t('fnb.menu.title')}>
                    {outlet !== null ? <TabsTrigger value="categories">{t('fnb.menu.tabCategories', { count: menu.categories.length })}</TabsTrigger> : null}
                    {outlet !== null ? <TabsTrigger value="items">{t('fnb.menu.tabItems', { count: menu.items.length })}</TabsTrigger> : null}
                    <TabsTrigger value="groups">{t('fnb.menu.tabGroups', { count: menu.groups.length })}</TabsTrigger>
                </TabsList>

                {outlet !== null ? (
                    <TabsContent className="flex flex-col gap-3" value="categories">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="sr-only">{t('fnb.menu.categories')}</h2>
                            <span />
                            {menu.may.manage ? <Button onClick={() => openCategory(null)} size="sm" type="button">{t('fnb.menu.newCategory')}</Button> : null}
                        </div>
                        <DataGrid caption={t('fnb.menu.categories')} columns={categoryColumns} empty={<EmptyState illustration="bell" title={t('fnb.menu.categoryEmpty')} />} getRowId={(c) => c.id} id="fnb.menu.categories" rows={menu.categories} testId="menu-categories" />
                    </TabsContent>
                ) : null}

                {outlet !== null ? (
                    <TabsContent className="flex flex-col gap-3" value="items">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="sr-only">{t('fnb.menu.items')}</h2>
                            {menu.categories.filter((c) => c.is_active).length === 0 && menu.may.manage ? <p className="text-sm text-muted-foreground">{t('fnb.menu.needCategory')}</p> : <span />}
                            {menu.may.manage ? <Button disabled={menu.categories.filter((c) => c.is_active).length === 0} onClick={() => openItem(null)} size="sm" type="button">{t('fnb.menu.newItem')}</Button> : null}
                        </div>
                        <DataGrid caption={t('fnb.menu.items')} columns={itemColumns} empty={<EmptyState illustration="coffee" title={t('fnb.menu.itemEmpty')} />} getRowId={(i) => i.id} id="fnb.menu.items" rows={menu.items} testId="menu-items" />
                    </TabsContent>
                ) : null}

                <TabsContent className="flex flex-col gap-3" value="groups">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="sr-only">{t('fnb.menu.groupSection')}</h2>
                        <span />
                        {menu.may.manage ? <Button onClick={() => openGroup(null)} size="sm" type="button">{t('fnb.menu.newGroup')}</Button> : null}
                    </div>
                    <DataGrid caption={t('fnb.menu.groupSection')} columns={groupColumns} empty={<EmptyState illustration="checklist" title={t('fnb.menu.groupEmpty')} />} getRowId={(g) => g.id} id="fnb.menu.groups" rows={menu.groups} testId="menu-groups" />
                </TabsContent>
            </Tabs>

            <Dialog footer={category === null ? undefined : saveBar(action.busy, () => setCategory(null), () => void saveCategory())} onClose={() => setCategory(null)} open={category !== null} title={category === null || category.id === null ? t('fnb.menu.newCategory') : t('fnb.menu.categoryTitle', { code: category.code })}>
                {category !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        {category.id === null ? (
                            <FormField error={action.fieldError('code')} field="code" hint={t('fnb.out.codeHint')} label={t('fnb.menu.code')}>
                                <Input maxLength={12} onChange={(e) => setCategory({ ...category, code: e.target.value })} value={category.code} />
                            </FormField>
                        ) : null}
                        <FormField error={action.fieldError('name')} field="name" label={t('fnb.menu.name')}>
                            <Input maxLength={80} onChange={(e) => setCategory({ ...category, name: e.target.value })} value={category.name} />
                        </FormField>
                        <FormField error={action.fieldError('station')} field="station" hint={t('fnb.menu.stationHint')} label={t('fnb.menu.station')}>
                            <Select onChange={(e) => setCategory({ ...category, station: e.target.value })} value={category.station}>
                                {menu.stations.map((s) => <option key={s} value={s}>{station(s)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('sort_order')} field="sort_order" label={t('fnb.menu.order')}>
                            <Input inputMode="numeric" onChange={(e) => setCategory({ ...category, order: e.target.value })} value={category.order} />
                        </FormField>
                        {category.id !== null ? (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input checked={category.active} onChange={(e) => setCategory({ ...category, active: e.target.checked })} type="checkbox" />
                                {t('fnb.menu.activeField')}
                            </label>
                        ) : null}
                    </div>
                )}
            </Dialog>

            <Dialog className="max-w-2xl" footer={item === null ? undefined : saveBar(action.busy, () => setItem(null), () => void saveItem())} onClose={() => setItem(null)} open={item !== null} title={item === null || item.id === null ? t('fnb.menu.newItem') : t('fnb.menu.itemTitle', { code: item.code })}>
                {item !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        {item.id === null ? (
                            <FormField error={action.fieldError('code')} field="code" hint={t('fnb.menu.codeHint')} label={t('fnb.menu.code')}>
                                <Input maxLength={16} onChange={(e) => setItem({ ...item, code: e.target.value })} value={item.code} />
                            </FormField>
                        ) : null}
                        <FormField error={action.fieldError('name')} field="name" label={t('fnb.menu.name')}>
                            <Input maxLength={80} onChange={(e) => setItem({ ...item, name: e.target.value })} value={item.name} />
                        </FormField>
                        <FormField error={action.fieldError('category_id')} field="category_id" label={t('fnb.menu.category')}>
                            <Select onChange={(e) => setItem({ ...item, category: e.target.value })} value={item.category}>
                                {menu.categories.filter((c) => c.is_active || c.id === item.category).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={badPrice ? t('fnb.menu.badPrice') : action.fieldError('price_minor')} field="price_minor" label={t('fnb.menu.price', { currency })}>
                            <Input inputMode="decimal" onChange={(e) => setItem({ ...item, price: e.target.value })} value={item.price} />
                        </FormField>
                        <FormField error={action.fieldError('station')} field="station" label={t('fnb.menu.station')}>
                            <Select onChange={(e) => setItem({ ...item, station: e.target.value })} value={item.station}>
                                <option value="">{t('fnb.menu.stationFromCategory')}</option>
                                {menu.stations.map((s) => <option key={s} value={s}>{station(s)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('sort_order')} field="sort_order" label={t('fnb.menu.order')}>
                            <Input inputMode="numeric" onChange={(e) => setItem({ ...item, order: e.target.value })} value={item.order} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('description')} field="description" label={t('fnb.menu.description2')}>
                                <Input maxLength={200} onChange={(e) => setItem({ ...item, description: e.target.value })} value={item.description} />
                            </FormField>
                        </div>
                        <fieldset className="flex flex-col gap-2 sm:col-span-2">
                            <legend className="text-sm font-medium">{t('fnb.menu.variants')}</legend>
                            <p className="text-sm text-muted-foreground">{t('fnb.menu.variantsHint')}</p>
                            {lines(item.variants, (variants) => setItem({ ...item, variants }), 'variants', t('fnb.menu.variantName'), t('fnb.menu.price', { currency }), t('fnb.menu.removeVariant'), t('fnb.menu.addVariant'))}
                        </fieldset>
                        <fieldset className="flex flex-col gap-2 sm:col-span-2">
                            <legend className="text-sm font-medium">{t('fnb.menu.groups')}</legend>
                            <p className="text-sm text-muted-foreground">{t('fnb.menu.groupsHint')}</p>
                            {menu.groups.filter((g) => g.is_active).length === 0 ? <p className="text-sm text-muted-foreground">{t('fnb.menu.noGroups')}</p> : null}
                            {menu.groups.filter((g) => g.is_active).map((g) => (
                                <label className="flex items-center gap-2 text-sm" key={g.id}>
                                    <input checked={item.groups.includes(g.id)} onChange={(e) => setItem({ ...item, groups: e.target.checked ? [...item.groups, g.id] : item.groups.filter((x) => x !== g.id) })} type="checkbox" />
                                    {g.name} <span className="text-muted-foreground">({t('fnb.menu.groupRange', { min: g.min_select, max: g.max_select })})</span>
                                </label>
                            ))}
                        </fieldset>
                        {item.id !== null ? (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input checked={item.active} onChange={(e) => setItem({ ...item, active: e.target.checked })} type="checkbox" />
                                {t('fnb.menu.activeField')}
                            </label>
                        ) : null}
                    </div>
                )}
            </Dialog>

            <Dialog className="max-w-2xl" footer={group === null ? undefined : saveBar(action.busy, () => setGroup(null), () => void saveGroup())} onClose={() => setGroup(null)} open={group !== null} title={group === null || group.id === null ? t('fnb.menu.newGroup') : t('fnb.menu.groupTitle', { code: group.code })}>
                {group !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        {group.id === null ? (
                            <FormField error={action.fieldError('code')} field="code" hint={t('fnb.out.codeHint')} label={t('fnb.menu.code')}>
                                <Input maxLength={12} onChange={(e) => setGroup({ ...group, code: e.target.value })} value={group.code} />
                            </FormField>
                        ) : null}
                        <FormField error={action.fieldError('name')} field="name" label={t('fnb.menu.name')}>
                            <Input maxLength={60} onChange={(e) => setGroup({ ...group, name: e.target.value })} value={group.name} />
                        </FormField>
                        <FormField error={action.fieldError('min_select')} field="min_select" label={t('fnb.menu.groupMin')}>
                            <Input inputMode="numeric" onChange={(e) => setGroup({ ...group, min: e.target.value })} value={group.min} />
                        </FormField>
                        <FormField error={action.fieldError('max_select')} field="max_select" label={t('fnb.menu.groupMax')}>
                            <Input inputMode="numeric" onChange={(e) => setGroup({ ...group, max: e.target.value })} value={group.max} />
                        </FormField>
                        <fieldset className="flex flex-col gap-2 sm:col-span-2">
                            <legend className="text-sm font-medium">{t('fnb.menu.groupChoices')}</legend>
                            {badPrice ? <p className="text-sm text-danger" role="alert">{t('fnb.menu.badPrice')}</p> : null}
                            {lines(group.choices, (choices) => setGroup({ ...group, choices }), 'modifiers', t('fnb.menu.choiceName'), t('fnb.menu.choiceDelta', { currency }), t('fnb.menu.removeChoice'), t('fnb.menu.addChoice'))}
                        </fieldset>
                        {group.id !== null ? (
                            <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input checked={group.active} onChange={(e) => setGroup({ ...group, active: e.target.checked })} type="checkbox" />
                                {t('fnb.menu.activeField')}
                            </label>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </FnbShell>
    );
}
