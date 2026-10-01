import { Link } from '@inertiajs/react';

import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { statusTone } from '@/modules/housekeeping/pages/board';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Room = { room_id: string; number: string; floor: string | null; type: string; room_type_id: string; housekeeping: string; blocked: boolean; stay_id: string | null; expected_departure: string | null };
type Arrival = { reservation_id: string; number: string; guest_name: string; room_type_id: string; type: string; arrival: string; departure: string };
type Board = { business_date: string; rooms: Room[]; arrivals: Arrival[]; counts: { occupied: number; vacant_ready: number; vacant_not_ready: number; blocked: number } };

/** Front desk room board (FR-FO-001): occupancy and housekeeping state side by side. Opening a room goes to its stay; a vacant ready room offers check-in. */
export default function RoomBoardPage({ board }: { board: Board }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const c = board.counts;

    return (
        <FrontOfficeShell description={t('fo.board.description')} title={t('fo.board.title')} wide>
            <p className="text-sm text-muted-foreground" data-testid="counts">{t('fo.board.counts', { occupied: c.occupied, ready: c.vacant_ready, notReady: c.vacant_not_ready, blocked: c.blocked })}</p>

            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                {board.rooms.map((r) => (
                    <li className="flex flex-col gap-1 border border-border p-3 text-sm" data-testid={`room-${r.number}`} key={r.room_id}>
                        <div className="flex items-baseline justify-between gap-2">
                            {r.stay_id !== null
                                ? <Link aria-label={t('fo.board.openStay', { room: r.number })} className="text-lg font-semibold underline-offset-2 hover:underline" href={`/front-office/stays/${r.stay_id}`}>{r.number}</Link>
                                : <span className="text-lg font-semibold">{r.number}</span>}
                            <span className="text-xs text-muted-foreground">{r.type}</span>
                        </div>
                        <span>{r.stay_id !== null && r.expected_departure !== null ? t('fo.board.occupied', { date: format.date(r.expected_departure) }) : r.blocked ? t('fo.board.blocked') : t('fo.board.vacant')}</span>
                        <StatusBadge label={t(`hk.status.${r.housekeeping}` as 'hk.status.dirty')} tone={statusTone[r.housekeeping] ?? 'neutral'} />
                    </li>
                ))}
            </ul>

            <section aria-labelledby="arr-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="arr-h">{t('fo.board.arrivals')}</h2>
                {board.arrivals.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.board.noArrivals')}</p> : (
                    <ul className="divide-y divide-border border-y border-border text-sm">
                        {board.arrivals.map((a) => {
                            const ready = board.rooms.filter((r) => r.room_type_id === a.room_type_id && r.stay_id === null && !r.blocked && r.housekeeping === 'ready');
                            return (
                                <li className="flex flex-wrap items-center justify-between gap-2 py-2" key={a.reservation_id}>
                                    <span>{a.number} · {a.guest_name} · {a.type} · {format.date(a.arrival)} – {format.date(a.departure)}</span>
                                    <span className="flex flex-wrap gap-2">
                                        {ready.length === 0 ? <span className="text-xs text-muted-foreground">{t('fo.board.notReadyNote')}</span> : ready.slice(0, 3).map((r) => (
                                            <Link className="rounded-md border border-border px-2 py-1 text-xs hover:bg-surface-muted" href={`/front-office/reservations/${a.reservation_id}/check-in?room_id=${r.room_id}`} key={r.room_id}>{t('fo.board.checkInTo')}: {r.number}</Link>
                                        ))}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>
        </FrontOfficeShell>
    );
}
