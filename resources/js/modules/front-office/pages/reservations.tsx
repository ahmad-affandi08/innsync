import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { StayBlocks } from '@/modules/front-office/components/stay-blocks';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { ApiError } from '@/shared/lib/api-error';
import { isIsoDate, nightsBetween } from '@/shared/time/time';

export type ReservationRow = {
    id: string; number: string; status: string; source: string; guest_name: string; arrival: string; departure: string; nights: number;
    adults: number; children: number; room_type_id: string; rate_plan_id: string; currency: string; total_minor: number; oversold: boolean; lock_version: number;
};
type Lookups = {
    types: { id: string; code: string; name: string; max_adults: number; max_children: number }[];
    plans: { id: string; code: string; name: string; kind: string; inclusions: string | null; prices_include_charges: boolean }[];
    business_date: string;
};
type Quote = {
    currency: string; bookable: boolean; total_minor: number;
    nights: { date: string; base_minor: number; service_charge_minor: number; tax_minor: number; total_minor: number }[];
    violations: { code: string; date: string | null; value: number | null }[];
    availability: { sold_out_nights: string[]; overbooking_nights: string[] };
};

export const statusTone: Record<string, StatusTone> = { tentative: 'pending', confirmed: 'info', guaranteed: 'success', checked_in: 'success', completed: 'neutral', cancelled: 'danger', no_show: 'warning' };

const SOURCES = ['direct', 'phone', 'ota', 'corporate', 'walk_in'] as const;
const STATUSES = ['tentative', 'confirmed', 'guaranteed', 'checked_in', 'completed', 'cancelled', 'no_show'] as const;

const emptyForm = (date: string) => ({
    source: 'direct', guest: '', phone: '', email: '', arrival: date, departure: '', adults: '2', children: '0', roomTypeId: '', ratePlanId: '', notes: '', status: 'tentative', oversellReason: '',
});

