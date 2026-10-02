import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { ConfirmDialog, Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Plan = { id: string; code: string; name: string; kind: string; inclusions: string | null; prices_include_charges: boolean; is_active: boolean; lock_version: number };
type RoomType = { id: string; code: string; name: string };
type Period = { id: string; room_type_id: string; from: string; to: string; weekday_mask: number; nightly_minor: number; currency: string };
type Restriction = { id: string; room_type_id: string | null; from: string; to: string; min_stay: number | null; max_stay: number | null; closed_to_arrival: boolean; closed_to_departure: boolean; stop_sell: boolean };
type Selected = { plan: Plan; periods: Period[]; restrictions: Restriction[] } | null;
type Quote = {
    currency: string;
    bookable: boolean;
    nights: { date: string; base_minor: number; service_charge_minor: number; tax_minor: number; total_minor: number }[];
    total_minor: number;
    violations: { code: string; date: string | null; value: number | null }[];
};

const KINDS = ['public', 'corporate', 'package', 'ota', 'promotion'] as const;
const DAYS = [1, 2, 3, 4, 5, 6, 7] as const;
const ALL_DAYS = 127;

type PlanForm = { id: string | null; code: string; name: string; kind: string; inclusions: string; nett: boolean; lockVersion: number; reason: string };
type PriceForm = { periodId: string | null; roomTypeId: string; from: string; to: string; mask: number; amount: string; reason: string };
type RestrictionForm = { roomTypeId: string; from: string; to: string; minStay: string; maxStay: string; cta: boolean; ctd: boolean; stop: boolean; reason: string };
type Removal = { kind: 'price' | 'restriction'; id: string };

export default function RatePlansPage({ plans, selected, types }: { plans: Plan[]; selected: Selected; types: RoomType[] }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [planForm, setPlanForm] = useState<PlanForm | null>(null);
    const [priceForm, setPriceForm] = useState<PriceForm | null>(null);
    const [restrictionForm, setRestrictionForm] = useState<RestrictionForm | null>(null);
    const [removal, setRemoval] = useState<Removal | null>(null);
    const [removalReason, setRemovalReason] = useState('');
    const [amountError, setAmountError] = useState(false);
    const [quoteForm, setQuoteForm] = useState({ roomTypeId: '', arrival: '', departure: '' });
    const [quote, setQuote] = useState<Quote | null>(null);
    const typeName = (id: string | null) => (id === null ? t('rates.restrictions.allTypes') : types.find((x) => x.id === id)?.name ?? '');
    const currency = selected?.periods[0]?.currency ?? 'IDR';
    const reload = ['plans', 'selected'];

    function closeAll() {
        action.clear();
        setPlanForm(null);
        setPriceForm(null);
        setRestrictionForm(null);
        setRemoval(null);
        setRemovalReason('');
        setAmountError(false);
    }

    const open = (fn: () => void) => () => {
        action.clear();
        fn();
    };

    async function savePlan() {
        if (planForm === null) return;
        const body = { code: planForm.code, name: planForm.name, kind: planForm.kind, inclusions: planForm.inclusions || null, prices_include_charges: planForm.nett, lock_version: planForm.lockVersion, reason: planForm.reason };
        const done = await action.run<{ plan: Plan }>(planForm.id === null ? '/property/rate-plans' : `/property/rate-plans/${planForm.id}`, { method: planForm.id === null ? 'POST' : 'PUT', body, reload });
        if (done !== null) {
            closeAll();
            if (planForm.id === null) router.get('/property/rates', { plan: done.plan.id });
        }
    }

    async function togglePlan(plan: Plan) {
        await action.run(`/property/rate-plans/${plan.id}/active`, { body: { active: !plan.is_active, lock_version: plan.lock_version, reason: plan.is_active ? 'Deactivated from the rate plan screen' : 'Activated from the rate plan screen' }, reload });
    }

    async function savePrice() {
        if (priceForm === null || selected === null) return;
        const minor = parseMajorToMinor(priceForm.amount, currency);
        setAmountError(minor === null);
        if (minor === null) return;
        const done = priceForm.periodId === null
            ? await action.run(`/property/rate-plans/${selected.plan.id}/prices`, { body: { room_type_id: priceForm.roomTypeId, from: priceForm.from, to: priceForm.to, weekday_mask: priceForm.mask, nightly_minor: minor, reason: priceForm.reason }, reload })
            : await action.run(`/property/rate-prices/${priceForm.periodId}/reprice`, { body: { nightly_minor: minor, reason: priceForm.reason }, reload });
        if (done !== null) closeAll();
    }

    async function saveRestriction() {
        if (restrictionForm === null || selected === null) return;
        const body = {
            room_type_id: restrictionForm.roomTypeId || null, from: restrictionForm.from, to: restrictionForm.to,
            min_stay: restrictionForm.minStay ? Number(restrictionForm.minStay) : null, max_stay: restrictionForm.maxStay ? Number(restrictionForm.maxStay) : null,
            closed_to_arrival: restrictionForm.cta, closed_to_departure: restrictionForm.ctd, stop_sell: restrictionForm.stop, reason: restrictionForm.reason,
        };
        const done = await action.run(`/property/rate-plans/${selected.plan.id}/restrictions`, { body, reload });
        if (done !== null) closeAll();
    }

    async function remove() {
        if (removal === null) return;
        const path = removal.kind === 'price' ? `/property/rate-prices/${removal.id}/remove` : `/property/rate-restrictions/${removal.id}/remove`;
        const done = await action.run(path, { body: { reason: removalReason }, reload });
        if (done !== null) closeAll();
    }

    async function check() {
        if (selected === null) return;
        const done = await action.run<{ quote: Quote }>(`/property/rate-plans/${selected.plan.id}/quote`, { body: { room_type_id: quoteForm.roomTypeId, arrival: quoteForm.arrival, departure: quoteForm.departure } });
        setQuote(done?.quote ?? null);
    }

    const days = (mask: number) => (mask === ALL_DAYS ? t('rates.day.all') : DAYS.filter((d) => (mask & (1 << (d - 1))) !== 0).map((d) => t(`rates.day.${d}`)).join(', '));
    const restrictionSummary = (r: Restriction) => [
        r.stop_sell ? t('rates.restrictions.stopSell') : null,
        r.closed_to_arrival ? t('rates.restrictions.cta') : null,
        r.closed_to_departure ? t('rates.restrictions.ctd') : null,
        r.min_stay !== null ? t('rates.restrictions.summary.min', { n: r.min_stay }) : null,
        r.max_stay !== null ? t('rates.restrictions.summary.max', { n: r.max_stay }) : null,
    ].filter(Boolean).join(' · ');
    const violation = (v: Quote['violations'][number]) => t(`rates.violation.${v.code}` as 'rates.violation.no_price', { date: v.date ? format.date(v.date) : '', value: v.value ?? '' });
    const error = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const footer = (onSave: () => void) => (<>
        <Button disabled={action.busy} onClick={closeAll} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
        <Button loading={action.busy} onClick={onSave} type="button">{t('property.action.save')}</Button>
    </>);
    const reasonField = (value: string, set: (v: string) => void) => (
        <FormField error={action.fieldError('reason')} hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
            <Input maxLength={500} onChange={(e) => set(e.target.value)} value={value} />
        </FormField>
    );

    return (
        <PropertyShell
            description={t('rates.description')}
            title={t('rates.title')}
        >
            {planForm === null && priceForm === null && restrictionForm === null && removal === null ? error : null}

            <section aria-labelledby="plans-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="plans-h">{t('rates.plans.heading')}</h2>
                    <Button onClick={open(() => setPlanForm({ id: null, code: '', name: '', kind: 'public', inclusions: '', nett: false, lockVersion: 0, reason: '' }))} size="sm" type="button">{t('rates.plans.add')}</Button>
                </div>
                {plans.length === 0 ? <EmptyState title={t('rates.plans.empty')} /> : (
                    <ul className="divide-y divide-border border-y border-border">
                        {plans.map((plan) => (
                            <li className="flex flex-wrap items-center justify-between gap-3 py-3" key={plan.id}>
                                <div>
                                    <Link className="text-sm font-medium underline-offset-2 hover:underline" href={`/property/rates?plan=${plan.id}`}>{plan.code} · {plan.name}</Link>
                                    <p className="text-xs text-muted-foreground">{t(`rates.kind.${plan.kind}` as 'rates.kind.public')} · {plan.prices_include_charges ? t('rates.plans.nett') : t('rates.plans.plusPlus')}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <StatusBadge label={plan.is_active ? t('property.status.active') : t('property.status.inactive')} tone={plan.is_active ? 'success' : 'neutral'} />
                                    <Button onClick={open(() => setPlanForm({ id: plan.id, code: plan.code, name: plan.name, kind: plan.kind, inclusions: plan.inclusions ?? '', nett: plan.prices_include_charges, lockVersion: plan.lock_version, reason: '' }))} size="sm" type="button" variant="outline">{t('property.action.edit')}</Button>
                                    <Button disabled={action.busy} onClick={() => void togglePlan(plan)} size="sm" type="button" variant="outline">{plan.is_active ? t('property.action.deactivate') : t('property.action.activate')}</Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {selected === null ? (plans.length > 0 ? <p className="text-sm text-muted-foreground">{t('rates.plans.chooseOne')}</p> : null) : (
                <>
                    <section aria-labelledby="prices-h" className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-lg font-semibold" id="prices-h">{selected.plan.code} · {t('rates.prices.heading')}</h2>
                            <Button disabled={types.length === 0} onClick={open(() => setPriceForm({ periodId: null, roomTypeId: '', from: '', to: '', mask: ALL_DAYS, amount: '', reason: '' }))} size="sm" type="button">{t('rates.prices.add')}</Button>
                        </div>
                        {selected.periods.length === 0 ? <EmptyState title={t('rates.prices.empty')} /> : (
                            <ul className="divide-y divide-border border-y border-border">
                                {selected.periods.map((p) => (
                                    <li className="flex flex-wrap items-center justify-between gap-3 py-3" key={p.id}>
                                        <div>
                                            <p className="text-sm font-medium">{typeName(p.room_type_id)} · {format.money(p.nightly_minor, p.currency)}</p>
                                            <p className="text-xs text-muted-foreground">{format.date(p.from)} – {format.date(p.to)} · {days(p.weekday_mask)}</p>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button onClick={open(() => setPriceForm({ periodId: p.id, roomTypeId: p.room_type_id, from: p.from, to: p.to, mask: p.weekday_mask, amount: String(p.nightly_minor / 100), reason: '' }))} size="sm" type="button" variant="outline">{t('rates.prices.reprice')}</Button>
                                            <Button onClick={open(() => setRemoval({ kind: 'price', id: p.id }))} size="sm" type="button" variant="outline">{t('rates.prices.remove')}</Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-labelledby="res-h" className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-lg font-semibold" id="res-h">{t('rates.restrictions.heading')}</h2>
                            <Button onClick={open(() => setRestrictionForm({ roomTypeId: '', from: '', to: '', minStay: '', maxStay: '', cta: false, ctd: false, stop: false, reason: '' }))} size="sm" type="button">{t('rates.restrictions.add')}</Button>
                        </div>
                        {selected.restrictions.length === 0 ? <EmptyState title={t('rates.restrictions.empty')} /> : (
                            <ul className="divide-y divide-border border-y border-border">
                                {selected.restrictions.map((r) => (
                                    <li className="flex flex-wrap items-center justify-between gap-3 py-3" key={r.id}>
                                        <div>
                                            <p className="text-sm font-medium">{typeName(r.room_type_id)} · {restrictionSummary(r)}</p>
                                            <p className="text-xs text-muted-foreground">{format.date(r.from)} – {format.date(r.to)}</p>
                                        </div>
                                        <Button onClick={open(() => setRemoval({ kind: 'restriction', id: r.id }))} size="sm" type="button" variant="outline">{t('rates.prices.remove')}</Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-labelledby="quote-h" className="flex flex-col gap-3">
                        <h2 className="text-lg font-semibold" id="quote-h">{t('rates.quote.heading')}</h2>
                        <form className="grid gap-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); void check(); }}>
                            <FormField error={action.fieldError('room_type_id')} label={t('rates.prices.roomType')}>
                                <Select onChange={(e) => setQuoteForm({ ...quoteForm, roomTypeId: e.target.value })} value={quoteForm.roomTypeId}>
                                    <option value="">{t('property.rooms.chooseType')}</option>
                                    {types.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                                </Select>
                            </FormField>
                            <FormField error={action.fieldError('arrival')} label={t('rates.quote.arrival')}><DatePicker onChange={(e) => setQuoteForm({ ...quoteForm, arrival: e.target.value })} value={quoteForm.arrival} /></FormField>
                            <FormField error={action.fieldError('departure')} label={t('rates.quote.departure')}><DatePicker onChange={(e) => setQuoteForm({ ...quoteForm, departure: e.target.value })} value={quoteForm.departure} /></FormField>
                            <div className="flex items-end"><Button loading={action.busy} type="submit">{t('rates.quote.check')}</Button></div>
                        </form>
                        {quote !== null && (
                            <div className="flex flex-col gap-2 text-sm">
                                {quote.violations.length === 0 ? <StatusBadge label={t('rates.quote.bookable')} tone="success" /> : (
                                    <div><StatusBadge label={t('rates.quote.notBookable')} tone="warning" /><ul className="mt-2 list-disc pl-5">{quote.violations.map((v, i) => <li key={i}>{violation(v)}</li>)}</ul></div>
                                )}
                                {quote.nights.length > 0 && (
                                    <div className="border border-border bg-surface">
                                        <Table>
                                            <TableHeader className="bg-surface-muted">
                                                <TableRow className="hover:bg-transparent">
                                                    <TableHead scope="col">{t('rates.quote.arrival')}</TableHead>
                                                    <TableHead className="text-right" scope="col">{t('rates.quote.base')}</TableHead>
                                                    <TableHead className="text-right" scope="col">{t('rates.quote.service')}</TableHead>
                                                    <TableHead className="text-right" scope="col">{t('rates.quote.tax')}</TableHead>
                                                    <TableHead className="text-right" scope="col">{t('rates.quote.total')}</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {quote.nights.map((n) => (
                                                    <TableRow key={n.date}>
                                                        <TableHead className="font-normal text-foreground" scope="row">{format.date(n.date)}</TableHead>
                                                        <TableCell className="text-right tabular-nums">{format.money(n.base_minor, quote.currency)}</TableCell>
                                                        <TableCell className="text-right tabular-nums">{format.money(n.service_charge_minor, quote.currency)}</TableCell>
                                                        <TableCell className="text-right tabular-nums">{format.money(n.tax_minor, quote.currency)}</TableCell>
                                                        <TableCell className="text-right tabular-nums">{format.money(n.total_minor, quote.currency)}</TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                            <TableFooter>
                                                <TableRow className="hover:bg-transparent">
                                                    <TableCell colSpan={4}>{t('rates.quote.total')}</TableCell>
                                                    <TableCell className="text-right tabular-nums">{format.money(quote.total_minor, quote.currency)}</TableCell>
                                                </TableRow>
                                            </TableFooter>
                                        </Table>
                                    </div>
                                )}
                            </div>
                        )}
                    </section>
                </>
            )}

            <Dialog footer={footer(() => void savePlan())} onClose={closeAll} open={planForm !== null} title={planForm?.id === null ? t('rates.plans.add') : t('rates.plans.edit')}>
                {planForm !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        {planForm.id === null && <FormField error={action.fieldError('code')} label={t('rates.plans.code')}><Input maxLength={20} onChange={(e) => setPlanForm({ ...planForm, code: e.target.value })} value={planForm.code} /></FormField>}
                        <FormField error={action.fieldError('name')} label={t('rates.plans.name')}><Input maxLength={100} onChange={(e) => setPlanForm({ ...planForm, name: e.target.value })} value={planForm.name} /></FormField>
                        <FormField error={action.fieldError('kind')} label={t('rates.plans.kind')}>
                            <Select onChange={(e) => setPlanForm({ ...planForm, kind: e.target.value })} value={planForm.kind}>{KINDS.map((k) => <option key={k} value={k}>{t(`rates.kind.${k}`)}</option>)}</Select>
                        </FormField>
                        <FormField error={action.fieldError('inclusions')} label={t('rates.plans.inclusions')}><Textarea maxLength={500} onChange={(e) => setPlanForm({ ...planForm, inclusions: e.target.value })} value={planForm.inclusions} /></FormField>
                        <label className="flex items-center gap-2 text-sm"><input checked={planForm.nett} onChange={(e) => setPlanForm({ ...planForm, nett: e.target.checked })} type="checkbox" />{t('rates.plans.nett')}</label>
                        {reasonField(planForm.reason, (v) => setPlanForm({ ...planForm, reason: v }))}
                    </div>
                )}
            </Dialog>

            <Dialog footer={footer(() => void savePrice())} onClose={closeAll} open={priceForm !== null} title={priceForm?.periodId === null ? t('rates.prices.add') : t('rates.prices.reprice')}>
                {priceForm !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        {priceForm.periodId === null && (<>
                            <FormField error={action.fieldError('room_type_id')} label={t('rates.prices.roomType')}>
                                <Select onChange={(e) => setPriceForm({ ...priceForm, roomTypeId: e.target.value })} value={priceForm.roomTypeId}>
                                    <option value="">{t('property.rooms.chooseType')}</option>
                                    {types.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                                </Select>
                            </FormField>
                            <div className="grid grid-cols-2 gap-3">
                                <FormField error={action.fieldError('from')} label={t('rates.prices.from')}><DatePicker onChange={(e) => setPriceForm({ ...priceForm, from: e.target.value })} value={priceForm.from} /></FormField>
                                <FormField error={action.fieldError('to')} label={t('rates.prices.to')}><DatePicker onChange={(e) => setPriceForm({ ...priceForm, to: e.target.value })} value={priceForm.to} /></FormField>
                            </div>
                            <fieldset className="flex flex-wrap gap-3 text-sm"><legend className="mb-1 text-sm font-medium">{t('rates.prices.days')}</legend>
                                {DAYS.map((d) => (
                                    <label className="flex items-center gap-1" key={d}>
                                        <input checked={(priceForm.mask & (1 << (d - 1))) !== 0} onChange={(e) => setPriceForm({ ...priceForm, mask: e.target.checked ? priceForm.mask | (1 << (d - 1)) : priceForm.mask & ~(1 << (d - 1)) })} type="checkbox" />{t(`rates.day.${d}`)}
                                    </label>
                                ))}
                            </fieldset>
                        </>)}
                        <FormField error={amountError ? t('rates.prices.invalidAmount') : action.fieldError('nightly_minor')} hint={t('rates.prices.nightlyHint')} label={t('rates.prices.nightly')}>
                            <Input inputMode="decimal" onChange={(e) => setPriceForm({ ...priceForm, amount: e.target.value })} value={priceForm.amount} />
                        </FormField>
                        {reasonField(priceForm.reason, (v) => setPriceForm({ ...priceForm, reason: v }))}
                    </div>
                )}
            </Dialog>

            <Dialog footer={footer(() => void saveRestriction())} onClose={closeAll} open={restrictionForm !== null} title={t('rates.restrictions.add')}>
                {restrictionForm !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        <FormField error={action.fieldError('room_type_id')} label={t('rates.prices.roomType')}>
                            <Select onChange={(e) => setRestrictionForm({ ...restrictionForm, roomTypeId: e.target.value })} value={restrictionForm.roomTypeId}>
                                <option value="">{t('rates.restrictions.allTypes')}</option>
                                {types.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                            </Select>
                        </FormField>
                        <div className="grid grid-cols-2 gap-3">
                            <FormField error={action.fieldError('from')} label={t('rates.prices.from')}><DatePicker onChange={(e) => setRestrictionForm({ ...restrictionForm, from: e.target.value })} value={restrictionForm.from} /></FormField>
                            <FormField error={action.fieldError('to')} label={t('rates.prices.to')}><DatePicker onChange={(e) => setRestrictionForm({ ...restrictionForm, to: e.target.value })} value={restrictionForm.to} /></FormField>
                            <FormField error={action.fieldError('min_stay')} label={t('rates.restrictions.minStay')}><Input inputMode="numeric" onChange={(e) => setRestrictionForm({ ...restrictionForm, minStay: e.target.value })} value={restrictionForm.minStay} /></FormField>
                            <FormField error={action.fieldError('max_stay')} label={t('rates.restrictions.maxStay')}><Input inputMode="numeric" onChange={(e) => setRestrictionForm({ ...restrictionForm, maxStay: e.target.value })} value={restrictionForm.maxStay} /></FormField>
                        </div>
                        <div className="flex flex-col gap-1 text-sm">
                            <label className="flex items-center gap-2"><input checked={restrictionForm.cta} onChange={(e) => setRestrictionForm({ ...restrictionForm, cta: e.target.checked })} type="checkbox" />{t('rates.restrictions.cta')}</label>
                            <label className="flex items-center gap-2"><input checked={restrictionForm.ctd} onChange={(e) => setRestrictionForm({ ...restrictionForm, ctd: e.target.checked })} type="checkbox" />{t('rates.restrictions.ctd')}</label>
                            <label className="flex items-center gap-2"><input checked={restrictionForm.stop} onChange={(e) => setRestrictionForm({ ...restrictionForm, stop: e.target.checked })} type="checkbox" />{t('rates.restrictions.stopSell')}</label>
                        </div>
                        {reasonField(restrictionForm.reason, (v) => setRestrictionForm({ ...restrictionForm, reason: v }))}
                    </div>
                )}
            </Dialog>

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={t('rates.prices.remove')}
                consequence={removal?.kind === 'price' ? t('rates.prices.removeConsequence') : t('rates.restrictions.removeConsequence')}
                destructive
                onCancel={closeAll}
                onConfirm={() => void remove()}
                open={removal !== null}
                pending={action.busy}
                title={removal?.kind === 'price' ? t('rates.prices.removeTitle') : t('rates.restrictions.removeTitle')}
            >
                <div className="flex flex-col gap-3">{error}{reasonField(removalReason, setRemovalReason)}</div>
            </ConfirmDialog>
        </PropertyShell>
    );
}
