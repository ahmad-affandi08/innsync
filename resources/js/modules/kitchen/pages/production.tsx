import { router } from '@inertiajs/react';
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
import { formatMilli, parseMilli, type Formula, type ProductionBatch, type ProductionOverview } from '@/modules/kitchen/lib/kitchen';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Row = { item: string; unit: string; quantity: string };
type FormulaForm = { code: string; name: string; output: string; unit: string; standard: string; rows: Row[] };
type BatchForm = { formula: string; batches: string; actual: string; expires: string; note: string };

const percent = (bp: number) => `${(bp / 100).toFixed(1)}%`;

/** Preparation batches: the formulas of the semi-finished goods, and what each batch took and really made. */
export default function ProductionPage({ overview }: { overview: ProductionOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [formula, setFormula] = useState<FormulaForm | null>(null);
    const [batch, setBatch] = useState<BatchForm | null>(null);
    const [retire, setRetire] = useState<{ formula: Formula; reason: string } | null>(null);
    const [bad, setBad] = useState(false);
    const [saved, setSaved] = useState(false);
    const reload = ['overview'];
    const money = (minor: number) => format.money(minor, overview.currency);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const active = overview.formulas.filter((f) => f.is_active);
    const chosen = batch === null ? undefined : overview.formulas.find((f) => f.id === batch.formula);
    const batchCount = batch === null ? 0 : Number(batch.batches) || 0;
    const outputItem = formula === null ? undefined : overview.items.find((i) => i.id === formula.output);

    function startFormula() {
        action.clear();
        setBad(false);
        setFormula({ code: '', name: '', output: '', unit: '', standard: '', rows: [{ item: '', unit: '', quantity: '' }] });
    }

    function startBatch() {
        action.clear();
        setBad(false);
        setSaved(false);
        setBatch({ formula: active[0]?.id ?? '', batches: '1', actual: '', expires: '', note: '' });
    }

    function setRow(index: number, patch: Partial<Row>) {
        if (formula === null) return;
        setFormula({ ...formula, rows: formula.rows.map((r, i) => (i === index ? { ...r, ...patch } : r)) });
    }

    async function saveFormula() {
        if (formula === null) return;
        const standard = parseMilli(formula.standard);
        const lines = formula.rows.map((r) => ({ item_id: r.item, unit: r.unit, quantity_milli: parseMilli(r.quantity) ?? 0 }));

        if (standard === null || formula.output === '' || lines.some((l) => l.item_id === '' || l.unit === '' || l.quantity_milli === 0)) {
            setBad(true);

            return;
        }

        setBad(false);
        const done = await action.run('/kitchen/production/formulas', { body: { code: formula.code.trim(), name: formula.name.trim(), output_item_id: formula.output, output_unit: formula.unit, standard_output_milli: standard, lines }, reload });
        if (done !== null) setFormula(null);
    }

    async function saveBatch() {
        if (batch === null) return;
        const actual = parseMilli(batch.actual);

        if (actual === null || batch.formula === '' || batchCount < 1) {
            setBad(true);

            return;
        }

        setBad(false);
        const done = await action.run('/kitchen/production', { idempotencyKey: newIdempotencyKey(), body: { formula_id: batch.formula, batches: batchCount, actual_output_milli: actual, expires_on: batch.expires === '' ? null : batch.expires, note: batch.note.trim() === '' ? null : batch.note.trim() }, reload });

        if (done !== null) {
            setBatch(null);
            setSaved(true);
            router.reload({ only: ['overview'] });
        }
    }

    async function submitRetire() {
        if (retire === null) return;
        const done = await action.run(`/kitchen/production/formulas/${retire.formula.id}/retire`, { body: { reason: retire.reason.trim() }, reload });
        if (done !== null) setRetire(null);
    }

    const batchColumns: DataGridColumn<ProductionBatch>[] = [
        { id: 'number', label: t('kitchen.prod.colNumber'), value: (b) => b.number, rowHeader: true, cell: (b) => <span>{b.number}<span className="block text-xs text-muted-foreground">{format.instant(b.at)}{b.by !== null ? ` · ${b.by}` : ''}</span></span> },
        { id: 'what', label: t('kitchen.prod.colWhat'), value: (b) => b.output_name, cell: (b) => <span>{b.output_name}<span className="block text-xs text-muted-foreground">{b.formula_code} × {b.batches}{b.expires_on !== null ? ` · ${t('kitchen.prod.expires', { date: format.date(b.expires_on) })}` : ''}</span></span> },
        { id: 'made', label: t('kitchen.prod.colMade'), align: 'right', value: (b) => b.actual_output_milli, cell: (b) => `${formatMilli(b.actual_output_milli)} ${b.output_unit}` },
        { id: 'yield', label: t('kitchen.prod.colYield'), align: 'right', value: (b) => b.yield_bp, cell: (b) => <span>{percent(b.yield_bp)}<span className="block text-xs text-muted-foreground">{t('kitchen.prod.standard', { qty: `${formatMilli(b.standard_output_milli)} ${b.output_unit}` })}</span></span> },
        { id: 'cost', label: t('kitchen.prod.colCost'), align: 'right', value: (b) => b.input_value_minor, cell: (b) => <span>{money(b.input_value_minor)}{!b.input_value_complete ? <span className="block text-xs text-muted-foreground">{t('kitchen.prod.costPartial')}</span> : null}</span> },
        { id: 'inputs', label: t('kitchen.prod.colInputs'), value: (b) => b.lines.length, sortable: false, cell: (b) => b.lines.map((l) => `${l.name} ${formatMilli(l.quantity_milli)} ${l.unit}`).join(', ') },
    ];
    const formulaColumns: DataGridColumn<Formula>[] = [
        { id: 'code', label: t('kitchen.prod.colCode'), value: (f) => f.code, rowHeader: true, cell: (f) => <span>{f.code}<span className="block text-xs text-muted-foreground">{f.name}</span></span> },
        { id: 'product', label: t('kitchen.prod.colProduct'), value: (f) => f.output_name, cell: (f) => `${f.output_name} · ${formatMilli(f.standard_output_milli)} ${f.output_unit}` },
        { id: 'lines', label: t('kitchen.prod.colInputs'), value: (f) => f.lines.length, sortable: false, cell: (f) => f.lines.map((l) => `${l.name} ${formatMilli(l.quantity_milli)} ${l.unit}`).join(', ') },
        { id: 'status', label: t('kitchen.prod.colStatus'), value: (f) => (f.is_active ? 'active' : 'retired'), cell: (f) => (f.is_active ? <StatusBadge label={t('kitchen.prod.inUse')} tone="success" /> : <span><StatusBadge label={t('kitchen.prod.retired')} tone="neutral" /><span className="block text-xs text-muted-foreground">{f.retire_reason}</span></span>) },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (f) => (overview.may.manage && f.is_active ? <Button disabled={action.busy} onClick={() => { action.clear(); setRetire({ formula: f, reason: '' }); }} size="sm" type="button" variant="outline">{t('kitchen.prod.retire')}</Button> : null) },
    ];

    return (
        <KitchenShell
            actions={<>{overview.may.record ? <Button disabled={active.length === 0 || !overview.has_location} onClick={startBatch} type="button">{t('kitchen.prod.record')}</Button> : null}{overview.may.manage ? <Button onClick={startFormula} type="button" variant="outline">{t('kitchen.prod.addFormula')}</Button> : null}</>}
            description={t('kitchen.prod.description')}
            title={t('kitchen.prod.title')}
        >
            {!overview.has_location && overview.may.record ? <Alert title={t('waste.noLocation')} tone="warning" /> : null}
            {saved ? <Alert title={t('kitchen.prod.saved')} tone="success" /> : null}
            {action.error !== null && formula === null && batch === null && retire === null ? failure : null}
            <Tabs defaultValue="batches">
                <TabsList aria-label={t('kitchen.prod.title')}>
                    <TabsTrigger value="batches">{t('kitchen.prod.batchesTab')}</TabsTrigger>
                    <TabsTrigger value="formulas">{t('kitchen.prod.formulasTab')}</TabsTrigger>
                </TabsList>
                <TabsContent value="batches">
                    <DataGrid caption={t('kitchen.prod.batchesTab')} columns={batchColumns} empty={<EmptyState illustration="coffee" title={t('kitchen.prod.noBatches')} />} getRowId={(b) => b.id} id="kitchen.production" rows={overview.batches} testId="production-batches" />
                </TabsContent>
                <TabsContent value="formulas">
                    <DataGrid caption={t('kitchen.prod.formulasTab')} columns={formulaColumns} empty={<EmptyState illustration="coffee" title={t('kitchen.prod.noFormulas')} />} getRowId={(f) => f.id} id="kitchen.formulas" rows={overview.formulas} testId="production-formulas" />
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setBatch(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveBatch()} type="button">{t('kitchen.prod.save')}</Button></>}
                onClose={() => setBatch(null)}
                open={batch !== null}
                title={t('kitchen.prod.record')}
            >
                {batch !== null && (
                    <div className="flex flex-col gap-3">
                        {failure}
                        {bad ? <Alert title={t('kitchen.prod.bad')} tone="warning" /> : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={action.fieldError('formula_id')} field="formula_id" label={t('kitchen.prod.formula')}>
                                <Select onChange={(e) => setBatch({ ...batch, formula: e.target.value })} value={batch.formula}>{active.map((f) => <option key={f.id} value={f.id}>{f.code} · {f.name}</option>)}</Select>
                            </FormField>
                            <FormField error={action.fieldError('batches')} field="batches" label={t('kitchen.prod.batches')}><Input inputMode="numeric" onChange={(e) => setBatch({ ...batch, batches: e.target.value.replace(/\D/g, '') })} value={batch.batches} /></FormField>
                        </div>
                        {chosen !== undefined ? (
                            <div className="border border-border p-3 text-sm" data-testid="production-plan">
                                <p className="font-medium">{t('kitchen.prod.takes')}</p>
                                <ul className="mt-1 text-muted-foreground">{chosen.lines.map((l) => <li key={l.item_id}>{l.name}: {formatMilli(l.quantity_milli * batchCount)} {l.unit}</li>)}</ul>
                                <p className="mt-2">{t('kitchen.prod.shouldMake', { qty: `${formatMilli(chosen.standard_output_milli * batchCount)} ${chosen.output_unit}`, name: chosen.output_name })}</p>
                            </div>
                        ) : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={action.fieldError('actual_output_milli')} field="actual_output_milli" hint={t('kitchen.prod.actualHint', { unit: chosen?.output_unit ?? '' })} label={t('kitchen.prod.actual')}><Input inputMode="decimal" onChange={(e) => setBatch({ ...batch, actual: e.target.value })} value={batch.actual} /></FormField>
                            <FormField error={action.fieldError('expires_on')} field="expires_on" hint={t('kitchen.prod.expiresHint')} label={t('kitchen.prod.expiresOn')}><DatePicker onChange={(e) => setBatch({ ...batch, expires: e.target.value })} value={batch.expires} /></FormField>
                        </div>
                        <FormField error={action.fieldError('note')} field="note" label={t('kitchen.prod.note')}><Input maxLength={200} onChange={(e) => setBatch({ ...batch, note: e.target.value })} value={batch.note} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setFormula(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveFormula()} type="button">{t('kitchen.prod.saveFormula')}</Button></>}
                onClose={() => setFormula(null)}
                open={formula !== null}
                title={t('kitchen.prod.addFormula')}
            >
                {formula !== null && (
                    <div className="flex flex-col gap-3">
                        {failure}
                        {bad ? <Alert title={t('kitchen.prod.badFormula')} tone="warning" /> : null}
                        <p className="text-sm text-muted-foreground">{t('kitchen.prod.formulaHint')}</p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={action.fieldError('code')} field="code" label={t('kitchen.prod.colCode')}><Input maxLength={20} onChange={(e) => setFormula({ ...formula, code: e.target.value })} value={formula.code} /></FormField>
                            <FormField error={action.fieldError('name')} field="name" label={t('kitchen.prod.name')}><Input maxLength={80} onChange={(e) => setFormula({ ...formula, name: e.target.value })} value={formula.name} /></FormField>
                            <FormField error={action.fieldError('output_item_id')} field="output_item_id" label={t('kitchen.prod.colProduct')}>
                                <Select onChange={(e) => setFormula({ ...formula, output: e.target.value, unit: overview.items.find((i) => i.id === e.target.value)?.base_unit ?? '' })} value={formula.output}>
                                    <option value="">—</option>
                                    {overview.items.map((i) => <option key={i.id} value={i.id}>{i.name} ({i.code})</option>)}
                                </Select>
                            </FormField>
                            <FormField error={action.fieldError('output_unit')} field="output_unit" label={t('waste.unit')}>
                                <Select onChange={(e) => setFormula({ ...formula, unit: e.target.value })} value={formula.unit}>{(outputItem?.units ?? []).map((u) => <option key={u} value={u}>{u}</option>)}</Select>
                            </FormField>
                            <FormField error={action.fieldError('standard_output_milli')} field="standard_output_milli" hint={t('kitchen.prod.standardHint')} label={t('kitchen.prod.standardOutput')}><Input inputMode="decimal" onChange={(e) => setFormula({ ...formula, standard: e.target.value })} value={formula.standard} /></FormField>
                        </div>
                        <fieldset className="flex flex-col gap-2">
                            <legend className="text-sm font-medium">{t('recipes.ingredients')}</legend>
                            {formula.rows.map((r, index) => {
                                const item = overview.items.find((i) => i.id === r.item);

                                return (
                                    <div className="grid grid-cols-[2fr_1fr_1fr_auto] items-end gap-2" key={index}>
                                        <Select aria-label={t('recipes.ingredient')} onChange={(e) => setRow(index, { item: e.target.value, unit: overview.items.find((i) => i.id === e.target.value)?.base_unit ?? '' })} value={r.item}>
                                            <option value="">—</option>
                                            {overview.items.map((i) => <option key={i.id} value={i.id}>{i.name} ({i.code})</option>)}
                                        </Select>
                                        <Input aria-label={t('recipes.quantity')} inputMode="decimal" onChange={(e) => setRow(index, { quantity: e.target.value })} placeholder={t('recipes.quantity')} value={r.quantity} />
                                        <Select aria-label={t('recipes.unit')} onChange={(e) => setRow(index, { unit: e.target.value })} value={r.unit}>{(item?.units ?? []).map((u) => <option key={u} value={u}>{u}</option>)}</Select>
                                        <Button aria-label={t('recipes.removeRow')} disabled={formula.rows.length === 1} onClick={() => setFormula({ ...formula, rows: formula.rows.filter((_, i) => i !== index) })} size="sm" type="button" variant="outline">×</Button>
                                    </div>
                                );
                            })}
                            <div><Button onClick={() => setFormula({ ...formula, rows: [...formula.rows, { item: '', unit: '', quantity: '' }] })} size="sm" type="button" variant="outline">{t('recipes.addRow')}</Button></div>
                        </fieldset>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setRetire(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={retire === null || retire.reason.trim() === ''} loading={action.busy} onClick={() => void submitRetire()} type="button">{t('kitchen.prod.retireConfirm')}</Button></>}
                onClose={() => setRetire(null)}
                open={retire !== null}
                title={retire === null ? '' : t('kitchen.prod.retireTitle', { name: retire.formula.name })}
            >
                {retire !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('kitchen.prod.retireHint')}</p>
                        {failure}
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fnb.px.reason')}><Input maxLength={200} onChange={(e) => setRetire({ ...retire, reason: e.target.value })} value={retire.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </KitchenShell>
    );
}
