import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Arrival = { reservation_id: string; number: string; guest_name: string; type: string; arrival: string; departure: string };
type Departure = { room: string; type: string; stay_id: string; expected_departure: string; open_requests: number };
type Desk = {
    business_date: string;
    counts: { occupied: number; vacant_ready: number; vacant_not_ready: number; blocked: number; rooms: number };
    arrivals: Arrival[];
    later_arrivals: number;
    departures: Departure[];
    open_requests: number;
    reminders_due: number;
    in_house: number;
};

/** The receptionist's day on one screen: who arrives, who leaves, how the rooms stand, what to remember. Everything here opens the screen where it is done. */
export default function TodayPage({ desk }: { desk: Desk }) {
    const { t } = useTranslation();
    const format = useFormatters();

    const stat = (label: string, value: number, hint?: string) => (
        <Card className="flex flex-col gap-1 border-t-2 border-t-brand p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="text-3xl font-semibold tabular-nums tracking-tight">{value}</p>
            {hint !== undefined ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
        </Card>
    );

    return (
        <FrontOfficeShell description={t('fo.today.description', { date: format.date(desk.business_date) })} title={t('fo.today.title')} wide>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="desk-stats">
                {stat(t('fo.today.arrivals'), desk.arrivals.length, desk.later_arrivals > 0 ? t('fo.today.later', { count: desk.later_arrivals }) : undefined)}
                {stat(t('fo.today.departures'), desk.departures.length)}
                {stat(t('fo.today.inHouse'), desk.in_house, t('fo.today.ofRooms', { count: desk.counts.rooms }))}
                {stat(t('fo.today.ready'), desk.counts.vacant_ready, t('fo.today.notReady', { count: desk.counts.vacant_not_ready, blocked: desk.counts.blocked }))}
            </div>

            {desk.reminders_due > 0 || desk.open_requests > 0 ? (
                <div className="flex flex-wrap gap-2" data-testid="desk-alerts">
                    {desk.reminders_due > 0 ? <Button asChild size="sm" variant="outline"><Link href="/front-office/reminders">{t('fo.today.reminders', { count: desk.reminders_due })}</Link></Button> : null}
                    {desk.open_requests > 0 ? <Button asChild size="sm" variant="outline"><Link href="/front-office/requests">{t('fo.today.requests', { count: desk.open_requests })}</Link></Button> : null}
                </div>
            ) : null}

            <div className="grid gap-6 lg:grid-cols-2">
                <section aria-labelledby="arr-h" className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold" id="arr-h">{t('fo.today.arrivalsTitle')}</h2>
                    {desk.arrivals.length === 0 ? <EmptyState title={t('fo.today.noArrivals')} /> : (
                        <ul className="divide-y divide-border border-y border-border" data-testid="desk-arrivals">
                            {desk.arrivals.map((a) => (
                                <li className="flex flex-wrap items-center justify-between gap-2 py-2.5" key={a.reservation_id}>
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">{a.guest_name}</p>
                                        <p className="text-xs text-muted-foreground">{a.number} · {a.type} · {format.date(a.arrival)} – {format.date(a.departure)}</p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {a.arrival < desk.business_date ? <StatusBadge label={t('fo.today.late')} tone="warning" /> : null}
                                        <Button asChild size="sm"><Link href={`/front-office/reservations/${a.reservation_id}/check-in`}>{t('fo.today.checkIn')}</Link></Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section aria-labelledby="dep-h" className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold" id="dep-h">{t('fo.today.departuresTitle')}</h2>
                    {desk.departures.length === 0 ? <EmptyState title={t('fo.today.noDepartures')} /> : (
                        <ul className="divide-y divide-border border-y border-border" data-testid="desk-departures">
                            {desk.departures.map((d) => (
                                <li className="flex flex-wrap items-center justify-between gap-2 py-2.5" key={d.stay_id}>
                                    <div className="min-w-0">
                                        <p className="font-medium">{t('fo.today.room', { room: d.room })} <span className="text-sm font-normal text-muted-foreground">{d.type}</span></p>
                                        <p className="text-xs text-muted-foreground">{d.expected_departure < desk.business_date ? t('fo.today.overdue', { date: format.date(d.expected_departure) }) : t('fo.today.leavesToday')}{d.open_requests > 0 ? ` · ${t('fo.today.requests', { count: d.open_requests })}` : ''}</p>
                                    </div>
                                    <Button asChild size="sm" variant="outline"><Link href={`/front-office/stays/${d.stay_id}`}>{t('fo.today.open')}</Link></Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <section aria-labelledby="go-h" className="flex flex-col gap-2">
                <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground" id="go-h">{t('fo.today.go')}</h2>
                <div className="flex flex-wrap gap-2">
                    <Button asChild size="sm" variant="outline"><Link href="/front-office/reservations">{t('fo.today.goReservations')}</Link></Button>
                    <Button asChild size="sm" variant="outline"><Link href="/front-office/room-board">{t('fo.today.goBoard')}</Link></Button>
                    <Button asChild size="sm" variant="outline"><Link href="/front-office/room-calendar">{t('fo.today.goCalendar')}</Link></Button>
                    <Button asChild size="sm" variant="outline"><Link href="/front-office/guests">{t('fo.today.goGuests')}</Link></Button>
                    <Button asChild size="sm" variant="outline"><Link href="/front-office/stays">{t('fo.today.goStays')}</Link></Button>
                </div>
            </section>
        </FrontOfficeShell>
    );
}
