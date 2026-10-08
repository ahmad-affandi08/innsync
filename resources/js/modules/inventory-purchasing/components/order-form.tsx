import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { bpToInput, minorToInput, parsePercentToBp } from '@/modules/inventory-purchasing/lib/amounts';
import { nextLineKey, type ItemChoice } from '@/modules/inventory-purchasing/lib/purchasing';
import { formatMilli, plainMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useTranslation } from '@/shared/i18n/i18n';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

export type Party = { id: string; code: string; name: string; payment_terms_days?: number };
export type RequestLineChoice = { id: string; request_number: string; item_code: string; item_name: string; unit: string; qty_milli: number; department: string };

/** The header of an order as typed; `tax_percent` is a percentage, the server gets basis points. */
export type OrderHeaderState = { supplier_id: string; location_id: string; expected_date: string; tax_percent: string; note: string };
export type OrderLineForm = {
    key: string; source: 'request' | 'manual';
    /** A line of a saved draft that came from a request cannot be swapped for another request line. */
    locked: boolean; summary: string;
    request_line_id: string; item_id: string; unit: string; quantity: string; price: string; department: string;
};
export type OrderFormState = OrderHeaderState & { lines: OrderLineForm[] };

export const blankOrderLine = (source: 'request' | 'manual'): OrderLineForm => ({ key: nextLineKey(), source, locked: false, summary: '', request_line_id: '', item_id: '', unit: '', quantity: '', price: '', department: '' });

export function blankOrder(overview: { suppliers: Party[]; locations: Party[]; tax_bp: number; request_lines?: RequestLineChoice[] }): OrderFormState {
    return {
        supplier_id: overview.suppliers[0]?.id ?? '', location_id: overview.locations[0]?.id ?? '', expected_date: '', tax_percent: bpToInput(overview.tax_bp), note: '',
        lines: [blankOrderLine((overview.request_lines?.length ?? 0) > 0 ? 'request' : 'manual')],
    };
}

type SavedLine = { item_code: string; item_name: string; item_id: string; unit: string; qty_milli: number; unit_price_minor: number; department: string | null; request_line_id: string | null; is_active: boolean };

/** The editable form of a draft order. Lines that came from a request keep their link and show only a summary. */
export function orderToForm(o: { supplier: { id: string }; location: { id: string }; expected_date: string | null; tax_bp: number; note: string | null; lines: SavedLine[] }, currency: string): OrderFormState {
    return {
        supplier_id: o.supplier.id, location_id: o.location.id, expected_date: o.expected_date ?? '', tax_percent: bpToInput(o.tax_bp), note: o.note ?? '',
        lines: o.lines.filter((l) => l.is_active).map((l) => ({
            key: nextLineKey(), source: l.request_line_id === null ? 'manual' : 'request', locked: l.request_line_id !== null, summary: `${l.item_code} · ${l.item_name} · ${plainMilli(l.qty_milli)} ${l.unit}`,
            request_line_id: l.request_line_id ?? '', item_id: l.item_id, unit: l.unit, quantity: plainMilli(l.qty_milli), price: minorToInput(l.unit_price_minor, currency), department: l.department ?? '',
        })),
    };
}

/** What the server takes for a price: whole minor units, or nothing so that the supplier's price list decides. `bad` is true when the text is not a clear amount. */
export function priceOf(text: string, currency: string): { minor: number | undefined; bad: boolean } {
    if (text.trim() === '') return { minor: undefined, bad: false };
    const minor = parseMajorToMinor(text, currency);

    return minor === null ? { minor: undefined, bad: true } : { minor, bad: false };
}

/** The header and lines as the server wants them. `bad` lists the lines with an unclear price; `taxBp` is null when the percentage is not clear. */
export function orderBody(form: OrderFormState, currency: string) {
    const bad: string[] = [];
    const lines = form.lines.map((l) => {
        const price = priceOf(l.price, currency);

        if (price.bad) bad.push(l.key);

        return l.source === 'request'
            ? { request_line_id: l.request_line_id, unit_price_minor: price.minor }
            : { item_id: l.item_id, unit: l.unit, quantity: l.quantity, unit_price_minor: price.minor, department: l.department === '' ? undefined : l.department };
    });

    return { lines, bad, taxBp: parsePercentToBp(form.tax_percent) };
}

