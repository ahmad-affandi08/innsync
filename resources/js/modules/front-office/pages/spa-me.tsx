import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Metric } from '@/components/ui/metric';
import { Progress } from '@/components/ui/progress';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { clock, earnedSoFar, percentDone, secondsLeft, type SpaBooking, useRefresh, useServerClock } from '@/modules/front-office/lib/spa';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Me = {
    therapist: { id: string; name: string };
    date: string;
    now: string;
    currency: string;
    present: boolean;
    clock_in_at: string | null;
    clock_out_at: string | null;
    bookings: SpaBooking[];
    treatments_done: number;
    commission_today_minor: number;
};

const TONE: Record<string, StatusTone> = { booked: 'info', accepted: 'info', declined: 'neutral', in_progress: 'warning', done: 'success', cancelled: 'neutral', no_show: 'neutral' };

/** The phone of a therapist: clock in, the bookings given to them (accept or decline), start and stop a treatment with the time running, and what they have earned today as it grows. */
export default function SpaMePage({ me }: { me: Me }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['me'];
    const now = useServerClock(me.now);
    const money = (minor: number) => format.money(minor, me.currency);
    const [decline, setDecline] = useState<{ booking: SpaBooking; reason: string } | null>(null);

    // New bookings come in while the phone lies open on the table.
    useRefresh(() => router.reload({ only: reload }), 15_000);

    const running = me.bookings.find((b) => b.status === 'in_progress');
    const live = running === undefined ? 0 : earnedSoFar(running, now);

    async function send(path: string, b?: SpaBooking) {
        await action.run(path, { body: b === undefined ? {} : { lock_version: b.lock_version }, reload });
    }

    async function declineNow() {
        if (decline === null) return;
        const done = await action.run(`/front-office/spa/bookings/${decline.booking.id}/decline`, { body: { reason: decline.reason.trim() || null, lock_version: decline.booking.lock_version }, reload });
        if (done !== null) setDecline(null);
    }

    const status = (s: string) => t(`fo.spa.status.${s}` as 'fo.spa.status.booked');

    return (
        <FrontOfficeShell description={t('fo.spa.me.description', { name: me.therapist.name })} title={t('fo.spa.me.title')}>
            {action.error !== null && decline === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <div className="flex flex-wrap items-center justify-between gap-3 border border-border bg-surface p-4">
                <div>
                    <p className="text-sm text-muted-foreground">{format.date(me.date)}</p>
                    <p className="text-base font-semibold">{me.present ? t('fo.spa.me.in', { time: format.instant(me.clock_in_at ?? me.now) }) : me.clock_out_at !== null ? t('fo.spa.me.out', { time: format.instant(me.clock_out_at) }) : t('fo.spa.me.notIn')}</p>
                </div>
                {me.present
                    ? <Button disabled={action.busy} onClick={() => void send('/front-office/spa/me/clock-out')} type="button" variant="outline">{t('fo.spa.me.clockOut')}</Button>
                    : <Button disabled={action.busy || me.clock_out_at !== null} onClick={() => void send('/front-office/spa/me/clock-in')} type="button">{t('fo.spa.me.clockIn')}</Button>}
            </div>

            <div className="grid grid-cols-2 gap-3">
                <Metric detail={running !== undefined ? t('fo.spa.me.growing') : undefined} label={t('fo.spa.me.earned')} value={money(me.commission_today_minor + live)} />
                <Metric label={t('fo.spa.me.done')} value={me.treatments_done} />
            </div>

            {me.bookings.length === 0 ? <EmptyState title={t('fo.spa.me.none')} /> : (
                <ul aria-label={t('fo.spa.me.bookings')} className="grid gap-3" data-testid="spa-my-bookings">
                    {me.bookings.map((b) => (
                        <li className="grid gap-2 border border-border bg-surface p-4" key={b.id}>
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-lg font-semibold tabular-nums">{b.start}–{b.end}</p>
                                    <p className="text-sm">{b.treatment_name} · {t('fo.spa.minutes', { n: b.minutes })}</p>
                                </div>
                                <StatusBadge label={status(b.status)} tone={TONE[b.status] ?? 'neutral'} />
                            </div>
                            <p className="text-sm">{b.guest_name}<span className="text-muted-foreground"> · {b.room_name}{b.guest_room !== null ? ` · ${t('fo.spa.inRoom', { room: b.guest_room })}` : ''}</span></p>
                            {b.status === 'in_progress' ? (
                                <div className="grid gap-1">
                                    <p className="text-4xl font-semibold tabular-nums" data-testid="spa-left">{clock(secondsLeft(b, now))}</p>
                                    <Progress aria-label={t('fo.spa.timeLeft')} value={percentDone(b, now)} />
                                    <p className="text-xs text-muted-foreground">{t('fo.spa.me.thisOne', { amount: money(earnedSoFar(b, now)) })}</p>
                                </div>
                            ) : null}
                            {b.status === 'done' ? <p className="text-sm text-muted-foreground">{t('fo.spa.me.commission', { amount: money(b.commission_minor) })}</p> : null}
                            <div className="flex flex-wrap gap-2">
                                {b.status === 'booked' ? <Button disabled={action.busy || !me.present} onClick={() => void send(`/front-office/spa/bookings/${b.id}/accept`, b)} type="button">{t('fo.spa.me.accept')}</Button> : null}
                                {b.status === 'accepted' ? <Button disabled={action.busy || !me.present} onClick={() => void send(`/front-office/spa/bookings/${b.id}/start`, b)} type="button">{t('fo.spa.me.start')}</Button> : null}
                                {b.status === 'in_progress' ? <Button disabled={action.busy} onClick={() => void send(`/front-office/spa/bookings/${b.id}/finish`, b)} type="button">{t('fo.spa.me.finish')}</Button> : null}
                                {b.status === 'booked' || b.status === 'accepted' ? <Button disabled={action.busy} onClick={() => { action.clear(); setDecline({ booking: b, reason: '' }); }} type="button" variant="outline">{t('fo.spa.me.decline')}</Button> : null}
                            </div>
                            {(b.status === 'booked' || b.status === 'accepted') && !me.present ? <p className="text-xs text-muted-foreground">{t('fo.spa.me.clockInFirst')}</p> : null}
                        </li>
                    ))}
                </ul>
            )}

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setDecline(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void declineNow()} type="button">{t('fo.spa.me.decline')}</Button></>}
                onClose={() => setDecline(null)}
                open={decline !== null}
                title={t('fo.spa.me.declineTitle')}
            >
                {decline !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{decline.booking.treatment_name} · {decline.booking.guest_name} · {decline.booking.start}–{decline.booking.end}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fo.spa.reasonOptional')}><Input maxLength={200} onChange={(e) => setDecline({ ...decline, reason: e.target.value })} value={decline.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
