import { Link, router } from '@inertiajs/react';
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
import { Progress } from '@/components/ui/progress';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { TimeInput } from '@/components/ui/time-input';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { clock, percentDone, secondsLeft, type SpaBooking, useRefresh, useServerClock } from '@/modules/front-office/lib/spa';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Room = { id: string; code: string; name: string; is_active: boolean; running: SpaBooking | null; bookings: SpaBooking[] };
type Treatment = { id: string; name: string; minutes: number; price_minor: number; is_active: boolean };
type Therapist = { id: string; name: string; is_active: boolean; present: boolean };
type Guest = { reservation_id: string; guest_name: string; room: string };
type Overview = {
    date: string;
    today: string;
    now: string;
    currency: string;
    methods: string[];
    rooms: Room[];
    bookings: SpaBooking[];
    treatments: Treatment[];
    therapists: Therapist[];
    guests: Guest[];
    may: { book: boolean; manage: boolean };
};

const TONE: Record<string, StatusTone> = { booked: 'info', accepted: 'info', declined: 'danger', in_progress: 'warning', done: 'success', cancelled: 'neutral', no_show: 'neutral' };
const WALK_IN = 'walk-in';

type BookForm = { room: string; date: string; time: string; guest: string; name: string; treatment: string; therapist: string; waiveService: boolean; waiveTax: boolean; intent: string };

