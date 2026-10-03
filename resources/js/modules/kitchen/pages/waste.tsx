import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { KitchenShell } from '@/modules/kitchen/components/kitchen-shell';
import { formatMilli, parseMilli, type WasteEntry, type WasteOverview } from '@/modules/kitchen/lib/kitchen';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

/** The waste log: what was thrown away and why, what it cost, and a form to record it. */
export default function WastePage({ overview }: { overview: WasteOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [kind, setKind] = useState<'ingredient' | 'dish'>('ingredient');
    const [item, setItem] = useState('');
    const [unit, setUnit] = useState('');
    const [quantity, setQuantity] = useState('');
    const [portions, setPortions] = useState('1');
    const [reason, setReason] = useState('spoiled');
    const [reference, setReference] = useState('');
    const [note, setNote] = useState('');
    const [bad, setBad] = useState(false);
    const [saved, setSaved] = useState(false);
    const money = (minor: number) => format.money(minor, overview.currency);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const ingredient = overview.ingredients.find((i) => i.id === item);
    const choices = kind === 'ingredient' ? overview.ingredients.map((i) => ({ id: i.id, label: `${i.name} (${i.code})` })) : overview.dishes.map((d) => ({ id: d.id, label: `${d.name} · ${d.outlet}` }));
    const thirty = overview.summary.by_reason.reduce((sum, r) => sum + r.value_minor, 0);
    const reasonLabel = (r: string) => t(`waste.reason.${r}` as MessageKey);

    function pickKind(next: 'ingredient' | 'dish') {
        setKind(next);
        setItem('');
        setUnit('');
    }

    function pickItem(id: string) {
        setItem(id);
        setUnit(overview.ingredients.find((i) => i.id === id)?.base_unit ?? '');
    }

    async function save() {
        const milli = kind === 'ingredient' ? parseMilli(quantity) : null;
        const count = Number(portions);
        const invalid = item === '' || (kind === 'ingredient' ? milli === null : !Number.isInteger(count) || count < 1 || count > 999);

        setBad(invalid);
        setSaved(false);
        if (invalid) return;
        const body = kind === 'ingredient' ? { kind, item_id: item, quantity_milli: milli, unit } : { kind, item_id: item, portions: count };
        const done = await action.run('/kitchen/waste', { idempotencyKey: newIdempotencyKey(), body: { ...body, reason, reference: reference.trim() === '' ? null : reference.trim(), note: note.trim() === '' ? null : note.trim() } });

        if (done !== null) {
            setSaved(true);
            setQuantity('');
            setReference('');
            setNote('');
            router.reload({ only: ['overview'] });
        }
    }

    const columns: DataGridColumn<WasteEntry>[] = [
        { id: 'number', label: t('waste.colNumber'), value: (w) => w.number, rowHeader: true },
        { id: 'at', label: t('waste.colAt'), value: (w) => w.at, cell: (w) => format.instant(w.at) },
        {
            id: 'what', label: t('waste.colWhat'), value: (w) => w.dish ?? w.lines[0]?.name ?? '', searchText: (w) => `${w.dish ?? ''} ${w.lines.map((l) => l.name).join(' ')}`,
            cell: (w) => <span>{w.kind === 'dish' ? t('waste.dishPortions', { dish: w.dish ?? '', portions: w.portions ?? 0 }) : t('waste.ingredientQty', { name: w.lines[0]?.name ?? '', qty: `${formatMilli(w.lines[0]?.quantity_milli ?? 0)} ${w.lines[0]?.unit ?? ''}` })}<span className="block text-xs text-muted-foreground">{w.kind === 'dish' ? w.lines.map((l) => `${l.name} ${formatMilli(l.quantity_milli)} ${l.unit}`).join(', ') : ''}</span></span>,
        },
        { id: 'reason', label: t('waste.colReason'), value: (w) => w.reason, filter: 'select', filterLabel: reasonLabel, cell: (w) => <span>{reasonLabel(w.reason)}{w.note !== null ? <span className="block text-xs text-muted-foreground">{w.note}</span> : null}</span> },
        { id: 'ref', label: t('waste.colReference'), value: (w) => w.reference ?? '—' },
        { id: 'by', label: t('waste.colBy'), value: (w) => w.by ?? '—', filter: 'select' },
        { id: 'cost', label: t('waste.colCost'), align: 'right', value: (w) => w.value_minor ?? 0, cell: (w) => <span>{money(w.value_minor ?? 0)}{!w.value_complete ? <span className="block text-xs text-muted-foreground">{t('waste.costPartial')}</span> : null}</span> },
    ];

    return (
        <KitchenShell description={t('waste.description')} title={t('waste.title')}>
            {!overview.has_location && overview.may.record ? <Alert title={t('waste.noLocation')} tone="warning" /> : null}
            <div className="grid gap-3 sm:grid-cols-2" data-testid="waste-kpis">
                <Metric label={t('waste.today')} value={money(overview.summary.today_minor)} />
                <Metric detail={overview.summary.by_reason.map((r) => `${reasonLabel(r.reason)}: ${money(r.value_minor)}`).join(' · ')} label={t('waste.thirty')} value={money(thirty)} />
            </div>

            {overview.may.record ? (
                <section aria-labelledby="waste-new-h" className="flex max-w-3xl flex-col gap-3 border border-border bg-surface p-4">
                    <h2 className="text-lg font-semibold" id="waste-new-h">{t('waste.new')}</h2>
                    {failure}
                    {saved ? <Alert title={t('waste.saved')} tone="success" /> : null}
                    {bad ? <Alert title={t('waste.bad')} tone="warning" /> : null}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField error={action.fieldError('kind')} field="kind" label={t('waste.kind')}>
                            <Select onChange={(e) => pickKind(e.target.value as 'ingredient' | 'dish')} value={kind}>
                                <option value="ingredient">{t('waste.kind.ingredient')}</option>
                                <option value="dish">{t('waste.kind.dish')}</option>
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('item_id')} field="item_id" hint={kind === 'dish' ? t('waste.dishHint') : undefined} label={kind === 'ingredient' ? t('waste.ingredient') : t('waste.dish')}>
                            <Select onChange={(e) => pickItem(e.target.value)} value={item}>
                                <option value="">—</option>
                                {choices.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
                            </Select>
                        </FormField>
                        {kind === 'ingredient' ? (
                            <>
                                <FormField error={action.fieldError('quantity_milli')} field="quantity_milli" label={t('waste.quantity')}><Input inputMode="decimal" onChange={(e) => setQuantity(e.target.value)} value={quantity} /></FormField>
                                <FormField error={action.fieldError('unit')} field="unit" label={t('waste.unit')}>
                                    <Select onChange={(e) => setUnit(e.target.value)} value={unit}>{(ingredient?.units ?? []).map((u) => <option key={u} value={u}>{u}</option>)}</Select>
                                </FormField>
                            </>
                        ) : (
                            <FormField error={action.fieldError('portions')} field="portions" label={t('waste.portions')}><Input inputMode="numeric" onChange={(e) => setPortions(e.target.value)} value={portions} /></FormField>
                        )}
                        <FormField error={action.fieldError('reason')} field="reason" label={t('waste.reason')}>
                            <Select onChange={(e) => setReason(e.target.value)} value={reason}>{overview.reasons.map((r) => <option key={r} value={r}>{reasonLabel(r)}</option>)}</Select>
                        </FormField>
                        <FormField error={action.fieldError('reference')} field="reference" hint={t('waste.referenceHint')} label={t('waste.reference')}><Input maxLength={40} onChange={(e) => setReference(e.target.value)} value={reference} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('note')} field="note" label={t('waste.note')}><Input maxLength={200} onChange={(e) => setNote(e.target.value)} value={note} /></FormField></div>
                    </div>
                    <div><Button disabled={!overview.has_location} loading={action.busy} onClick={() => void save()} type="button">{t('waste.save')}</Button></div>
                </section>
            ) : null}

            <DataGrid caption={t('waste.title')} columns={columns} empty={<EmptyState illustration="coffee" title={t('waste.empty')} />} getRowId={(w) => w.id} id="kitchen.waste" rows={overview.entries} testId="waste-entries" />
        </KitchenShell>
    );
}