export default function ReservationsPage({ filters, lookups, reservations }: { filters: Record<string, string>; lookups: Lookups; reservations: ReservationRow[] }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [filter, setFilter] = useState({ query: filters.query ?? '', status: filters.status ?? '', arrival_from: filters.arrival_from ?? '', arrival_to: filters.arrival_to ?? '' });
    const [form, setForm] = useState<ReturnType<typeof emptyForm> | null>(null);
    const [quote, setQuote] = useState<Quote | null>(null);
    const [oversellOpen, setOversellOpen] = useState(false);
    const typeName = (id: string) => lookups.types.find((x) => x.id === id)?.code ?? '';
    // One key per intent: any change to what is being booked is a new intent and gets a new key (NFR-18).
    const intentKey = useMemo(() => newIdempotencyKey(), [JSON.stringify(form)]);
    const conflictReason = action.error instanceof ApiError ? action.error.failure.conflict?.reason ?? null : null;
    const violation = (v: Quote['violations'][number]) => t(`rates.violation.${v.code}` as 'rates.violation.no_price', { date: v.date ? format.date(v.date) : '', value: v.value ?? '' });

    function search(next = filter) {
        const params = Object.fromEntries(Object.entries(next).filter(([, v]) => v !== ''));
        router.get('/front-office/reservations', params, { preserveState: true });
    }

    function open() {
        action.clear();
        setQuote(null);
        setForm(emptyForm(lookups.business_date));
    }

    function close() {
        action.clear();
        setForm(null);
        setQuote(null);
        setOversellOpen(false);
    }

    async function check() {
        if (form === null) return;
        const done = await action.run<{ quote: Quote }>('/front-office/reservations/quote', { body: { rate_plan_id: form.ratePlanId, room_type_id: form.roomTypeId, arrival: form.arrival, departure: form.departure } });
        setQuote(done?.quote ?? null);
    }

    async function create(acknowledge: boolean) {
        if (form === null) return;
        const done = await action.run<{ reservation: ReservationRow }>('/front-office/reservations', {
            idempotencyKey: intentKey,
            onFailure: (failure) => {
                if (failure.conflict?.reason === 'oversell_warning') setOversellOpen(true);
            },
            body: {
                source: form.source, guest_name: form.guest, guest_phone: form.phone || null, guest_email: form.email || null, arrival: form.arrival, departure: form.departure,
                adults: Number(form.adults), children: Number(form.children), room_type_id: form.roomTypeId, rate_plan_id: form.ratePlanId, notes: form.notes || null, status: form.status,
                acknowledge_oversell: acknowledge, oversell_reason: acknowledge ? form.oversellReason : null,
            },
        });

        if (done !== null) {
            close();
            router.visit(`/front-office/reservations/${done.reservation.id}`);
        }
    }

    // The price of every night is worked out as soon as the dates, room type and rate plan are known (FR-FO-012).
    const quoteKey = form === null ? '' : [form.arrival, form.departure, form.roomTypeId, form.ratePlanId].join('|');
    useEffect(() => {
        if (form === null || form.roomTypeId === '' || form.ratePlanId === '' || !isIsoDate(form.arrival) || !isIsoDate(form.departure) || nightsBetween(form.arrival, form.departure) < 1) return;
        const timer = window.setTimeout(() => { void check(); }, 400);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [quoteKey]);

    const columns: DataGridColumn<ReservationRow>[] = [
        { id: 'number', label: t('fo.res.number'), value: (r) => r.number, rowHeader: true, cell: (r) => <Link className="font-medium underline-offset-2 hover:underline" href={`/front-office/reservations/${r.id}`}>{r.number}</Link> },
        { id: 'guest', label: t('fo.res.guest'), value: (r) => r.guest_name },
        { id: 'stay', label: t('fo.res.stay'), value: (r) => r.arrival, searchText: (r) => `${r.arrival} ${r.departure}`, cell: (r) => <>{format.date(r.arrival)} – {format.date(r.departure)} <span className="text-xs text-muted-foreground">({t('fo.res.nights', { n: r.nights })})</span></> },
        { id: 'roomType', label: t('fo.res.roomType'), value: (r) => typeName(r.room_type_id), filter: 'select' },
        { id: 'source', label: t('fo.res.source'), value: (r) => r.source, filter: 'select', filterLabel: (v) => t(`fo.source.${v}` as 'fo.source.direct'), cell: (r) => t(`fo.source.${r.source}` as 'fo.source.direct'), hidden: true },
        { id: 'status', label: t('fo.res.status'), value: (r) => r.status, filter: 'select', filterLabel: (v) => t(`fo.status.${v}` as 'fo.status.tentative'), cell: (r) => <StatusBadge label={t(`fo.status.${r.status}` as 'fo.status.tentative')} tone={statusTone[r.status] ?? 'neutral'} /> },
        { id: 'total', label: t('fo.res.total'), align: 'right', value: (r) => r.total_minor, cell: (r) => format.money(r.total_minor, r.currency) },
    ];

    const field = (name: string) => action.fieldError(name);
    const set = (patch: Partial<NonNullable<typeof form>>) => form !== null && setForm({ ...form, ...patch });

    return (
        <FrontOfficeShell description={t('fo.res.description')} title={t('fo.res.title')} wide>
            <form className="grid gap-3 sm:grid-cols-5" onSubmit={(e) => { e.preventDefault(); search(); }}>
                <FormField label={t('fo.res.search')}><Input onChange={(e) => setFilter({ ...filter, query: e.target.value })} value={filter.query} /></FormField>
                <FormField label={t('fo.res.status')}>
                    <Select onChange={(e) => setFilter({ ...filter, status: e.target.value })} value={filter.status}>
                        <option value="">{t('fo.res.allStatuses')}</option>
                        {STATUSES.map((s) => <option key={s} value={s}>{t(`fo.status.${s}`)}</option>)}
                    </Select>
                </FormField>
                <FormField label={t('fo.res.arrivalFrom')}><Input onChange={(e) => setFilter({ ...filter, arrival_from: e.target.value })} type="date" value={filter.arrival_from} /></FormField>
                <FormField label={t('fo.res.arrivalTo')}><Input onChange={(e) => setFilter({ ...filter, arrival_to: e.target.value })} type="date" value={filter.arrival_to} /></FormField>
                <div className="flex items-end gap-2">
                    <Button type="submit">{t('fo.res.apply')}</Button>
                    <Button onClick={() => { const cleared = { query: '', status: '', arrival_from: '', arrival_to: '' }; setFilter(cleared); search(cleared); }} type="button" variant="outline">{t('fo.res.clear')}</Button>
                </div>
            </form>

            <div className="flex justify-end"><Button onClick={open} type="button">{t('fo.res.add')}</Button></div>

            <DataGrid
                caption={t('fo.res.title')}
                columns={columns}
                empty={<EmptyState title={t('fo.res.empty')} />}
                getRowId={(r) => r.id}
                id="fo.reservations"
                rows={reservations}
            />

            <Dialog
                className="w-[min(44rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={close} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button disabled={action.busy} onClick={() => void check()} type="button" variant="outline">{t('fo.res.checkPrice')}</Button>
                    <Button loading={action.busy} onClick={() => void create(false)} type="button">{t('fo.res.create')}</Button>
                </>}
                onClose={close}
                open={form !== null && !oversellOpen}
                title={t('fo.res.add')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null && conflictReason !== 'oversell_warning' ? (
                            <ErrorState {...errorCopy} error={action.error} onRefresh={() => void check()}>
                                {conflictReason === 'no_availability' && <p>{t('fo.error.noAvailability')}</p>}
                                {action.failure?.kind === 'validation' && action.failure.fields !== undefined && Object.keys(action.failure.fields).length === 0 && <p>{t('fo.error.notBookable')}</p>}
                            </ErrorState>
                        ) : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={field('guest_name')} label={t('fo.res.guest')}><Input maxLength={150} onChange={(e) => set({ guest: e.target.value })} value={form.guest} /></FormField>
                            <FormField error={field('source')} label={t('fo.res.source')}>
                                <Select onChange={(e) => set({ source: e.target.value })} value={form.source}>{SOURCES.map((s) => <option key={s} value={s}>{t(`fo.source.${s}`)}</option>)}</Select>
                            </FormField>
                            <FormField error={field('guest_phone')} label={t('fo.res.phone')}><Input inputMode="tel" maxLength={30} onChange={(e) => set({ phone: e.target.value })} value={form.phone} /></FormField>
                            <FormField error={field('guest_email')} label={t('fo.res.email')}><Input inputMode="email" maxLength={190} onChange={(e) => set({ email: e.target.value })} value={form.email} /></FormField>
                            <FormField error={field('arrival')} label={t('fo.res.arrival')}><Input min={lookups.business_date} onChange={(e) => set({ arrival: e.target.value })} type="date" value={form.arrival} /></FormField>
                            <FormField error={field('departure')} label={t('fo.res.departure')}><Input onChange={(e) => set({ departure: e.target.value })} type="date" value={form.departure} /></FormField>
                            <StayBlocks arrival={form.arrival} currency={quote?.currency ?? 'IDR'} departure={form.departure} nights={quote?.nights ?? []} onDeparture={(departure) => set({ departure })} />
                            <FormField error={field('room_type_id')} label={t('fo.res.roomType')}>
                                <Select onChange={(e) => set({ roomTypeId: e.target.value })} value={form.roomTypeId}>
                                    <option value="">{t('property.rooms.chooseType')}</option>
                                    {lookups.types.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                                </Select>
                            </FormField>
                            <FormField error={field('rate_plan_id')} label={t('fo.res.ratePlan')}>
                                <Select onChange={(e) => set({ ratePlanId: e.target.value })} value={form.ratePlanId}>
                                    <option value="">—</option>
                                    {lookups.plans.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                                </Select>
                            </FormField>
                            <FormField error={field('adults')} label={t('fo.res.adults')}><Input inputMode="numeric" onChange={(e) => set({ adults: e.target.value })} value={form.adults} /></FormField>
                            <FormField error={field('children')} label={t('fo.res.children')}><Input inputMode="numeric" onChange={(e) => set({ children: e.target.value })} value={form.children} /></FormField>
                            <FormField label={t('fo.res.initialStatus')}>
                                <Select onChange={(e) => set({ status: e.target.value })} value={form.status}>{(['tentative', 'confirmed'] as const).map((s) => <option key={s} value={s}>{t(`fo.status.${s}`)}</option>)}</Select>
                            </FormField>
                        </div>
                        <FormField error={field('notes')} label={t('fo.res.notes')}><Textarea maxLength={1000} onChange={(e) => set({ notes: e.target.value })} value={form.notes} /></FormField>

                        {quote !== null && (
                            <div className="flex flex-col gap-2 text-sm" role="status">
                                {quote.violations.length === 0 && quote.availability.sold_out_nights.length === 0
                                    ? <StatusBadge label={t('rates.quote.bookable')} tone="success" />
                                    : <div><StatusBadge label={t('rates.quote.notBookable')} tone="warning" /><ul className="mt-2 list-disc pl-5">
                                        {quote.violations.map((v, i) => <li key={i}>{violation(v)}</li>)}
                                        {quote.availability.sold_out_nights.length > 0 && <li>{t('fo.error.noAvailability')} ({quote.availability.sold_out_nights.map((d) => format.date(d)).join(', ')})</li>}
                                    </ul></div>}
                                {quote.availability.overbooking_nights.length > 0 && <Alert title={t('fo.oversell.title')} tone="warning">{t('fo.oversell.body', { nights: quote.availability.overbooking_nights.map((d) => format.date(d)).join(', ') })}</Alert>}
                                {quote.nights.length > 0 && <p className="font-medium">{t('fo.res.total')}: {format.money(quote.total_minor, quote.currency)}</p>}
                            </div>
                        )}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button onClick={() => setOversellOpen(false)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void create(true)} type="button">{t('fo.oversell.accept')}</Button>
                </>}
                onClose={() => setOversellOpen(false)}
                open={oversellOpen}
                title={t('fo.oversell.title')}
            >
                <div className="flex flex-col gap-3">
                    <p className="text-sm">{t('fo.oversell.body', { nights: quote?.availability.overbooking_nights.map((d) => format.date(d)).join(', ') ?? '' })}</p>
                    {action.error !== null && conflictReason !== 'oversell_warning' ? <ErrorState {...errorCopy} error={action.error} /> : null}
                    <FormField error={field('oversell_reason')} label={t('fo.oversell.reason')}><Input maxLength={500} onChange={(e) => set({ oversellReason: e.target.value })} value={form?.oversellReason ?? ''} /></FormField>
                </div>
            </Dialog>
        </FrontOfficeShell>
    );
}