/** The treatment rooms of the day: which one is in use and for how long still, who is booked next, and booking by touching a room (time, then the guest, the treatment and the therapist). */
export default function SpaPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['overview'];
    const now = useServerClock(overview.now);
    const money = (minor: number) => format.money(minor, overview.currency);
    const [book, setBook] = useState<BookForm | null>(null);
    const [assign, setAssign] = useState<{ booking: SpaBooking; therapist: string } | null>(null);
    const [cancel, setCancel] = useState<{ booking: SpaBooking; reason: string } | null>(null);
    const [pay, setPay] = useState<{ booking: SpaBooking; method: string; reference: string } | null>(null);
    const activeTreatments = overview.treatments.filter((x) => x.is_active);
    const activeTherapists = overview.therapists.filter((x) => x.is_active);
    const isToday = overview.date === overview.today;

    // A screen left open looks again by itself, so a room that is free again is shown free.
    useRefresh(() => router.reload({ only: reload }), 30_000);

    function open(room: Room) {
        action.clear();
        const t0 = new Date(now);
        const time = isToday ? `${String(t0.getHours()).padStart(2, '0')}:${String(Math.ceil(t0.getMinutes() / 5) * 5 % 60).padStart(2, '0')}` : '10:00';
        setBook({ room: room.id, date: overview.date, time, guest: overview.guests[0]?.reservation_id ?? WALK_IN, name: '', treatment: activeTreatments[0]?.id ?? '', therapist: activeTherapists.find((x) => x.present)?.id ?? activeTherapists[0]?.id ?? '', waiveService: false, waiveTax: false, intent: newIdempotencyKey() });
    }

    async function saveBooking() {
        if (book === null) return;
        const guest = overview.guests.find((g) => g.reservation_id === book.guest);
        const done = await action.run('/front-office/spa/bookings', {
            body: { room_id: book.room, treatment_id: book.treatment, therapist_id: book.therapist, date: book.date, time: book.time, guest_name: guest?.guest_name ?? book.name.trim(), reservation_id: guest?.reservation_id ?? null, waive_service_charge: book.waiveService, waive_tax: book.waiveTax },
            idempotencyKey: book.intent,
            reload,
        });
        if (done !== null) setBook(null);
    }

    async function step(b: SpaBooking, what: 'no-show') {
        await action.run(`/front-office/spa/bookings/${b.id}/${what}`, { body: { lock_version: b.lock_version }, reload });
    }

    async function assignNow() {
        if (assign === null) return;
        const done = await action.run(`/front-office/spa/bookings/${assign.booking.id}/assign`, { body: { therapist_id: assign.therapist, lock_version: assign.booking.lock_version }, reload });
        if (done !== null) setAssign(null);
    }

    async function cancelNow() {
        if (cancel === null) return;
        const done = await action.run(`/front-office/spa/bookings/${cancel.booking.id}/cancel`, { body: { reason: cancel.reason.trim(), lock_version: cancel.booking.lock_version }, reload });
        if (done !== null) setCancel(null);
    }

    async function payNow() {
        if (pay === null) return;
        const done = await action.run(`/front-office/spa/bookings/${pay.booking.id}/pay`, { body: { method: pay.method, reference: pay.reference.trim() || null, lock_version: pay.booking.lock_version }, reload });
        if (done !== null) setPay(null);
    }

    const statusText = (s: string) => t(`fo.spa.status.${s}` as 'fo.spa.status.booked');

    const columns: DataGridColumn<SpaBooking>[] = [
        { id: 'when', label: t('fo.spa.col.when'), value: (b) => b.start, cell: (b) => <span className="tabular-nums">{b.start}–{b.end}</span> },
        { id: 'room', label: t('fo.spa.col.room'), value: (b) => b.room_name, filter: 'select', cell: (b) => b.room_name },
        { id: 'guest', label: t('fo.spa.col.guest'), value: (b) => b.guest_name, rowHeader: true, cell: (b) => <span>{b.guest_name}<span className="block text-xs text-muted-foreground">{b.guest_room !== null ? t('fo.spa.inRoom', { room: b.guest_room }) : t('fo.spa.walkIn')} · {b.number}</span></span> },
        { id: 'treatment', label: t('fo.spa.col.treatment'), value: (b) => b.treatment_name, cell: (b) => <span>{b.treatment_name}<span className="block text-xs text-muted-foreground">{t('fo.spa.minutes', { n: b.minutes })} · {money(b.total_minor > 0 ? b.total_minor : b.price_minor)}</span></span> },
        { id: 'therapist', label: t('fo.spa.col.therapist'), value: (b) => b.therapist_name, filter: 'select', cell: (b) => b.therapist_name },
        {
            id: 'status',
            label: t('fo.spa.col.status'),
            value: (b) => b.status,
            filter: 'select',
            filterLabel: statusText,
            cell: (b) => (
                <span>
                    <StatusBadge label={statusText(b.status)} tone={TONE[b.status] ?? 'neutral'} />
                    {b.status === 'in_progress' ? <span className="block text-xs tabular-nums text-muted-foreground">{clock(secondsLeft(b, now))}</span> : null}
                    {b.status === 'done' ? <span className="block text-xs text-muted-foreground">{b.charged_to_room ? t('fo.spa.chargedToRoom') : b.paid ? t('fo.spa.paidAtDesk') : t('fo.spa.unpaid')}</span> : null}
                    {b.reason !== null ? <span className="block text-xs text-muted-foreground">{b.reason}</span> : null}
                </span>
            ),
        },
        {
            id: 'actions',
            label: '',
            value: () => '',
            sortable: false,
            cell: (b) => {
                if (!overview.may.book) return null;
                const open = b.status === 'booked' || b.status === 'accepted' || b.status === 'declined';

                return (
                    <span className="flex flex-wrap justify-end gap-2">
                        {b.status === 'done' && !b.paid ? <Button onClick={() => { action.clear(); setPay({ booking: b, method: overview.methods[0] ?? 'cash', reference: '' }); }} size="sm" type="button">{t('fo.spa.takePayment')}</Button> : null}
                        {b.status === 'booked' || b.status === 'declined' ? <Button onClick={() => { action.clear(); setAssign({ booking: b, therapist: b.therapist_id }); }} size="sm" type="button" variant={b.status === 'declined' ? 'default' : 'outline'}>{t('fo.spa.changeTherapist')}</Button> : null}
                        {open && b.date <= overview.today ? <Button disabled={action.busy} onClick={() => void step(b, 'no-show')} size="sm" type="button" variant="outline">{t('fo.spa.noShow')}</Button> : null}
                        {open ? <Button onClick={() => { action.clear(); setCancel({ booking: b, reason: '' }); }} size="sm" type="button" variant="ghost">{t('fo.spa.cancel')}</Button> : null}
                    </span>
                );
            },
        },
    ];

    return (
        <FrontOfficeShell description={t('fo.spa.description')} title={t('fo.spa.title')} wide>
            {action.error !== null && book === null && assign === null && cancel === null && pay === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <div className="flex flex-wrap items-end gap-3 print:hidden">
                <FormField label={t('fo.spa.day')}><DatePicker onChange={(e) => router.get('/front-office/spa', { date: e.target.value }, { only: reload, preserveState: true, preserveScroll: true })} value={overview.date} /></FormField>
                {overview.may.manage ? <Link className="text-sm font-medium text-brand underline-offset-4 hover:underline" href="/front-office/spa/manage">{t('fo.spa.manageLink')}</Link> : null}
            </div>

            {overview.rooms.filter((r) => r.is_active).length === 0 ? <EmptyState title={t('fo.spa.noRooms')} /> : (
                <ul aria-label={t('fo.spa.rooms')} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-testid="spa-rooms">
                    {overview.rooms.filter((r) => r.is_active).map((r) => {
                        const next = r.bookings.find((b) => b.status !== 'in_progress' && b.status !== 'done');
                        const run = r.running;

                        return (
                            <li className="flex flex-col gap-2 border border-border bg-surface p-4" key={r.id}>
                                <div className="flex items-center justify-between gap-2">
                                    <h2 className="text-base font-semibold">{r.name}</h2>
                                    <StatusBadge label={run !== null ? t('fo.spa.inUse') : t('fo.spa.free')} tone={run !== null ? 'warning' : 'success'} />
                                </div>
                                {run !== null ? (
                                    <div className="grid gap-1">
                                        <p className="text-3xl font-semibold tabular-nums" data-testid="spa-left">{clock(secondsLeft(run, now))}</p>
                                        <Progress aria-label={t('fo.spa.timeLeft')} value={percentDone(run, now)} />
                                        <p className="text-sm">{run.treatment_name} · {run.guest_name}</p>
                                        <p className="text-xs text-muted-foreground">{run.therapist_name} · {run.start}–{run.end}</p>
                                    </div>
                                ) : next !== undefined ? (
                                    <p className="text-sm text-muted-foreground">{t('fo.spa.next', { time: next.start, guest: next.guest_name })}</p>
                                ) : <p className="text-sm text-muted-foreground">{t('fo.spa.nothingBooked')}</p>}
                                {overview.may.book ? <Button className="mt-auto" disabled={activeTreatments.length === 0 || activeTherapists.length === 0} onClick={() => open(r)} type="button" variant={run !== null ? 'outline' : 'default'}>{t('fo.spa.bookRoom')}</Button> : null}
                            </li>
                        );
                    })}
                </ul>
            )}
            {overview.may.book && (activeTreatments.length === 0 || activeTherapists.length === 0) ? <p className="text-sm text-muted-foreground">{t('fo.spa.setUpFirst')}</p> : null}

            <DataGrid caption={t('fo.spa.bookings')} columns={columns} empty={<EmptyState title={t('fo.spa.noBookings')} />} getRowId={(b) => b.id} id="fo.spa.bookings" rows={overview.bookings} testId="spa-bookings" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setBook(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={book !== null && (book.treatment === '' || book.therapist === '' || (book.guest === WALK_IN && book.name.trim() === ''))} loading={action.busy} onClick={() => void saveBooking()} type="button">{t('fo.spa.book')}</Button></>}
                onClose={() => setBook(null)}
                open={book !== null}
                title={t('fo.spa.bookTitle', { room: overview.rooms.find((r) => r.id === book?.room)?.name ?? '' })}
            >
                {book !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('time')} field="time" label={t('fo.spa.time')}><TimeInput onChange={(e) => setBook({ ...book, time: e.target.value })} value={book.time} /></FormField>
                        <FormField error={action.fieldError('date')} field="date" label={t('fo.spa.day')}><DatePicker min={overview.today} onChange={(e) => setBook({ ...book, date: e.target.value })} value={book.date} /></FormField>
                        <FormField error={action.fieldError('guest_name')} label={t('fo.spa.col.guest')}>
                            <Select onChange={(e) => setBook({ ...book, guest: e.target.value })} value={book.guest}>
                                {overview.guests.map((g) => <option key={g.reservation_id} value={g.reservation_id}>{g.room} · {g.guest_name}</option>)}
                                <option value={WALK_IN}>{t('fo.spa.walkInOption')}</option>
                            </Select>
                        </FormField>
                        {book.guest === WALK_IN ? <FormField error={action.fieldError('guest_name')} field="guest_name" label={t('fo.spa.guestName')}><Input maxLength={120} onChange={(e) => setBook({ ...book, name: e.target.value })} value={book.name} /></FormField> : <div />}
                        <FormField error={action.fieldError('treatment_id')} field="treatment_id" label={t('fo.spa.col.treatment')}><Select onChange={(e) => setBook({ ...book, treatment: e.target.value })} value={book.treatment}>{activeTreatments.map((x) => <option key={x.id} value={x.id}>{x.name} · {t('fo.spa.minutes', { n: x.minutes })} · {money(x.price_minor)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('therapist_id')} field="therapist_id" label={t('fo.spa.col.therapist')}><Select onChange={(e) => setBook({ ...book, therapist: e.target.value })} value={book.therapist}>{activeTherapists.map((x) => <option key={x.id} value={x.id}>{x.name}{x.present ? ` · ${t('fo.spa.present')}` : ''}</option>)}</Select></FormField>
                        {overview.may.manage ? (
                            <div className="grid gap-2 sm:col-span-2">
                                <label className="flex items-center gap-2 text-sm"><Checkbox checked={book.waiveService} onCheckedChange={(v) => setBook({ ...book, waiveService: v === true })} />{t('fo.spa.waiveService')}</label>
                                <label className="flex items-center gap-2 text-sm"><Checkbox checked={book.waiveTax} onCheckedChange={(v) => setBook({ ...book, waiveTax: v === true })} />{t('fo.spa.waiveTax')}</label>
                            </div>
                        ) : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAssign(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void assignNow()} type="button">{t('fo.spa.assign')}</Button></>}
                onClose={() => setAssign(null)}
                open={assign !== null}
                title={t('fo.spa.changeTherapist')}
            >
                {assign !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{assign.booking.treatment_name} · {assign.booking.guest_name} · {assign.booking.start}–{assign.booking.end}{assign.booking.reason !== null ? ` · ${assign.booking.reason}` : ''}</p>
                        <FormField error={action.fieldError('therapist_id')} field="therapist_id" label={t('fo.spa.col.therapist')}><Select onChange={(e) => setAssign({ ...assign, therapist: e.target.value })} value={assign.therapist}>{activeTherapists.map((x) => <option key={x.id} value={x.id}>{x.name}{x.present ? ` · ${t('fo.spa.present')}` : ''}</option>)}</Select></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setCancel(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={cancel?.reason.trim() === ''} loading={action.busy} onClick={() => void cancelNow()} type="button">{t('fo.spa.cancel')}</Button></>}
                onClose={() => setCancel(null)}
                open={cancel !== null}
                title={t('fo.spa.cancelTitle')}
            >
                {cancel !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{cancel.booking.treatment_name} · {cancel.booking.guest_name} · {cancel.booking.start}–{cancel.booking.end}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fo.spa.reason')}><Input maxLength={200} onChange={(e) => setCancel({ ...cancel, reason: e.target.value })} value={cancel.reason} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setPay(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void payNow()} type="button">{t('fo.spa.takePayment')}</Button></>}
                onClose={() => setPay(null)}
                open={pay !== null}
                title={t('fo.spa.payTitle')}
            >
                {pay !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{pay.booking.treatment_name} · {pay.booking.guest_name} · {money(pay.booking.price_minor)}</p>
                        <p className="text-xs text-muted-foreground">{t('fo.spa.payHint')}</p>
                        <FormField error={action.fieldError('method')} field="method" label={t('fo.spa.method')}><Select onChange={(e) => setPay({ ...pay, method: e.target.value })} value={pay.method}>{overview.methods.map((m) => <option key={m} value={m}>{t(`fo.spa.method.${m}` as 'fo.spa.method.cash')}</option>)}</Select></FormField>
                        {pay.method !== 'cash' ? <FormField error={action.fieldError('reference')} field="reference" label={t('fo.spa.reference')}><Input maxLength={60} onChange={(e) => setPay({ ...pay, reference: e.target.value })} value={pay.reference} /></FormField> : null}
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
