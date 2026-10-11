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

type Service = { id: string; code: string; name: string; category: string; duration_minutes: number; price_minor: number; capacity: number; is_active: boolean; lock_version: number };
type Booking = { id: string; number: string; service_id: string; service_name: string; starts_on: string; starts_at: string; duration_minutes: number; guests: number; guest_name: string; price_minor: number; status: string; note: string | null; reason: string | null; lock_version: number };
type Guest = { reservation_id: string; guest_name: string; room: string; departure: string; arrival?: string };
type Overview = { currency: string; today: string; categories: string[]; services: Service[]; bookings: Booking[]; in_house: Guest[]; may: { book: boolean; manage: boolean } };

const TONE: Record<string, StatusTone> = { booked: 'info', done: 'success', cancelled: 'neutral', no_show: 'warning' };

/** Spa and activities: what is offered, who is booked when, and what happens to a booking (given and charged to the room, cancelled, or the guest did not come). */
export default function ActivitiesPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['overview'];
    const money = (minor: number) => format.money(minor, overview.currency);
    const firstService = overview.services.find((s) => s.is_active);
    const [book, setBook] = useState<{ service: string; reservation: string; day: string; time: string; guests: string; note: string; intent: string } | null>(null);
    const [service, setService] = useState<{ id: string | null; lock: number; code: string; name: string; category: string; minutes: string; price: string; capacity: string; active: boolean } | null>(null);
    const [cancel, setCancel] = useState<{ booking: Booking; reason: string } | null>(null);

    async function saveBooking() {
        if (book === null) return;
        const done = await action.run('/front-office/activities/bookings', { body: { service_id: book.service, reservation_id: book.reservation, starts_on: book.day, starts_at: book.time, guests: Number(book.guests) || 0, note: book.note.trim() || null }, idempotencyKey: book.intent, reload });
        if (done !== null) setBook(null);
    }

    async function saveService() {
        if (service === null) return;
        const body = { name: service.name.trim(), duration_minutes: Number(service.minutes) || 0, price_minor: parseMajorToMinor(service.price, overview.currency) ?? 0, capacity: Number(service.capacity) || 0 };
        const done = service.id === null
            ? await action.run('/front-office/activities/services', { body: { ...body, code: service.code, category: service.category }, reload })
            : await action.run(`/front-office/activities/services/${service.id}`, { body: { ...body, is_active: service.active, lock_version: service.lock }, reload });
        if (done !== null) setService(null);
    }

    async function settle(b: Booking, what: 'complete' | 'no-show') {
        await action.run(`/front-office/activities/bookings/${b.id}/${what}`, { body: { lock_version: b.lock_version }, reload });
    }

    async function cancelNow() {
        if (cancel === null) return;
        const done = await action.run(`/front-office/activities/bookings/${cancel.booking.id}/cancel`, { body: { reason: cancel.reason.trim(), lock_version: cancel.booking.lock_version }, reload });
        if (done !== null) setCancel(null);
    }

    const bookings: DataGridColumn<Booking>[] = [
        { id: 'when', label: t('fo.act.col.when'), value: (b) => `${b.starts_on} ${b.starts_at}`, cell: (b) => <span>{format.date(b.starts_on)} {b.starts_at}<span className="block text-xs text-muted-foreground">{t('fo.act.minutes', { n: b.duration_minutes })}</span></span> },
        { id: 'service', label: t('fo.act.col.service'), value: (b) => b.service_name, rowHeader: true, cell: (b) => <span>{b.service_name}<span className="block text-xs text-muted-foreground">{b.number}{b.note !== null ? ` · ${b.note}` : ''}</span></span> },
        { id: 'guest', label: t('fo.act.col.guest'), value: (b) => b.guest_name, cell: (b) => <span>{b.guest_name}<span className="block text-xs text-muted-foreground">{t('fo.act.people', { n: b.guests })}</span></span> },
        { id: 'price', label: t('fo.act.col.price'), align: 'right', value: (b) => b.price_minor, cell: (b) => money(b.price_minor) },
        { id: 'status', label: t('fo.act.col.status'), value: (b) => b.status, filter: 'select', filterLabel: (v) => t(`fo.act.status.${v}` as 'fo.act.status.booked'), cell: (b) => <span><StatusBadge label={t(`fo.act.status.${b.status}` as 'fo.act.status.booked')} tone={TONE[b.status] ?? 'neutral'} />{b.reason !== null ? <span className="block text-xs text-muted-foreground">{b.reason}</span> : null}</span> },
        {
            id: 'actions',
            label: '',
            value: () => '',
            sortable: false,
            cell: (b) => (overview.may.book && b.status === 'booked' ? (
                <span className="flex flex-wrap justify-end gap-2">
                    {b.starts_on <= overview.today ? <Button disabled={action.busy} onClick={() => void settle(b, 'complete')} size="sm" type="button">{t('fo.act.done')}</Button> : null}
                    {b.starts_on <= overview.today ? <Button disabled={action.busy} onClick={() => void settle(b, 'no-show')} size="sm" type="button" variant="outline">{t('fo.act.noShow')}</Button> : null}
                    <Button onClick={() => { action.clear(); setCancel({ booking: b, reason: '' }); }} size="sm" type="button" variant="ghost">{t('fo.act.cancel')}</Button>
                </span>
            ) : null),
        },
    ];

    const services: DataGridColumn<Service>[] = [
        { id: 'service', label: t('fo.act.col.service'), value: (s) => s.name, rowHeader: true, cell: (s) => <span>{s.code} · {s.name}<span className="block text-xs text-muted-foreground">{t(`fo.act.cat.${s.category}` as 'fo.act.cat.spa')}</span></span> },
        { id: 'length', label: t('fo.act.col.length'), align: 'right', value: (s) => s.duration_minutes, cell: (s) => t('fo.act.minutes', { n: s.duration_minutes }) },
        { id: 'capacity', label: t('fo.act.col.capacity'), align: 'right', value: (s) => s.capacity, cell: (s) => t('fo.act.people', { n: s.capacity }) },
        { id: 'price', label: t('fo.act.col.price'), align: 'right', value: (s) => s.price_minor, cell: (s) => money(s.price_minor) },
        { id: 'active', label: t('fo.act.col.status'), value: (s) => (s.is_active ? 'on' : 'off'), cell: (s) => <StatusBadge label={s.is_active ? t('fo.act.offered') : t('fo.act.paused')} tone={s.is_active ? 'success' : 'neutral'} /> },
        { id: 'actions', label: '', value: () => '', sortable: false, cell: (s) => (overview.may.manage ? <Button onClick={() => { action.clear(); setService({ id: s.id, lock: s.lock_version, code: s.code, name: s.name, category: s.category, minutes: String(s.duration_minutes), price: minorToMajorText(s.price_minor, overview.currency), capacity: String(s.capacity), active: s.is_active }); }} size="sm" type="button" variant="outline">{t('fo.act.edit')}</Button> : null) },
    ];

    return (
        <FrontOfficeShell description={t('fo.act.description')} title={t('fo.act.title')} wide>
            {action.error !== null && book === null && service === null && cancel === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <div className="flex flex-wrap items-center gap-3 print:hidden">
                {overview.may.book ? <Button disabled={firstService === undefined || overview.in_house.length === 0} onClick={() => { action.clear(); setBook({ service: firstService?.id ?? '', reservation: overview.in_house[0]?.reservation_id ?? '', day: overview.today, time: '10:00', guests: '1', note: '', intent: newIdempotencyKey() }); }} type="button">{t('fo.act.new')}</Button> : null}
                {overview.may.manage ? <Button onClick={() => { action.clear(); setService({ id: null, lock: 0, code: '', name: '', category: 'spa', minutes: '60', price: '', capacity: '1', active: true }); }} type="button" variant="outline">{t('fo.act.addService')}</Button> : null}
            </div>
            <DataGrid caption={t('fo.act.bookings')} columns={bookings} empty={<EmptyState title={t('fo.act.noBookings')} />} getRowId={(b) => b.id} id="fo.activities.bookings" rows={overview.bookings} testId="activity-bookings" />
            <h2 className="text-base font-semibold">{t('fo.act.services')}</h2>
            <DataGrid caption={t('fo.act.services')} columns={services} empty={<EmptyState title={t('fo.act.noServices')} />} getRowId={(s) => s.id} id="fo.activities.services" rows={overview.services} testId="activity-services" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setBook(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveBooking()} type="button">{t('fo.act.new')}</Button></>}
                onClose={() => setBook(null)}
                open={book !== null}
                title={t('fo.act.new')}
            >
                {book !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('service_id')} field="service_id" label={t('fo.act.col.service')}><Select onChange={(e) => setBook({ ...book, service: e.target.value })} value={book.service}>{overview.services.filter((s) => s.is_active).map((s) => <option key={s.id} value={s.id}>{s.name} · {money(s.price_minor)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('reservation_id')} field="reservation_id" label={t('fo.act.col.guest')}><Select onChange={(e) => setBook({ ...book, reservation: e.target.value })} value={book.reservation}>{overview.in_house.map((g) => <option key={g.reservation_id} value={g.reservation_id}>{g.arrival === undefined ? g.room : t('fo.act.arrives', { date: format.date(g.arrival) })} · {g.guest_name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('starts_on')} field="starts_on" label={t('fo.act.day')}><DatePicker min={overview.today} onChange={(e) => setBook({ ...book, day: e.target.value })} value={book.day} /></FormField>
                        <FormField error={action.fieldError('starts_at')} field="starts_at" hint={t('fo.act.timeHint')} label={t('fo.act.time')}><Input inputMode="numeric" maxLength={5} onChange={(e) => setBook({ ...book, time: e.target.value })} placeholder="14:30" value={book.time} /></FormField>
                        <FormField error={action.fieldError('guests')} field="guests" label={t('fo.act.guests')}><Input inputMode="numeric" maxLength={2} onChange={(e) => setBook({ ...book, guests: e.target.value.replace(/\D/g, '') })} value={book.guests} /></FormField>
                        <FormField error={action.fieldError('note')} field="note" label={t('fo.act.note')}><Input maxLength={200} onChange={(e) => setBook({ ...book, note: e.target.value })} value={book.note} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setService(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveService()} type="button">{t('fo.act.save')}</Button></>}
                onClose={() => setService(null)}
                open={service !== null}
                title={service?.id === null ? t('fo.act.addService') : t('fo.act.edit')}
            >
                {service !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('code')} field="code" label={t('fin.acc.code')}><Input disabled={service.id !== null} maxLength={20} onChange={(e) => setService({ ...service, code: e.target.value })} value={service.code} /></FormField>
                        <FormField error={action.fieldError('name')} field="name" label={t('fin.acc.name')}><Input maxLength={120} onChange={(e) => setService({ ...service, name: e.target.value })} value={service.name} /></FormField>
                        <FormField error={action.fieldError('category')} field="category" label={t('fin.acc.category')}><Select disabled={service.id !== null} onChange={(e) => setService({ ...service, category: e.target.value })} value={service.category}>{overview.categories.map((c) => <option key={c} value={c}>{t(`fo.act.cat.${c}` as 'fo.act.cat.spa')}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('duration_minutes')} field="duration_minutes" label={t('fo.act.col.length')}><Input inputMode="numeric" maxLength={3} onChange={(e) => setService({ ...service, minutes: e.target.value.replace(/\D/g, '') })} value={service.minutes} /></FormField>
                        <FormField error={action.fieldError('price_minor')} field="price_minor" hint={t('fo.act.priceHint')} label={t('fo.act.priceEach')}><MoneyInput onChange={(e) => setService({ ...service, price: e.target.value })} value={service.price} /></FormField>
                        <FormField error={action.fieldError('capacity')} field="capacity" hint={t('fo.act.capacityHint')} label={t('fo.act.col.capacity')}><Input inputMode="numeric" maxLength={2} onChange={(e) => setService({ ...service, capacity: e.target.value.replace(/\D/g, '') })} value={service.capacity} /></FormField>
                        {service.id !== null ? <label className="flex items-center gap-2 text-sm sm:col-span-2"><Checkbox checked={service.active} onCheckedChange={(v) => setService({ ...service, active: v === true })} />{t('fo.act.offered')}</label> : null}
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
                        <p className="text-sm">{cancel.booking.service_name} · {cancel.booking.guest_name} · {format.date(cancel.booking.starts_on)} {cancel.booking.starts_at}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fo.lock.reason')}><Input maxLength={200} onChange={(e) => setCancel({ ...cancel, reason: e.target.value })} value={cancel.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