type HeaderProps = {
    form: OrderHeaderState;
    onChange: (patch: Partial<OrderHeaderState>) => void;
    suppliers: Party[];
    locations: Party[];
    fieldError: (name: string) => string | undefined;
    badTax: boolean;
};

/** Supplier, location, expected date, tax rate and note: shared by new, edit and revise. */
export function OrderHeaderFields({ badTax, fieldError, form, locations, onChange, suppliers }: HeaderProps) {
    const { t } = useTranslation();
    const supplier = suppliers.find((s) => s.id === form.supplier_id);

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            <FormField error={fieldError('supplier_id')} field="supplier_id" hint={supplier?.payment_terms_days === undefined ? undefined : t('inv.po.terms', { count: supplier.payment_terms_days })} label={t('inv.po.supplier')}>
                <Select onChange={(e) => onChange({ supplier_id: e.target.value })} value={form.supplier_id}>
                    {suppliers.map((s) => <option key={s.id} value={s.id}>{s.code} · {s.name}</option>)}
                </Select>
            </FormField>
            <FormField error={fieldError('location_id')} field="location_id" label={t('inv.col.location')}>
                <Select onChange={(e) => onChange({ location_id: e.target.value })} value={form.location_id}>
                    {locations.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.name}</option>)}
                </Select>
            </FormField>
            <FormField error={fieldError('expected_date')} field="expected_date" label={t('inv.po.expected')}>
                <DatePicker onChange={(e) => onChange({ expected_date: e.target.value })} value={form.expected_date} />
            </FormField>
            <FormField error={badTax ? t('inv.po.invalidPercent') : fieldError('tax_bp')} field="tax_bp" hint={t('inv.po.taxHint')} label={t('inv.po.taxRate')}>
                <Input inputMode="decimal" onChange={(e) => onChange({ tax_percent: e.target.value })} value={form.tax_percent} />
            </FormField>
            <div className="sm:col-span-2">
                <FormField error={fieldError('note')} field="note" label={t('inv.opening.note')}>
                    <Input maxLength={200} onChange={(e) => onChange({ note: e.target.value })} value={form.note} />
                </FormField>
            </div>
        </div>
    );
}

type LinesProps = {
    form: OrderFormState;
    onChange: (next: OrderFormState) => void;
    items: ItemChoice[];
    departments: string[];
    /** Open approved request lines, only where a person may pick from them (a new order). */
    requestLines: RequestLineChoice[];
    currency: string;
    fieldError: (name: string) => string | undefined;
    badPrices: string[];
    /** Fewest lines the form may hold: an order needs one, a revision may add none. */
    minLines?: number;
    heading?: string;
    hint?: string;
};

