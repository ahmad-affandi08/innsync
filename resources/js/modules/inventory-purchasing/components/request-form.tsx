import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { minorToInput } from '@/modules/inventory-purchasing/lib/amounts';
import { nextLineKey, type ItemChoice } from '@/modules/inventory-purchasing/lib/purchasing';
import { plainMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

export type RequestLineForm = { key: string; item_id: string; unit: string; quantity: string; cost: string; note: string };
export type RequestFormState = { department: string; urgency: string; reason: string; needed_by: string; lines: RequestLineForm[] };

export const blankRequestLine = (): RequestLineForm => ({ key: nextLineKey(), item_id: '', unit: '', quantity: '', cost: '', note: '' });

export const blankRequest = (departments: string[]): RequestFormState => ({ department: departments[0] ?? '', urgency: 'normal', reason: '', needed_by: '', lines: [blankRequestLine()] });

type SavedLine = { item_id: string; unit: string; qty_milli: number; est_unit_cost_minor: number; note: string | null };

/** The editable form of a draft request, with every amount turned back into what a person types. */
export function requestToForm(r: { department: string; urgency: string; reason: string; needed_by: string; lines: SavedLine[] }, currency: string): RequestFormState {
    return {
        department: r.department, urgency: r.urgency, reason: r.reason, needed_by: r.needed_by,
        lines: r.lines.map((l) => ({ key: nextLineKey(), item_id: l.item_id, unit: l.unit, quantity: plainMilli(l.qty_milli), cost: l.est_unit_cost_minor > 0 ? minorToInput(l.est_unit_cost_minor, currency) : '', note: l.note ?? '' })),
    };
}

/** The lines as the server wants them (cost in minor units); `bad` names the lines whose typed cost is not a clear amount. */
export function requestLinesBody(form: RequestFormState, currency: string) {
    const bad: string[] = [];
    const lines = form.lines.map((l) => {
        const minor = l.cost.trim() === '' ? null : parseMajorToMinor(l.cost, currency);

        if (l.cost.trim() !== '' && minor === null) bad.push(l.key);

        return { item_id: l.item_id, unit: l.unit, quantity: l.quantity, est_cost_minor: minor, note: l.note.trim() === '' ? null : l.note.trim() };
    });

    return { lines, bad };
}

type Props = {
    form: RequestFormState;
    onChange: (next: RequestFormState) => void;
    items: ItemChoice[];
    departments: string[];
    urgencies: string[];
    currency: string;
    fieldError: (name: string) => string | undefined;
    badCosts: string[];
};

/** The fields of a purchase request, shared by "New request" and "Edit". The page sends them; the server validates and totals them. */
export function RequestFields({ badCosts, currency, departments, fieldError, form, items, onChange, urgencies }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();

    const suggestion = (itemId: string, unit: string) => items.find((i) => i.id === itemId)?.suggested_cost_minor?.[unit] ?? null;
    const asText = (minor: number | null) => (minor === null ? '' : minorToInput(minor, currency));

    function setLine(key: string, patch: Partial<RequestLineForm>) {
        onChange({ ...form, lines: form.lines.map((l) => (l.key === key ? { ...l, ...patch } : l)) });
    }

    /** Changing the item or the unit refreshes the estimate, unless the person typed their own. */
    function retarget(line: RequestLineForm, itemId: string, unit: string) {
        const fresh = asText(suggestion(itemId, unit));
        const untouched = line.cost === '' || line.cost === asText(suggestion(line.item_id, line.unit));

        setLine(line.key, { item_id: itemId, unit, cost: untouched ? fresh : line.cost });
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <FormField error={fieldError('department')} field="department" label={t('inv.col.department')}>
                <Select onChange={(e) => onChange({ ...form, department: e.target.value })} value={form.department}>
                    {departments.map((d) => <option key={d} value={d}>{t(`inv.dept.${d}` as MessageKey)}</option>)}
                </Select>
            </FormField>
            <FormField error={fieldError('urgency')} field="urgency" label={t('inv.req.urgency')}>
                <Select onChange={(e) => onChange({ ...form, urgency: e.target.value })} searchable={false} value={form.urgency}>
                    {urgencies.map((u) => <option key={u} value={u}>{t(`inv.req.urgency.${u}` as MessageKey)}</option>)}
                </Select>
            </FormField>
            <div className="sm:col-span-2">
                <FormField error={fieldError('reason')} field="reason" label={t('inv.req.reason')}>
                    <Input maxLength={200} onChange={(e) => onChange({ ...form, reason: e.target.value })} value={form.reason} />
                </FormField>
            </div>
            <FormField error={fieldError('needed_by')} field="needed_by" label={t('inv.req.neededBy')}>
                <DatePicker onChange={(e) => onChange({ ...form, needed_by: e.target.value })} value={form.needed_by} />
            </FormField>
            <div className="flex flex-col gap-3 sm:col-span-2">
                <h3 className="text-sm font-semibold">{t('inv.req.linesHeading')}</h3>
                {fieldError('lines') ? <p className="text-sm text-danger">{fieldError('lines')}</p> : null}
                {form.lines.map((l) => {
                    const item = items.find((x) => x.id === l.item_id);
                    const suggested = suggestion(l.item_id, l.unit);

                    return (
                        <div className="grid gap-3 border-t border-border pt-3 sm:grid-cols-[minmax(0,2fr)_6rem_7rem_9rem_minmax(0,1.5fr)_auto]" data-testid="request-line" key={l.key}>
                            <FormField field="lines.*.item_id" label={t('inv.col.lineItem')}>
                                <Select onChange={(e) => { const next = items.find((x) => x.id === e.target.value); retarget(l, e.target.value, next?.base_unit ?? ''); }} value={l.item_id}>
                                    <option value="">{t('inv.req.chooseItem')}</option>
                                    {items.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                                </Select>
                            </FormField>
                            <FormField field="lines.*.unit" label={t('inv.col.unit')}>
                                <Select onChange={(e) => retarget(l, l.item_id, e.target.value)} searchable={false} value={l.unit}>
                                    {item === undefined ? null : item.units.map((u) => <option key={u} value={u}>{u}</option>)}
                                </Select>
                            </FormField>
                            <FormField field="lines.*.quantity" label={t('inv.opening.quantity')}>
                                <Input inputMode="decimal" onChange={(e) => setLine(l.key, { quantity: e.target.value })} value={l.quantity} />
                            </FormField>
                            <FormField error={badCosts.includes(l.key) ? t('fo.folio.invalidAmount') : undefined} field="lines.*.est_cost_minor" hint={suggested === null ? undefined : t('inv.req.suggested', { amount: format.money(suggested, currency) })} label={t('inv.req.estCost', { currency })}>
                                <Input inputMode="decimal" onChange={(e) => setLine(l.key, { cost: e.target.value })} value={l.cost} />
                            </FormField>
                            <FormField field="lines.*.note" label={t('inv.col.note')}>
                                <Input maxLength={200} onChange={(e) => setLine(l.key, { note: e.target.value })} value={l.note} />
                            </FormField>
                            <div className="flex items-end">
                                {form.lines.length > 1 ? <Button onClick={() => onChange({ ...form, lines: form.lines.filter((x) => x.key !== l.key) })} size="sm" type="button" variant="outline">{t('inv.trf.removeLine')}</Button> : null}
                            </div>
                        </div>
                    );
                })}
                <div><Button onClick={() => onChange({ ...form, lines: [...form.lines, blankRequestLine()] })} size="sm" type="button" variant="outline">{t('inv.trf.addLine')}</Button></div>
            </div>
        </div>
    );
}
