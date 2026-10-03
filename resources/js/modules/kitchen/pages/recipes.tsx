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
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { KitchenShell } from '@/modules/kitchen/components/kitchen-shell';
import { formatBp, formatMilli, parseMilli, parsePercentBp, type Consumption, type RecipeDish, type RecipeShow, type RecipesPageProps, type RecipeVersion } from '@/modules/kitchen/lib/kitchen';
import { apiRequest } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';
import { router } from '@inertiajs/react';

type Row = { item_id: string; unit: string; quantity: string; waste: string };

/** The recipes of the dishes: what a dish takes, in versions that take effect on a date, what a portion costs, and what sales took out of the pantry. */
export default function RecipesPage({ consumed, overview }: RecipesPageProps) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [open, setOpen] = useState<RecipeShow | null>(null);
    const [form, setForm] = useState({ from: '', yield: '1', reason: '', rows: [] as Row[] });
    const [bad, setBad] = useState<string | null>(null);
    const [loadError, setLoadError] = useState(false);
    const money = (minor: number) => format.money(minor, overview.currency);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    function seed(show: RecipeShow) {
        const latest = show.versions[0];
        const first = show.ingredients[0];

        setForm({
            from: show.business_date > (latest?.effective_from ?? '') ? show.business_date : addDay(latest?.effective_from ?? show.business_date), yield: String(latest?.yield_portions ?? 1), reason: '',
            rows: latest !== undefined ? latest.lines.map((l) => ({ item_id: l.item_id, unit: l.unit, quantity: formatMilli(l.quantity_milli), waste: formatBp(l.waste_bp) })) : first !== undefined ? [{ item_id: first.id, unit: first.base_unit, quantity: '', waste: '0' }] : [],
        });
        setBad(null);
    }

    async function openDish(d: RecipeDish) {
        action.clear();
        setLoadError(false);
        try {
            const show = await apiRequest<RecipeShow>(`/kitchen/recipes/${d.id}`, { method: 'GET' });

            seed(show);
            setOpen(show);
        } catch {
            setLoadError(true);
        }
    }

    function change(index: number, patch: Partial<Row>) {
        setForm({ ...form, rows: form.rows.map((r, i) => (i === index ? { ...r, ...patch } : r)) });
    }

    async function save() {
        if (open === null) return;
        const lines = [];

        for (const r of form.rows) {
            const quantity = parseMilli(r.quantity);
            const waste = parsePercentBp(r.waste);

            if (quantity === null || waste === null || r.item_id === '') {
                setBad(t('recipes.badLine'));

                return;
            }
            lines.push({ item_id: r.item_id, unit: r.unit, quantity_milli: quantity, waste_bp: waste });
        }

        const portions = Number(form.yield);

        if (!Number.isInteger(portions) || portions < 1 || portions > 1000) {
            setBad(t('recipes.badYield'));

            return;
        }

        setBad(null);
        const done = await action.run<RecipeShow>(`/kitchen/recipes/${open.dish.id}`, { body: { effective_from: form.from, yield_portions: portions, reason: form.reason.trim(), lines } });

        if (done !== null) {
            setOpen(null);
            router.reload({ only: ['overview'] });
        }
    }

    const ingredient = (id: string) => open?.ingredients.find((i) => i.id === id);
    const dishColumns: DataGridColumn<RecipeDish>[] = [
        { id: 'name', label: t('recipes.dish'), value: (d) => d.name, rowHeader: true },
        { id: 'outlet', label: t('recipes.outlet'), value: (d) => d.outlet, filter: 'select' },
        { id: 'category', label: t('recipes.category'), value: (d) => d.category, filter: 'select' },
        { id: 'price', label: t('recipes.price'), align: 'right', value: (d) => d.price_minor, cell: (d) => money(d.price_minor) },
        {
            id: 'version', label: t('recipes.version'), value: (d) => (d.version === null ? 'none' : 'has'), filter: 'select', filterLabel: (v) => t(v === 'none' ? 'recipes.none' : 'recipes.has'),
            cell: (d) => (d.version === null ? <StatusBadge label={t('recipes.none')} tone="warning" /> : <span>{t('recipes.versionOf', { version: d.version, from: format.date(d.effective_from ?? '') })}{d.scheduled !== null ? <span className="block text-xs text-muted-foreground">{t('recipes.scheduled', { version: d.scheduled.version, from: format.date(d.scheduled.effective_from) })}</span> : null}</span>),
        },
        { id: 'ingredients', label: t('recipes.ingredients'), align: 'right', value: (d) => d.ingredients },
        { id: 'cost', label: t('recipes.cost'), align: 'right', value: (d) => d.cost_minor ?? 0, cell: (d) => (d.cost_minor === null ? '—' : <span>{money(d.cost_minor)}{!d.cost_complete ? <span className="block text-xs text-muted-foreground">{t('recipes.costPartial')}</span> : null}</span>) },
        { id: 'foodCost', label: t('recipes.foodCost'), align: 'right', value: (d) => d.food_cost_bp ?? 0, cell: (d) => (d.food_cost_bp === null ? '—' : `${(d.food_cost_bp / 100).toFixed(1)}%`) },
        { id: 'open', label: '', value: () => '', sortable: false, cell: (d) => <Button onClick={() => void openDish(d)} size="sm" type="button" variant="outline">{t(overview.may.manage ? 'recipes.edit' : 'recipes.view')}</Button> },
    ];
    const consumedColumns: DataGridColumn<Consumption>[] = [
        { id: 'at', label: t('recipes.colAt'), value: (c) => c.at, cell: (c) => format.instant(c.at) },
        { id: 'bill', label: t('recipes.colBill'), value: (c) => c.bill_number, rowHeader: true },
        { id: 'dish', label: t('recipes.dish'), value: (c) => c.item_name, filter: 'select' },
        { id: 'portions', label: t('recipes.colPortions'), align: 'right', value: (c) => c.portions },
        { id: 'ver', label: t('recipes.version'), value: (c) => c.version, cell: (c) => `v${c.version}` },
        { id: 'ing', label: t('recipes.colIngredient'), value: (c) => c.ingredient, filter: 'select' },
        { id: 'qty', label: t('recipes.colTaken'), align: 'right', value: (c) => c.quantity_milli, cell: (c) => `${formatMilli(c.quantity_milli)} ${c.unit}` },
    ];
    const versionCard = (v: RecipeVersion) => (
        <li className="flex flex-col gap-1 border border-border p-3 text-sm" key={v.id}>
            <span className="flex flex-wrap items-center gap-2 font-medium">{t('recipes.versionTitle', { version: v.version, from: format.date(v.effective_from) })}{v.scheduled ? <StatusBadge label={t('recipes.notYet')} tone="pending" /> : null}</span>
            <span className="text-muted-foreground">{t('recipes.versionMeta', { yield: v.yield_portions, reason: v.reason })}</span>
            <ul className="list-disc pl-5">{v.lines.map((l) => <li key={l.item_id}>{l.name}: {formatMilli(l.quantity_milli)} {l.unit}{l.waste_bp > 0 ? ` (+${formatBp(l.waste_bp)}%)` : ''}</li>)}</ul>
            <span>{t('recipes.portionCost', { cost: money(v.cost_minor) })}{!v.cost_complete ? ` · ${t('recipes.costPartial')}` : ''}</span>
        </li>
    );

    return (
        <KitchenShell description={t('recipes.description')} title={t('recipes.title')}>
            {loadError ? <Alert title={t('recipes.loadFailed')} tone="danger" /> : null}
            {overview.stock_location_id === null && overview.dishes.some((d) => d.version !== null) ? <Alert title={t('recipes.noLocation')} tone="warning" /> : null}
            <Tabs defaultValue="recipes">
                <TabsList aria-label={t('recipes.title')}>
                    <TabsTrigger value="recipes">{t('recipes.tabRecipes')}</TabsTrigger>
                    <TabsTrigger value="consumed">{t('recipes.tabConsumed')}</TabsTrigger>
                </TabsList>
                <TabsContent className="flex flex-col gap-3" value="recipes">
                    <DataGrid caption={t('recipes.tabRecipes')} columns={dishColumns} empty={<EmptyState illustration="coffee" title={t('recipes.empty')} />} getRowId={(d) => d.id} id="kitchen.recipes" rows={overview.dishes} testId="recipe-dishes" />
                </TabsContent>
                <TabsContent className="flex flex-col gap-3" value="consumed">
                    <p className="text-sm text-muted-foreground">{t('recipes.consumedHint')}</p>
                    <DataGrid caption={t('recipes.tabConsumed')} columns={consumedColumns} empty={<EmptyState illustration="coffee" title={t('recipes.consumedEmpty')} />} getRowId={(c) => c.id} id="kitchen.consumed" rows={consumed.consumptions} testId="recipe-consumed" />
                </TabsContent>
            </Tabs>

            <Dialog
                className="w-[min(46rem,calc(100vw-2rem))]"
                footer={open?.may.manage ? <>
                    <Button disabled={action.busy} onClick={() => setOpen(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button disabled={form.reason.trim() === '' || form.rows.length === 0} loading={action.busy} onClick={() => void save()} type="button">{t('recipes.save')}</Button>
                </> : <Button onClick={() => setOpen(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>}
                onClose={() => setOpen(null)}
                open={open !== null}
                title={open === null ? '' : t('recipes.dialogTitle', { name: open.dish.name })}
            >
                {open !== null && (
                    <div className="flex flex-col gap-4">
                        {failure}
                        {bad !== null ? <Alert title={bad} tone="warning" /> : null}
                        {open.versions.length > 0 ? <ul className="flex max-h-56 flex-col gap-2 overflow-y-auto" data-testid="recipe-versions">{open.versions.map(versionCard)}</ul> : <p className="text-sm text-muted-foreground">{t('recipes.noVersions')}</p>}
                        {open.may.manage ? (
                            <section aria-labelledby="recipe-new-h" className="flex flex-col gap-3 border-t border-border pt-4">
                                <h3 className="font-semibold" id="recipe-new-h">{t('recipes.newVersion')}</h3>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField error={action.fieldError('effective_from')} field="effective_from" hint={t('recipes.fromHint')} label={t('recipes.from')}><DatePicker min={open.business_date} onChange={(e) => setForm({ ...form, from: e.target.value })} value={form.from} /></FormField>
                                    <FormField error={action.fieldError('yield_portions')} field="yield_portions" hint={t('recipes.yieldHint')} label={t('recipes.yield')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, yield: e.target.value })} value={form.yield} /></FormField>
                                </div>
                                <div className="flex flex-col gap-2" data-testid="recipe-rows">
                                    {form.rows.map((r, i) => (
                                        <div className="grid items-end gap-2 sm:grid-cols-[2fr_1fr_1fr_1fr_auto]" key={`${r.item_id}-${i}`}>
                                            <FormField label={t('recipes.ingredient')}>
                                                <Select onChange={(e) => change(i, { item_id: e.target.value, unit: ingredient(e.target.value)?.base_unit ?? r.unit })} value={r.item_id}>{open.ingredients.map((g) => <option key={g.id} value={g.id}>{g.name} ({g.code})</option>)}</Select>
                                            </FormField>
                                            <FormField label={t('recipes.unit')}>
                                                <Select onChange={(e) => change(i, { unit: e.target.value })} value={r.unit}>{(ingredient(r.item_id)?.units ?? [r.unit]).map((u) => <option key={u} value={u}>{u}</option>)}</Select>
                                            </FormField>
                                            <FormField label={t('recipes.quantity')}><Input inputMode="decimal" onChange={(e) => change(i, { quantity: e.target.value })} value={r.quantity} /></FormField>
                                            <FormField label={t('recipes.waste')}><Input inputMode="decimal" onChange={(e) => change(i, { waste: e.target.value })} value={r.waste} /></FormField>
                                            <Button aria-label={t('recipes.removeRow')} onClick={() => setForm({ ...form, rows: form.rows.filter((_, j) => j !== i) })} size="sm" type="button" variant="outline">×</Button>
                                        </div>
                                    ))}
                                    {action.fieldError('lines') !== undefined ? <p className="text-sm text-danger">{action.fieldError('lines')}</p> : null}
                                    <div><Button disabled={open.ingredients.length === 0} onClick={() => setForm({ ...form, rows: [...form.rows, { item_id: open.ingredients[0]!.id, unit: open.ingredients[0]!.base_unit, quantity: '', waste: '0' }] })} size="sm" type="button" variant="outline">{t('recipes.addRow')}</Button></div>
                                </div>
                                <FormField error={action.fieldError('reason')} field="reason" label={t('recipes.reason')}><Input maxLength={200} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} /></FormField>
                            </section>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </KitchenShell>
    );
}

function addDay(date: string): string {
    const d = new Date(`${date}T00:00:00Z`);

    d.setUTCDate(d.getUTCDate() + 1);

    return d.toISOString().slice(0, 10);
}