/** The lines of a new or draft order: each from an approved request line, or typed by hand. */
export function OrderLineFields({ badPrices, currency, departments, fieldError, form, heading, hint, items, minLines = 1, onChange, requestLines }: LinesProps) {
    const { t, locale } = useTranslation();

    function setLine(key: string, patch: Partial<OrderLineForm>) {
        onChange({ ...form, lines: form.lines.map((l) => (l.key === key ? { ...l, ...patch } : l)) });
    }

    const label = (r: RequestLineChoice) => `${r.request_number} · ${r.item_code} ${r.item_name} · ${formatMilli(r.qty_milli, locale)} ${r.unit}`;

    return (
        <div className="flex flex-col gap-3">
            <h3 className="text-sm font-semibold">{heading ?? t('inv.po.linesHeading')}</h3>
            <p className="text-sm text-muted-foreground">{hint ?? t('inv.po.linesHint')}</p>
            {fieldError('lines') ? <p className="text-sm text-danger">{fieldError('lines')}</p> : null}
            {form.lines.map((l) => {
                const taken = new Set(form.lines.filter((x) => x.key !== l.key).map((x) => x.request_line_id));
                const item = items.find((x) => x.id === l.item_id);
                const price = (
                    <FormField error={badPrices.includes(l.key) ? t('fo.folio.invalidAmount') : undefined} field="lines.*.unit_price_minor" label={t('inv.po.unitPrice', { currency })}>
                        <MoneyInput onChange={(e) => setLine(l.key, { price: e.target.value })} placeholder={t('inv.po.priceFromList')} value={l.price} />
                    </FormField>
                );
                const remove = form.lines.length > minLines ? <Button onClick={() => onChange({ ...form, lines: form.lines.filter((x) => x.key !== l.key) })} size="sm" type="button" variant="outline">{t('inv.trf.removeLine')}</Button> : null;

                return (
                    <div className="grid gap-3 border-t border-border pt-3 sm:grid-cols-[9rem_minmax(0,1fr)]" data-testid="order-line" key={l.key}>
                        {l.locked || requestLines.length === 0 ? (
                            <p className="self-end pb-2 text-sm font-medium">{t(l.source === 'request' ? 'inv.po.source.request' : 'inv.po.source.manual')}</p>
                        ) : (
                            <FormField label={t('inv.po.source')}>
                                <Select onChange={(e) => setLine(l.key, { source: e.target.value === 'request' ? 'request' : 'manual' })} searchable={false} value={l.source}>
                                    <option value="request">{t('inv.po.source.request')}</option>
                                    <option value="manual">{t('inv.po.source.manual')}</option>
                                </Select>
                            </FormField>
                        )}
                        {l.source === 'request' ? (
                            <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_auto]">
                                {l.locked ? (
                                    <p className="self-end pb-2 text-sm" data-testid="order-line-summary">{l.summary}</p>
                                ) : (
                                    <FormField field="lines.*.request_line_id" label={t('inv.po.requestLine')} required>
                                        <Select onChange={(e) => setLine(l.key, { request_line_id: e.target.value })} value={l.request_line_id}>
                                            <option value="">{t('inv.po.chooseRequestLine')}</option>
                                            {requestLines.filter((r) => !taken.has(r.id)).map((r) => <option key={r.id} value={r.id}>{label(r)}</option>)}
                                        </Select>
                                    </FormField>
                                )}
                                {price}
                                <div className="flex items-end">{remove}</div>
                            </div>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-[minmax(0,2fr)_6rem_7rem_9rem_minmax(0,1.3fr)_auto]">
                                <FormField field="lines.*.item_id" label={t('inv.col.lineItem')} required>
                                    <Select onChange={(e) => setLine(l.key, { item_id: e.target.value, unit: items.find((x) => x.id === e.target.value)?.base_unit ?? '' })} value={l.item_id}>
                                        <option value="">{t('inv.req.chooseItem')}</option>
                                        {items.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                                    </Select>
                                </FormField>
                                <FormField field="lines.*.unit" label={t('inv.col.unit')} required>
                                    <Select onChange={(e) => setLine(l.key, { unit: e.target.value })} searchable={false} value={l.unit}>
                                        {item === undefined ? null : item.units.map((u) => <option key={u} value={u}>{u}</option>)}
                                    </Select>
                                </FormField>
                                <FormField field="lines.*.quantity" label={t('inv.opening.quantity')} required>
                                    <Input inputMode="decimal" onChange={(e) => setLine(l.key, { quantity: e.target.value })} value={l.quantity} />
                                </FormField>
                                {price}
                                <FormField field="lines.*.department" label={t('inv.col.department')}>
                                    <Select onChange={(e) => setLine(l.key, { department: e.target.value })} value={l.department}>
                                        <option value="">{t('inv.po.noDepartment')}</option>
                                        {departments.map((d) => <option key={d} value={d}>{t(`inv.dept.${d}` as MessageKey)}</option>)}
                                    </Select>
                                </FormField>
                                <div className="flex items-end">{remove}</div>
                            </div>
                        )}
                    </div>
                );
            })}
            <div className="flex flex-wrap gap-2">
                <Button onClick={() => onChange({ ...form, lines: [...form.lines, blankOrderLine(requestLines.length > 0 ? 'request' : 'manual')] })} size="sm" type="button" variant="outline">{t('inv.trf.addLine')}</Button>
            </div>
        </div>
    );
}
