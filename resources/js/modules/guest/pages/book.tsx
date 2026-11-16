import { Head } from '@inertiajs/react';
import { BedDouble, CalendarCheck, Check, CircleCheck, ShieldCheck, Users, Wallet } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { PoweredBy } from '@/components/layout/powered-by';
import { apiRequest, newIdempotencyKey } from '@/shared/api/http';
import { ApiError } from '@/shared/lib/api-error';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { useBrand } from '@/shared/lib/brand';
import { cn } from '@/shared/lib/utils';

type Booking = { hotel: string; notice: string | null; max_nights: number; today: string; horizon_days: number; privacy: { version: number; body_id: string; body_en: string }; form_token: string };
type Offers = { currency: string | null; nights: number; offers: Offer[] };
type Offer = { room_type_id: string; code: string; name: string; max_adults: number; max_children: number; available: boolean; reason: string | null; currency: string; total_minor: number; nights: { date: string; total_minor: number }[]; photos: string[] };
type Done = { number: string; status: string; total_minor: number; currency: string; arrival: string; departure: string; emailed: boolean };

/** The photos of a room type: the main one large, the others as small pictures under it to switch to; touching the large one opens it at full size. */
function RoomPhotos({ name, photos, propertyId }: { name: string; photos: string[]; propertyId: string }) {
    const { t } = useTranslation();
    const [index, setIndex] = useState(0);
    const [zoom, setZoom] = useState(false);

    if (photos.length === 0) {
        return (
            <div className="flex aspect-[4/3] w-full flex-col items-center justify-center gap-2 bg-surface-muted text-muted-foreground">
                <BedDouble aria-hidden="true" className="size-10" />
                <span className="text-xs">{t('guest.booking.noPhoto')}</span>
            </div>
        );
    }

    const url = (id: string, size: 'thumb' | 'full') => `/book/${propertyId}/photos/${id}?size=${size}`;

    return (
        <div className="flex flex-col gap-1.5">
            <button aria-label={t('guest.booking.photoOpen')} className="group block aspect-[4/3] w-full overflow-hidden bg-surface-muted" onClick={() => setZoom(true)} type="button">
                <img alt={t('guest.booking.photoAlt', { name, n: index + 1 })} className="size-full object-cover transition-transform duration-300 group-hover:scale-105 motion-reduce:transition-none" loading="lazy" src={url(photos[index], 'thumb')} />
            </button>
            {photos.length > 1 ? (
                <ul className="flex gap-1.5 overflow-x-auto pb-0.5">
                    {photos.map((id, i) => (
                        <li className="shrink-0" key={id}>
                            <button aria-label={t('guest.booking.photoShow', { n: i + 1 })} aria-pressed={i === index} className={cn('block h-11 w-14 overflow-hidden border-2', i === index ? 'border-brand' : 'border-transparent opacity-70 hover:opacity-100')} onClick={() => setIndex(i)} type="button">
                                <img alt="" className="size-full object-cover" loading="lazy" src={url(id, 'thumb')} />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
            <Dialog onClose={() => setZoom(false)} open={zoom} title={name}>
                <img alt={t('guest.booking.photoAlt', { name, n: index + 1 })} className="max-h-[70dvh] w-full object-contain" src={url(photos[index], 'full')} />
            </Dialog>
        </div>
    );
}

const addDays = (date: string, n: number) => new Date(Date.parse(`${date}T00:00:00Z`) + n * 86_400_000).toISOString().slice(0, 10);

/** The hotel's frame for the booking page: a dark band with the hotel, the promise and the step the guest is on; the page sits under it, wide enough for rooms beside a summary. */
function BookingFrame({ hotel, title, subtitle, step, children }: { hotel: string; title: string; subtitle?: string; step: number; children: ReactNode }) {
    const { t } = useTranslation();
    const own = useBrand().logoUrl;
    const steps = ['guest.booking.step.dates', 'guest.booking.step.room', 'guest.booking.step.details', 'guest.booking.step.done'] as const;
    const trust = [[ShieldCheck, 'guest.booking.trust.direct'], [CalendarCheck, 'guest.booking.trust.confirm'], [Wallet, 'guest.booking.trust.pay']] as const;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="bg-primary text-primary-foreground">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 py-3">
                    {own !== null ? <span className="bg-white px-2 py-1"><img alt={hotel} className="max-h-10 max-w-[10rem] w-auto object-contain" src={own} /></span> : <p className="min-w-0 truncate text-sm font-semibold uppercase tracking-[0.18em]">{hotel}</p>}
                    <LanguageSwitcher />
                </div>
                <div className="mx-auto max-w-6xl px-4 pb-20 pt-6 sm:pt-10">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-brand">{hotel}</p>
                    <h1 className="mt-2 text-3xl font-semibold leading-tight sm:text-4xl">{title}</h1>
                    {subtitle !== undefined ? <p className="mt-2 max-w-2xl text-sm text-primary-foreground/75 sm:text-base">{subtitle}</p> : null}
                    <ul className="mt-5 flex flex-wrap gap-x-6 gap-y-2 text-sm text-primary-foreground/85">
                        {trust.map(([Icon, key]) => <li className="flex items-center gap-2" key={key}><Icon aria-hidden="true" className="size-4 text-brand" />{t(key)}</li>)}
                    </ul>
                </div>
            </header>
            <main className="mx-auto -mt-14 flex max-w-6xl flex-col gap-5 px-4 pb-28 lg:pb-10">
                <nav aria-label={t('guest.booking.steps')} className="border border-border bg-surface px-4 py-3">
                    <ol className="flex items-center gap-2 overflow-x-auto text-sm">
                        {steps.map((key, i) => {
                            const n = i + 1;
                            const state = n < step ? 'past' : n === step ? 'now' : 'next';

                            return (
                                <li aria-current={state === 'now' ? 'step' : undefined} className="flex shrink-0 items-center gap-2" key={key}>
                                    {i > 0 ? <span aria-hidden="true" className={cn('h-px w-6 sm:w-12', n <= step ? 'bg-brand' : 'bg-border')} /> : null}
                                    <span className={cn('grid size-7 place-items-center text-xs font-semibold', state === 'next' ? 'border border-border text-muted-foreground' : 'bg-brand text-white')}>
                                        {state === 'past' ? <Check aria-hidden="true" className="size-4" /> : n}
                                    </span>
                                    <span className={cn(state === 'now' ? 'font-semibold' : 'text-muted-foreground', state !== 'now' && 'hidden sm:inline')}>{t(key)}</span>
                                </li>
                            );
                        })}
                    </ol>
                </nav>
                {children}
            </main>
            <p className="px-4 pb-6 text-center"><PoweredBy /></p>
        </div>
    );
}

/** The hotel's own booking page. A guest looks at what is free and what it costs, and sends a request; the hotel confirms it and the guest pays at the hotel. Nothing is charged here. */
export default function BookPage({ booking, property_id: propertyId }: { booking: Booking; property_id: string }) {
    const { locale, t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [dates, setDates] = useState({ arrival: booking.today, departure: addDays(booking.today, 1) });
    const [party, setParty] = useState({ adults: '2', children: '0' });
    const [offers, setOffers] = useState<Offers | null>(null);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);
    const [chosen, setChosen] = useState<Offer | null>(null);
    const [form, setForm] = useState({ name: '', phone: '', email: '', notes: '', agree: false, website: '' });
    const [key, setKey] = useState(() => newIdempotencyKey());
    const [done, setDone] = useState<Done | null>(null);
    const notice = locale === 'id' ? booking.privacy.body_id : booking.privacy.body_en;
    // Rooms and prices belong to the dates and party they were found for; changing either asks again.
    const changeDates = (next: typeof dates) => { setDates(next); setOffers(null); setChosen(null); };
    const changeParty = (next: typeof party) => { setParty(next); setOffers(null); setChosen(null); };

    async function search() {
        setSearching(true);
        setSearchError(null);
        setChosen(null);
        try {
            const body = await apiRequest<Offers>(`/book/${propertyId}/offers`, { query: { arrival: dates.arrival, departure: dates.departure, adults: party.adults, children: party.children } });
            setOffers(body);
        } catch (error) {
            const fields = error instanceof ApiError ? error.failure.fields : {};
            setSearchError(Object.values(fields)[0]?.[0] ?? t('guest.booking.searchFailed'));
            setOffers(null);
        } finally {
            setSearching(false);
        }
    }

    async function send() {
        if (chosen === null) return;
        const result = await action.run<Done>(`/book/${propertyId}`, {
            body: {
                ...dates, adults: Number(party.adults), children: Number(party.children), room_type_id: chosen.room_type_id, name: form.name, phone: form.phone, email: form.email, notes: form.notes,
                agree: form.agree, notice_version: booking.privacy.version, key, website: form.website, form_token: booking.form_token,
            },
        });
        if (result !== null) setDone(result);
    }

    function choose(o: Offer) {
        setChosen(o);
        action.clear();
        // The details form appears under the rooms; bring it into view once it is drawn.
        window.setTimeout(() => document.getElementById('bk-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
    }

    const valid = dates.arrival >= booking.today && dates.departure > dates.arrival && Number(party.adults) >= 1;
    const nightsLabel = (n: number) => t('guest.booking.nights', { count: n });
    const canSend = form.name.trim() !== '' && (form.phone.trim() !== '' || form.email.trim() !== '') && form.agree;

    if (done !== null) {
        return (
            <BookingFrame hotel={booking.hotel} step={4} title={t('guest.booking.doneTitle')}>
                <Head title={t('guest.booking.doneTitle')} />
                <section className="mx-auto flex w-full max-w-xl flex-col items-center gap-4 border border-border bg-surface px-5 py-8 text-center" data-testid="booking-done">
                    <span className="grid size-14 place-items-center bg-success/10 text-success"><CircleCheck aria-hidden="true" className="size-8" /></span>
                    <div>
                        <p className="text-sm text-muted-foreground">{t('guest.booking.doneNumberLabel')}</p>
                        <p className="mt-1 font-mono text-2xl font-semibold tracking-wider">{done.number}</p>
                    </div>
                    <p className="max-w-md text-sm text-muted-foreground">{t('guest.booking.doneBody')}</p>
                    <dl className="grid w-full grid-cols-2 gap-px border border-border bg-border text-left text-sm">
                        <div className="bg-surface p-3"><dt className="text-xs text-muted-foreground">{t('guest.booking.stay')}</dt><dd className="mt-1 font-medium">{format.date(done.arrival)} – {format.date(done.departure)}</dd></div>
                        <div className="bg-surface p-3"><dt className="text-xs text-muted-foreground">{t('guest.booking.total')}</dt><dd className="mt-1 font-semibold tabular-nums">{format.money(done.total_minor, done.currency)}</dd></div>
                    </dl>
                    <p className="text-sm text-muted-foreground">{done.emailed ? t('guest.booking.emailed') : t('guest.booking.noEmail')}</p>
                    {booking.notice !== null ? <p className="text-sm">{booking.notice}</p> : null}
                </section>
            </BookingFrame>
        );
    }

    const step = chosen !== null ? 3 : offers !== null ? 2 : 1;

    const summary = offers !== null ? (
        <div className="flex flex-col gap-3 border border-border bg-surface p-4">
            <h2 className="text-base font-semibold">{t('guest.booking.summary')}</h2>
            <dl className="flex flex-col gap-2 text-sm">
                <div className="flex justify-between gap-3"><dt className="text-muted-foreground">{t('guest.booking.stay')}</dt><dd className="text-right">{format.date(dates.arrival)} – {format.date(dates.departure)}<span className="block text-xs text-muted-foreground">{nightsLabel(offers.nights)}</span></dd></div>
                <div className="flex justify-between gap-3"><dt className="text-muted-foreground">{t('guest.booking.guests')}</dt><dd>{t('guest.booking.party', { adults: party.adults, children: party.children })}</dd></div>
                {chosen !== null ? <div className="flex justify-between gap-3"><dt className="text-muted-foreground">{t('guest.booking.step.room')}</dt><dd className="text-right font-medium">{chosen.name}</dd></div> : null}
            </dl>
            {chosen !== null ? (
                <>
                    <ul className="flex flex-col gap-1 border-t border-border pt-3 text-sm">
                        {chosen.nights.map((n) => <li className="flex justify-between gap-3" key={n.date}><span className="text-muted-foreground">{format.date(n.date)}</span><span className="tabular-nums">{format.money(n.total_minor, chosen.currency)}</span></li>)}
                    </ul>
                    <div className="flex items-baseline justify-between gap-3 border-t border-border pt-3">
                        <span className="text-sm font-medium">{t('guest.booking.total')}</span>
                        <span className="text-xl font-semibold tabular-nums">{format.money(chosen.total_minor, chosen.currency)}</span>
                    </div>
                    <p className="text-xs text-muted-foreground">{t('guest.booking.taxIncluded')}</p>
                </>
            ) : <p className="border-t border-border pt-3 text-sm text-muted-foreground">{t('guest.booking.pickRoom')}</p>}
        </div>
    ) : null;

    return (
        <BookingFrame hotel={booking.hotel} step={step} subtitle={t('guest.booking.subtitle')} title={t('guest.booking.title')}>
            <Head title={`${t('guest.booking.title')} · ${booking.hotel}`} />

            <section aria-labelledby="bk-search" className="order-first border border-border bg-surface p-4 sm:p-5">
                <h2 className="mb-3 text-base font-semibold" id="bk-search">{t('guest.booking.when')}</h2>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-[1fr_1fr_8rem_8rem_auto] lg:items-end">
                    <FormField label={t('guest.booking.arrival')}><DatePicker min={booking.today} onChange={(e) => { const arrival = e.target.value; changeDates({ arrival, departure: dates.departure > arrival ? dates.departure : addDays(arrival, 1) }); }} value={dates.arrival} /></FormField>
                    <FormField label={t('guest.booking.departure')}><DatePicker min={addDays(dates.arrival, 1)} onChange={(e) => changeDates({ ...dates, departure: e.target.value })} value={dates.departure} /></FormField>
                    <FormField label={t('guest.booking.adults')}><Select onChange={(e) => changeParty({ ...party, adults: e.target.value })} value={party.adults}>{[1, 2, 3, 4, 5, 6].map((n) => <option key={n} value={n}>{n}</option>)}</Select></FormField>
                    <FormField label={t('guest.booking.children')}><Select onChange={(e) => changeParty({ ...party, children: e.target.value })} value={party.children}>{[0, 1, 2, 3, 4].map((n) => <option key={n} value={n}>{n}</option>)}</Select></FormField>
                    <Button className="col-span-2 lg:col-span-1" disabled={!valid} loading={searching} onClick={() => void search()} size="lg" type="button" variant="accent">{t('guest.booking.check')}</Button>
                </div>
                {searchError !== null ? <p className="mt-3 text-sm text-destructive" role="alert">{searchError}</p> : null}
            </section>

            {booking.notice !== null ? <Alert title={booking.notice} tone="info" /> : null}

            {offers !== null ? (
                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
                    <div className="flex flex-col gap-5">
                        <section aria-labelledby="bk-offers" className="flex flex-col gap-3">
                            <h2 className="text-lg font-semibold" id="bk-offers">{t('guest.booking.rooms')} · <span className="font-normal text-muted-foreground">{nightsLabel(offers.nights)}</span></h2>
                            {offers.offers.length === 0 ? <Alert title={t('guest.booking.noneFit')} tone="warning" /> : (
                                <ul className="flex flex-col gap-4" data-testid="offers">
                                    {offers.offers.map((o) => {
                                        const isChosen = chosen?.room_type_id === o.room_type_id;
                                        const perNight = Math.round(o.total_minor / Math.max(1, offers.nights));

                                        return (
                                            <li className={cn('grid border bg-surface transition-colors sm:grid-cols-[18rem_minmax(0,1fr)]', isChosen ? 'border-brand ring-2 ring-brand/30' : 'border-border', !o.available && 'opacity-70')} key={o.room_type_id}>
                                                <div className="p-3 sm:pr-0"><RoomPhotos name={o.name} photos={o.photos} propertyId={propertyId} /></div>
                                                <div className="flex flex-col gap-3 p-4">
                                                    <div>
                                                        <p className="text-lg font-semibold leading-snug">{o.name}</p>
                                                        <p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground"><Users aria-hidden="true" className="size-4" />{t('guest.booking.fits', { adults: o.max_adults, children: o.max_children })}</p>
                                                    </div>
                                                    {o.available ? (
                                                        <>
                                                            {o.nights.length > 1 ? (
                                                                <details className="text-sm">
                                                                    <summary className="cursor-pointer text-muted-foreground hover:text-foreground">{t('guest.booking.priceDetail')}</summary>
                                                                    <ul className="mt-2 flex flex-col gap-1">
                                                                        {o.nights.map((n) => <li className="flex justify-between gap-3" key={n.date}><span className="text-muted-foreground">{format.date(n.date)}</span><span className="tabular-nums">{format.money(n.total_minor, o.currency)}</span></li>)}
                                                                    </ul>
                                                                </details>
                                                            ) : null}
                                                            <div className="mt-auto flex flex-wrap items-end justify-between gap-3 border-t border-border pt-3">
                                                                <div>
                                                                    <p className="text-2xl font-semibold tabular-nums leading-none">{format.money(perNight, o.currency)} <span className="text-sm font-normal text-muted-foreground">{t('guest.booking.perNight')}</span></p>
                                                                    <p className="mt-1 text-xs text-muted-foreground">{t('guest.booking.totalFor', { count: offers.nights })}: <span className="font-medium text-foreground tabular-nums">{format.money(o.total_minor, o.currency)}</span></p>
                                                                </div>
                                                                <Button aria-pressed={isChosen} className="w-full sm:w-auto" onClick={() => choose(o)} type="button" variant={isChosen ? 'default' : 'accent'}>{isChosen ? <><Check aria-hidden="true" className="size-4" />{t('guest.booking.chosen')}</> : t('guest.booking.choose')}</Button>
                                                            </div>
                                                        </>
                                                    ) : <p className="mt-auto border-t border-border pt-3 text-sm font-medium text-muted-foreground">{t(o.reason === 'sold_out' ? 'guest.booking.soldOut' : 'guest.booking.notOffered')}</p>}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </section>

                        {chosen !== null ? (
                            <section aria-labelledby="bk-form" className="flex scroll-mt-4 flex-col gap-4 border border-border bg-surface p-4 sm:p-5">
                                <h2 className="text-lg font-semibold" id="bk-form">{t('guest.booking.yourDetails')}</h2>
                                {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                                <FormField error={action.fieldError('name')} label={t('guest.booking.name')}><Input autoComplete="name" onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField error={action.fieldError('phone')} label={t('guest.booking.phone')}><Input autoComplete="tel" inputMode="tel" onChange={(e) => setForm({ ...form, phone: e.target.value })} value={form.phone} /></FormField>
                                    <FormField error={action.fieldError('email')} label={t('guest.booking.email')}><Input autoComplete="email" inputMode="email" onChange={(e) => setForm({ ...form, email: e.target.value })} type="email" value={form.email} /></FormField>
                                </div>
                                <p className="-mt-2 text-xs text-muted-foreground">{t('guest.booking.contactHint')}</p>
                                <FormField error={action.fieldError('notes')} label={t('guest.booking.notes')}><Textarea maxLength={300} onChange={(e) => setForm({ ...form, notes: e.target.value })} rows={2} value={form.notes} /></FormField>
                                {/* A field only a program fills in: people never see it. */}
                                <div aria-hidden="true" className="absolute -left-[9999px] h-0 w-0 overflow-hidden"><label>Website<input autoComplete="off" name="website" onChange={(e) => setForm({ ...form, website: e.target.value })} tabIndex={-1} value={form.website} /></label></div>
                                <details className="border border-border bg-surface-muted p-3 text-sm" data-testid="booking-notice">
                                    <summary className="cursor-pointer font-medium">{t('guest.booking.privacy')}</summary>
                                    <p className="mt-2 whitespace-pre-line text-muted-foreground">{notice}</p>
                                </details>
                                <label className="flex items-start gap-2 text-sm"><Checkbox checked={form.agree} className="mt-0.5" onCheckedChange={(v) => setForm({ ...form, agree: v === true })} />{t('guest.booking.agree')}</label>
                                {action.fieldError('agree') !== undefined ? <p className="text-sm text-destructive" role="alert">{action.fieldError('agree')}</p> : null}
                                <div className="flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                                    <p className="flex items-center gap-2 text-sm text-muted-foreground"><Wallet aria-hidden="true" className="size-4 text-brand" />{t('guest.booking.payAtHotel')}</p>
                                    <Button className="w-full sm:w-auto" disabled={!canSend} loading={action.busy} onClick={() => void send()} size="lg" type="button" variant="accent">{t('guest.booking.send')}</Button>
                                </div>
                            </section>
                        ) : null}
                    </div>

                    <aside className="hidden lg:sticky lg:top-4 lg:block">{summary}</aside>
                </div>
            ) : null}

            {chosen !== null ? (
                <div className="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-surface px-4 py-3 lg:hidden">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-3">
                        <div className="min-w-0">
                            <p className="truncate text-xs text-muted-foreground">{chosen.name} · {nightsLabel(offers?.nights ?? 1)}</p>
                            <p className="text-lg font-semibold tabular-nums">{format.money(chosen.total_minor, chosen.currency)}</p>
                        </div>
                        <Button onClick={() => document.getElementById('bk-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' })} type="button" variant="accent">{t('guest.booking.toDetails')}</Button>
                    </div>
                </div>
            ) : null}
        </BookingFrame>
    );
}
