import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { minorToMajorText, parseMajorToMinor } from '@/shared/money/money';

type RoomType = { id: string; code: string; name: string; rooms: { id: string; number: string }[] };
type Rate = { id: string; room_type_id: string; price_minor: number; max_hours: number; is_active: boolean; lock_version: number };
type Booking = { id: string; number: string; room_id: string; room_number: string; starts_on: string; starts_at: string; ends_at: string; guest_name: string; guests: number; price_minor: number; total_minor: number; status: string; payment_method: string | null; note: string | null; reason: string | null; lock_version: number };
type Overview = { currency: string; today: string; rules: { earliest_start: string; latest_end: string; after_checkout: string; max_advance_days: number }; methods: string[]; types: RoomType[]; rates: Rate[]; bookings: Booking[]; may: { book: boolean; manage: boolean } };

const TONE: Record<string, StatusTone> = { booked: 'info', in_use: 'warning', done: 'success', cancelled: 'neutral', no_show: 'neutral' };

/** Day use: a room rented for some hours with no night in it. Prices by room type, bookings, the guest let in with the payment, and the guest leaving. */
export default function DayUsePage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['overview'];
    const money = (minor: number) => format.money(minor, overview.currency);
    const priced = overview.types.filter((type) => overview.rates.some((r) => r.room_type_id === type.id && r.is_active));
    const typeName = (id: string) => overview.types.find((x) => x.id === id)?.name ?? '—';
    const [book, setBook] = useState<{ type: string; room: string; day: string; time: string; hours: string; name: string; phone: string; guests: string; note: string; intent: string } | null>(null);
    const [rate, setRate] = useState<{ id: string | null; lock: number; type: string; price: string; hours: string; active: boolean } | null>(null);
    const [pay, setPay] = useState<{ booking: Booking; method: string; reference: string } | null>(null);
    const [cancel, setCancel] = useState<{ booking: Booking; reason: string } | null>(null);

    async function saveBooking() {
        if (book === null) return;
        const done = await action.run('/front-office/day-use/bookings', { body: { room_id: book.room, starts_on: book.day, starts_at: book.time, hours: Number(book.hours) || 0, guest_name: book.name.trim(), guest_phone: book.phone.trim() || null, guests: Number(book.guests) || 0, note: book.note.trim() || null }, idempotencyKey: book.intent, reload });
        if (done !== null) setBook(null);
    }

    async function saveRate() {
        if (rate === null) return;
        const body = { price_minor: parseMajorToMinor(rate.price, overview.currency) ?? 0, max_hours: Number(rate.hours) || 0 };
        const done = rate.id === null
            ? await action.run('/front-office/day-use/rates', { body: { ...body, room_type_id: rate.type }, reload })
            : await action.run(`/front-office/day-use/rates/${rate.id}`, { body: { ...body, is_active: rate.active, lock_version: rate.lock }, reload });
        if (done !== null) setRate(null);
    }

    async function startNow() {
        if (pay === null) return;
        const done = await action.run(`/front-office/day-use/bookings/${pay.booking.id}/start`, { body: { payment_method: pay.method, reference: pay.reference.trim() || null, lock_version: pay.booking.lock_version }, reload });
        if (done !== null) setPay(null);
    }

    async function settle(b: Booking, what: 'finish' | 'no-show') {
        await action.run(`/front-office/day-use/bookings/${b.id}/${what}`, { body: { lock_version: b.lock_version }, reload });
    }

    async function cancelNow() {
        if (cancel === null) return;
        const done = await action.run(`/front-office/day-use/bookings/${cancel.booking.id}/cancel`, { body: { reason: cancel.reason.trim(), lock_version: cancel.booking.lock_version }, reload });
        if (done !== null) setCancel(null);
    }

    const chosenType = book === null ? undefined : overview.types.find((x) => x.id === book.type);
    const chosenRate = book === null ? undefined : overview.rates.find((r) => r.room_type_id === book.type);

    const bookings: DataGridColumn<Booking>[] = [
        { id: 'when', label: t('fo.du.col.when'), value: (b) => `${b.starts_on} ${b.starts_at}`, cell: (b) => <span>{format.date(b.starts_on)} {b.starts_at}–{b.ends_at}<span className="block text-xs text-muted-foreground">{b.number}</span></span> },
        { id: 'room', label: t('fo.du.col.room'), value: (b) => b.room_number, rowHeader: true, cell: (b) => <span>{b.room_number}<span className="block text-xs text-muted-foreground">{b.guest_name} · {t('fo.act.people', { n: b.guests })}{b.note !== null ? ` · ${b.note}` : ''}</span></span> },
        { id: 'price', label: t('fo.du.col.price'), align: 'right', value: (b) => (b.total_minor > 0 ? b.total_minor : b.price_minor), cell: (b) => <span>{money(b.total_minor > 0 ? b.total_minor : b.price_minor)}{b.payment_method !== null ? <span className="block text-xs text-muted-foreground">{t(`fo.gc.method.${b.payment_method}` as 'fo.gc.method.cash')}</span> : <span className="block text-xs text-muted-foreground">{t('fo.du.beforeCharges')}</span>}</span> },
        { id: 'status', label: t('fo.act.col.status'), value: (b) => b.status, filter: 'select', filterLabel: (v) => t(`fo.du.status.${v}` as 'fo.du.status.booked'), cell: (b) => <span><StatusBadge label={t(`fo.du.status.${b.status}` as 'fo.du.status.booked')} tone={TONE[b.status] ?? 'neutral'} />{b.reason !== null ? <span className="block text-xs text-muted-foreground">{b.reason}</span> : null}</span> },
        {
            id: 'actions',
            label: '',
            value: () => '',
            sortable: false,
            cell: (b) => {
                if (!overview.may.book) return null;
                if (b.status === 'in_use') return <Button disabled={action.busy} onClick={() => void settle(b, 'finish')} size="sm" type="button">{t('fo.du.finish')}</Button>;
                if (b.status !== 'booked') return null;

                return (
                    <span className="flex flex-wrap justify-end gap-2">
                        {b.starts_on === overview.today ? <Button onClick={() => { action.clear(); setPay({ booking: b, method: 'cash', reference: '' }); }} size="sm" type="button">{t('fo.du.start')}</Button> : null}
                        {b.starts_on <= overview.today ? <Button disabled={action.busy} onClick={() => void settle(b, 'no-show')} size="sm" type="button" variant="outline">{t('fo.act.noShow')}</Button> : null}
                        <Button onClick={() => { action.clear(); setCancel({ booking: b, reason: '' }); }} size="sm" type="button" variant="ghost">{t('fo.act.cancel')}</Button>
                    </span>
                );
            },
        },
    ];

    const rates: DataGridColumn<Rate>[] = [
        { id: 'type', label: t('fo.du.col.type'), value: (r) => typeName(r.room_type_id), rowHeader: true, cell: (r) => typeName(r.room_type_id) },
        { id: 'price', label: t('fo.du.col.price'), align: 'right', value: (r) => r.price_minor, cell: (r) => money(r.price_minor) },
        { id: 'hours', label: t('fo.du.col.hours'), align: 'right', value: (r) => r.max_hours, cell: (r) => t('fo.du.hours', { n: r.max_hours }) },
        { id: 'active', label: t('fo.act.col.status'), value: (r) => (r.is_active ? 'on' : 'off'), cell: (r) => <StatusBadge label={r.is_active ? t('fo.act.offered') : t('fo.act.paused')} tone={r.is_active ? 'success' : 'neutral'} /> },
        { id: 'actions', label: '', value: () => '', sortable: false, cell: (r) => (overview.may.manage ? <Button onClick={() => { action.clear(); setRate({ id: r.id, lock: r.lock_version, type: r.room_type_id, price: minorToMajorText(r.price_minor, overview.currency), hours: String(r.max_hours), active: r.is_active }); }} size="sm" type="button" variant="outline">{t('fo.act.edit')}</Button> : null) },
    ];

    return (
        <FrontOfficeShell description={t('fo.du.description', { from: overview.rules.earliest_start, to: overview.rules.latest_end })} title={t('fo.du.title')} wide>
            {action.error !== null && book === null && rate === null && pay === null && cancel === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <div className="flex flex-wrap items-center gap-3 print:hidden">
                {overview.may.book ? <Button disabled={priced.length === 0} onClick={() => { action.clear(); const first = priced[0]; setBook({ type: first.id, room: first.rooms[0]?.id ?? '', day: overview.today, time: overview.rules.earliest_start > '10:00' ? overview.rules.earliest_start : '10:00', hours: '4', name: '', phone: '', guests: '1', note: '', intent: newIdempotencyKey() }); }} type="button">{t('fo.du.new')}</Button> : null}
                {overview.may.manage ? <Button disabled={overview.types.every((x) => overview.rates.some((r) => r.room_type_id === x.id))} onClick={() => { action.clear(); setRate({ id: null, lock: 0, type: overview.types.find((x) => !overview.rates.some((r) => r.room_type_id === x.id))?.id ?? '', price: '', hours: '6', active: true }); }} type="button" variant="outline">{t('fo.du.addRate')}</Button> : null}
                {priced.length === 0 ? <span className="text-sm text-muted-foreground">{t('fo.du.noPrice')}</span> : null}
            </div>
            <DataGrid caption={t('fo.du.bookings')} columns={bookings} empty={<EmptyState title={t('fo.du.none')} />} getRowId={(b) => b.id} id="fo.dayuse.bookings" rows={overview.bookings} testId="day-use-bookings" />
            <h2 className="text-base font-semibold">{t('fo.du.prices')}</h2>
            <DataGrid caption={t('fo.du.prices')} columns={rates} empty={<EmptyState title={t('fo.du.noRates')} />} getRowId={(r) => r.id} id="fo.dayuse.rates" rows={overview.rates} testId="day-use-rates" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setBook(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveBooking()} type="button">{t('fo.du.new')}</Button></>}
                onClose={() => setBook(null)}
                open={book !== null}
                title={t('fo.du.new')}
            >
                {book !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField hint={chosenRate === undefined ? undefined : t('fo.du.priceHint', { price: money(chosenRate.price_minor), hours: chosenRate.max_hours })} label={t('fo.du.col.type')}><Select onChange={(e) => { const next = overview.types.find((x) => x.id === e.target.value); setBook({ ...book, type: e.target.value, room: next?.rooms[0]?.id ?? '' }); }} value={book.type}>{priced.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('room_id')} field="room_id" label={t('fo.du.col.room')}><Select onChange={(e) => setBook({ ...book, room: e.target.value })} value={book.room}>{(chosenType?.rooms ?? []).map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('starts_on')} field="starts_on" label={t('fo.act.day')}><DatePicker min={overview.today} onChange={(e) => setBook({ ...book, day: e.target.value })} value={book.day} /></FormField>
                        <FormField error={action.fieldError('starts_at')} field="starts_at" hint={t('fo.du.timeHint', { from: overview.rules.earliest_start, to: overview.rules.latest_end })} label={t('fo.act.time')}><Input inputMode="numeric" maxLength={5} onChange={(e) => setBook({ ...book, time: e.target.value })} placeholder="10:00" value={book.time} /></FormField>
                        <FormField error={action.fieldError('hours')} field="hours" label={t('fo.du.col.hours')}><Input inputMode="numeric" maxLength={2} onChange={(e) => setBook({ ...book, hours: e.target.value.replace(/\D/g, '') })} value={book.hours} /></FormField>
                        <FormField error={action.fieldError('guests')} field="guests" label={t('fo.act.guests')}><Input inputMode="numeric" maxLength={2} onChange={(e) => setBook({ ...book, guests: e.target.value.replace(/\D/g, '') })} value={book.guests} /></FormField>
                        <FormField error={action.fieldError('guest_name')} field="guest_name" label={t('fo.du.guestName')}><Input maxLength={120} onChange={(e) => setBook({ ...book, name: e.target.value })} value={book.name} /></FormField>
                        <FormField error={action.fieldError('guest_phone')} field="guest_phone" label={t('fo.du.guestPhone')}><Input inputMode="tel" maxLength={30} onChange={(e) => setBook({ ...book, phone: e.target.value })} value={book.phone} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('note')} field="note" label={t('fo.act.note')}><Input maxLength={200} onChange={(e) => setBook({ ...book, note: e.target.value })} value={book.note} /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setRate(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveRate()} type="button">{t('fo.act.save')}</Button></>}
                onClose={() => setRate(null)}
                open={rate !== null}
                title={rate?.id === null ? t('fo.du.addRate') : t('fo.act.edit')}
            >
                {rate !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('room_type_id')} field="room_type_id" label={t('fo.du.col.type')}><Select disabled={rate.id !== null} onChange={(e) => setRate({ ...rate, type: e.target.value })} value={rate.type}>{overview.types.filter((x) => rate.id !== null || !overview.rates.some((r) => r.room_type_id === x.id)).map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('price_minor')} field="price_minor" hint={t('fo.du.rateHint')} label={t('fo.du.priceWhole')}><MoneyInput onChange={(e) => setRate({ ...rate, price: e.target.value })} value={rate.price} /></FormField>
                        <FormField error={action.fieldError('max_hours')} field="max_hours" label={t('fo.du.longest')}><Input inputMode="numeric" maxLength={2} onChange={(e) => setRate({ ...rate, hours: e.target.value.replace(/\D/g, '') })} value={rate.hours} /></FormField>
                        {rate.id !== null ? <label className="flex items-center gap-2 text-sm"><Checkbox checked={rate.active} onCheckedChange={(v) => setRate({ ...rate, active: v === true })} />{t('fo.act.offered')}</label> : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setPay(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void startNow()} type="button">{t('fo.du.start')}</Button></>}
                onClose={() => setPay(null)}
                open={pay !== null}
                title={t('fo.du.payTitle')}
            >
                {pay !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{t('fo.du.payBody', { room: pay.booking.room_number, name: pay.booking.guest_name, price: money(pay.booking.price_minor) })}</p>
                        <FormField error={action.fieldError('payment_method')} field="payment_method" label={t('fo.gc.paidBy')}><Select onChange={(e) => setPay({ ...pay, method: e.target.value })} value={pay.method}>{overview.methods.map((m) => <option key={m} value={m}>{t(`fo.gc.method.${m}` as 'fo.gc.method.cash')}</option>)}</Select></FormField>
                        {pay.method !== 'cash' ? <FormField error={action.fieldError('reference')} field="reference" label={t('fo.folio.reference')}><Input maxLength={60} onChange={(e) => setPay({ ...pay, reference: e.target.value })} value={pay.reference} /></FormField> : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setCancel(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={cancel?.reason.trim() === ''} loading={action.busy} onClick={() => void cancelNow()} type="button">{t('fo.act.cancel')}</Button></>}
                onClose={() => setCancel(null)}
                open={cancel !== null}
                title={t('fo.act.cancelTitle')}
            >
                {cancel !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{cancel.booking.room_number} · {cancel.booking.guest_name} · {format.date(cancel.booking.starts_on)} {cancel.booking.starts_at}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fo.lock.reason')}><Input maxLength={200} onChange={(e) => setCancel({ ...cancel, reason: e.target.value })} value={cancel.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
