import { Head } from '@inertiajs/react';
import { useState } from 'react';

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
import { GuestShell } from '@/modules/guest/components/guest-shell';
import { apiRequest, newIdempotencyKey } from '@/shared/api/http';
import { ApiError } from '@/shared/lib/api-error';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Booking = { hotel: string; notice: string | null; max_nights: number; today: string; horizon_days: number; privacy: { version: number; body_id: string; body_en: string } };
type Offers = { currency: string | null; nights: number; offers: Offer[] };
type Offer = { room_type_id: string; code: string; name: string; max_adults: number; max_children: number; available: boolean; reason: string | null; currency: string; total_minor: number; nights: { date: string; total_minor: number }[]; photos: string[] };
type Done = { number: string; status: string; total_minor: number; currency: string; arrival: string; departure: string; emailed: boolean };

/** The photos of a room type: the main one large, the others as small pictures under it to switch to; touching the large one opens it at full size. */
function RoomPhotos({ name, photos, propertyId }: { name: string; photos: string[]; propertyId: string }) {
    const { t } = useTranslation();
    const [index, setIndex] = useState(0);
    const [zoom, setZoom] = useState(false);

    if (photos.length === 0) return null;

    const url = (id: string, size: 'thumb' | 'full') => `/book/${propertyId}/photos/${id}?size=${size}`;

    return (
        <div className="flex flex-col gap-1.5">
            <button aria-label={t('guest.booking.photoOpen')} className="block aspect-[16/9] w-full overflow-hidden bg-surface-muted" onClick={() => setZoom(true)} type="button">
                <img alt={t('guest.booking.photoAlt', { name, n: index + 1 })} className="size-full object-cover" loading="lazy" src={url(photos[index], 'thumb')} />
            </button>
            {photos.length > 1 ? (
                <ul className="flex gap-1.5 overflow-x-auto">
                    {photos.map((id, i) => (
                        <li className="shrink-0" key={id}>
                            <button aria-label={t('guest.booking.photoShow', { n: i + 1 })} aria-pressed={i === index} className={`block h-12 w-16 overflow-hidden border ${i === index ? 'border-foreground' : 'border-border opacity-80'}`} onClick={() => setIndex(i)} type="button">
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
                agree: form.agree, notice_version: booking.privacy.version, key, website: form.website,
            },
        });
        if (result !== null) setDone(result);
    }

    const valid = dates.arrival >= booking.today && dates.departure > dates.arrival && Number(party.adults) >= 1;
    const nightsLabel = (n: number) => t('guest.booking.nights', { count: n });

    if (done !== null) {
        return (
            <GuestShell hotel={booking.hotel} plain title={t('guest.booking.doneTitle')}>
                <Head title={t('guest.booking.doneTitle')} />
                <Alert title={t('guest.booking.doneNumber', { number: done.number })} tone="success">{t('guest.booking.doneBody')}</Alert>
                <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]" data-testid="booking-done">
                    <dt className="text-muted-foreground">{t('guest.booking.stay')}</dt><dd>{format.date(done.arrival)} – {format.date(done.departure)}</dd>
                    <dt className="text-muted-foreground">{t('guest.booking.total')}</dt><dd className="font-medium">{format.money(done.total_minor, done.currency)}</dd>
                </dl>
                <p className="text-sm text-muted-foreground">{done.emailed ? t('guest.booking.emailed') : t('guest.booking.noEmail')}</p>
                {booking.notice !== null ? <p className="text-sm">{booking.notice}</p> : null}
            </GuestShell>
        );
    }

    return (
        <GuestShell hotel={booking.hotel} plain subtitle={t('guest.booking.subtitle')} title={t('guest.booking.title')}>
            <Head title={`${t('guest.booking.title')} · ${booking.hotel}`} />
            {booking.notice !== null ? <Alert title={booking.notice} tone="info" /> : null}

            <section aria-labelledby="bk-search" className="flex flex-col gap-3">
                <h2 className="text-base font-semibold" id="bk-search">{t('guest.booking.when')}</h2>
                <div className="grid grid-cols-2 gap-3">
                    <FormField label={t('guest.booking.arrival')}><DatePicker min={booking.today} onChange={(e) => { const arrival = e.target.value; setDates({ arrival, departure: dates.departure > arrival ? dates.departure : addDays(arrival, 1) }); }} value={dates.arrival} /></FormField>
                    <FormField label={t('guest.booking.departure')}><DatePicker min={addDays(dates.arrival, 1)} onChange={(e) => setDates({ ...dates, departure: e.target.value })} value={dates.departure} /></FormField>
                    <FormField label={t('guest.booking.adults')}><Select onChange={(e) => setParty({ ...party, adults: e.target.value })} value={party.adults}>{[1, 2, 3, 4, 5, 6].map((n) => <option key={n} value={n}>{n}</option>)}</Select></FormField>
                    <FormField label={t('guest.booking.children')}><Select onChange={(e) => setParty({ ...party, children: e.target.value })} value={party.children}>{[0, 1, 2, 3, 4].map((n) => <option key={n} value={n}>{n}</option>)}</Select></FormField>
                </div>
                <div><Button disabled={!valid} loading={searching} onClick={() => void search()} type="button">{t('guest.booking.check')}</Button></div>
                {searchError !== null ? <p className="text-sm text-destructive" role="alert">{searchError}</p> : null}
            </section>

            {offers !== null ? (
                <section aria-labelledby="bk-offers" className="flex flex-col gap-3">
                    <h2 className="text-base font-semibold" id="bk-offers">{t('guest.booking.rooms')} · {nightsLabel(offers.nights)}</h2>
                    {offers.offers.length === 0 ? <Alert title={t('guest.booking.noneFit')} tone="warning" /> : (
                        <ul className="flex flex-col gap-3" data-testid="offers">
                            {offers.offers.map((o) => (
                                <li className="flex flex-col gap-2 border border-border bg-surface p-3" key={o.room_type_id}>
                                    <RoomPhotos name={o.name} photos={o.photos} propertyId={propertyId} />
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <p className="font-semibold">{o.name}</p>
                                        <p className="text-sm text-muted-foreground">{t('guest.booking.fits', { adults: o.max_adults, children: o.max_children })}</p>
                                    </div>
                                    {o.available ? (
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <p className="text-lg font-semibold tabular-nums">{format.money(o.total_minor, o.currency)}</p>
                                                <p className="text-xs text-muted-foreground">{t('guest.booking.taxIncluded')}</p>
                                            </div>
                                            <Button onClick={() => { setChosen(o); action.clear(); }} type="button" variant={chosen?.room_type_id === o.room_type_id ? 'default' : 'outline'}>{t(chosen?.room_type_id === o.room_type_id ? 'guest.booking.chosen' : 'guest.booking.choose')}</Button>
                                        </div>
                                    ) : <p className="text-sm text-muted-foreground">{t(o.reason === 'sold_out' ? 'guest.booking.soldOut' : 'guest.booking.notOffered')}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            ) : null}

            {chosen !== null ? (
                <section aria-labelledby="bk-form" className="flex flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-base font-semibold" id="bk-form">{t('guest.booking.yourDetails')}</h2>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={action.fieldError('name')} label={t('guest.booking.name')}><Input autoComplete="name" onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                    <FormField error={action.fieldError('phone')} label={t('guest.booking.phone')}><Input autoComplete="tel" inputMode="tel" onChange={(e) => setForm({ ...form, phone: e.target.value })} value={form.phone} /></FormField>
                    <FormField error={action.fieldError('email')} hint={t('guest.booking.contactHint')} label={t('guest.booking.email')}><Input autoComplete="email" inputMode="email" onChange={(e) => setForm({ ...form, email: e.target.value })} type="email" value={form.email} /></FormField>
                    <FormField error={action.fieldError('notes')} label={t('guest.booking.notes')}><Textarea maxLength={300} onChange={(e) => setForm({ ...form, notes: e.target.value })} rows={2} value={form.notes} /></FormField>
                    {/* A field only a program fills in: people never see it. */}
                    <div aria-hidden="true" className="absolute -left-[9999px] h-0 w-0 overflow-hidden"><label>Website<input autoComplete="off" name="website" onChange={(e) => setForm({ ...form, website: e.target.value })} tabIndex={-1} value={form.website} /></label></div>
                    <details className="border border-border bg-surface p-3 text-sm" data-testid="booking-notice">
                        <summary className="cursor-pointer font-medium">{t('guest.booking.privacy')}</summary>
                        <p className="mt-2 whitespace-pre-line text-muted-foreground">{notice}</p>
                    </details>
                    <label className="flex items-start gap-2 text-sm"><Checkbox checked={form.agree} className="mt-0.5" onCheckedChange={(v) => setForm({ ...form, agree: v === true })} />{t('guest.booking.agree')}</label>
                    {action.fieldError('agree') !== undefined ? <p className="text-sm text-destructive" role="alert">{action.fieldError('agree')}</p> : null}
                    <p className="text-sm text-muted-foreground">{t('guest.booking.payAtHotel')}</p>
                    <Button disabled={form.name.trim() === '' || (form.phone.trim() === '' && form.email.trim() === '') || !form.agree} loading={action.busy} onClick={() => void send()} type="button">{t('guest.booking.send')}</Button>
                </section>
            ) : null}
        </GuestShell>
    );
}
