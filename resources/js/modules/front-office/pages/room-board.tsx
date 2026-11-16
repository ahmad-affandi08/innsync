import { Link } from '@inertiajs/react';
import { ArrowRightToLine, BedDouble, Clock, LogOut, Search, User } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { statusTone } from '@/modules/housekeeping/pages/board';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';

type Room = { room_id: string; number: string; floor: string | null; type: string; room_type_id: string; housekeeping: string; blocked: boolean; sellability: 'sellable' | 'out_of_order' | 'out_of_service'; service_flags: string[]; stay_id: string | null; expected_departure: string | null; open_requests: number; guest_name: string | null; charges_minor: number | null; balance_minor: number | null };
type Arrival = { reservation_id: string; number: string; guest_name: string; room_type_id: string; type: string; arrival: string; departure: string };
type Board = { business_date: string; currency: string; rooms: Room[]; arrivals: Arrival[]; counts: { occupied: number; vacant_ready: number; vacant_not_ready: number; blocked: number } };
type State = 'occupied' | 'ready' | 'not_ready' | 'blocked';
type Filter = 'all' | State | 'late';

const FILTERS: Filter[] = ['all', 'occupied', 'ready', 'not_ready', 'blocked', 'late'];

const stateOf = (r: Room): State => (r.stay_id !== null ? 'occupied' : r.blocked || r.sellability !== 'sellable' ? 'blocked' : r.housekeeping === 'ready' ? 'ready' : 'not_ready');

/** Whole days between two calendar dates (`YYYY-MM-DD`), counted in UTC so a time zone or a clock change cannot move it. */
function daysBetween(from: string, to: string): number {
    const parts = (v: string) => v.slice(0, 10).split('-').map(Number) as [number, number, number];
    const [fy, fm, fd] = parts(from);
    const [ty, tm, td] = parts(to);

    return Math.round((Date.UTC(ty, tm - 1, td) - Date.UTC(fy, fm - 1, fd)) / 86_400_000);
}

const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? '').join('');

const EDGE: Record<State, string> = { occupied: 'border-l-primary', ready: 'border-l-success', not_ready: 'border-l-warning', blocked: 'border-l-muted-foreground' };
const TONE: Record<State, 'info' | 'success' | 'warning' | 'neutral'> = { occupied: 'info', ready: 'success', not_ready: 'warning', blocked: 'neutral' };
const MAP: Record<State, string> = { occupied: 'bg-brand', ready: 'bg-[#34d399]/70', not_ready: 'bg-[#fbbf24]', blocked: 'bg-white/30' };

/** The ring of how full the house is: the share of rooms with a guest in them. */
function Ring({ percent, label }: { percent: number; label: string }) {
    const c = 2 * Math.PI * 52;

    return (
        <svg aria-hidden="true" className="size-[9.5rem] shrink-0" viewBox="0 0 130 130">
            <circle cx="65" cy="65" fill="none" r="52" className="stroke-white/15" strokeWidth="14" />
            <circle cx="65" cy="65" fill="none" r="52" className="stroke-brand" strokeDasharray={`${(c * percent) / 100} ${c}`} strokeWidth="14" transform="rotate(-90 65 65)" />
            <text className="fill-white text-[28px] font-bold" textAnchor="middle" x="65" y="64">{percent}%</text>
            <text className="fill-white/70 text-[11px]" textAnchor="middle" x="65" y="82">{label}</text>
        </svg>
    );
}

/** Front desk room board (FR-FO-001): the house at a glance, then every room by floor with its guest, its bill and its housekeeping state. Opening a room goes to its stay; a vacant ready room offers check-in from the arrivals below. */
export default function RoomBoardPage({ board }: { board: Board }) {
    const currency = board.currency;
    const { t } = useTranslation();
    const format = useFormatters();
    const c = board.counts;
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');
    const today = board.business_date.slice(0, 10);

    const lateDays = (r: Room) => (r.stay_id !== null && r.expected_departure !== null ? Math.max(0, daysBetween(r.expected_departure, today)) : 0);
    const lateCount = board.rooms.filter((r) => lateDays(r) > 0).length;
    const total = board.rooms.length;
    const percent = total === 0 ? 0 : Math.round((c.occupied * 100) / total);
    const counts: Record<Filter, number> = { all: total, occupied: c.occupied, ready: c.vacant_ready, not_ready: c.vacant_not_ready, blocked: c.blocked, late: lateCount };

    const shown = useMemo(() => {
        const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);

        return board.rooms.filter((r) => {
            const state = stateOf(r);
            const matchesFilter = filter === 'all' || (filter === 'late' ? lateDays(r) > 0 : state === filter);
            const hay = `${r.number} ${r.type} ${r.guest_name ?? ''}`.toLowerCase();

            return matchesFilter && words.every((w) => hay.includes(w));
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [board.rooms, filter, query, today]);

    const floors = useMemo(() => {
        const map = new Map<string, Room[]>();

        for (const r of shown) {
            const key = r.floor ?? '';
            map.set(key, [...(map.get(key) ?? []), r]);
        }

        return [...map.entries()].sort(([a], [b]) => (a === '' ? 1 : b === '' ? -1 : a.localeCompare(b, undefined, { numeric: true })));
    }, [shown]);

    const floorId = (key: string) => `floor-${key === '' ? 'none' : key}`;
    const floorName = (key: string) => (key === '' ? t('fo.board.noFloor') : t('fo.board.floor', { floor: key }));

    const card = (r: Room) => {
        const state = stateOf(r);
        const late = lateDays(r);
        const paid = r.charges_minor !== null && r.charges_minor > 0 && r.balance_minor !== null ? Math.min(100, Math.max(0, Math.round(((r.charges_minor - r.balance_minor) * 100) / r.charges_minor))) : null;
        const owes = r.balance_minor !== null && r.balance_minor > 0;

        return (
            <li className={cn('flex flex-col gap-3 border border-l-4 border-border bg-surface p-4 text-sm', late > 0 ? 'border-l-warning' : EDGE[state])} data-testid={`room-${r.number}`} key={r.room_id}>
                <div className="flex items-center justify-between gap-2">
                    <div className="flex items-baseline gap-2">
                        {r.stay_id !== null
                            ? <Link aria-label={t('fo.board.openStay', { room: r.number })} className="text-2xl font-semibold tracking-tight underline-offset-2 hover:underline" href={`/front-office/stays/${r.stay_id}`}>{r.number}</Link>
                            : <span className="text-2xl font-semibold tracking-tight">{r.number}</span>}
                        <span className="bg-secondary px-1.5 text-xs font-semibold text-muted-foreground">{r.type}</span>
                    </div>
                    <StatusBadge label={t(`fo.board.state.${state}` as 'fo.board.state.occupied')} tone={TONE[state]} />
                </div>

                {r.stay_id !== null ? (
                    <>
                        <div className="flex items-center gap-3">
                            <Avatar className="size-9"><AvatarFallback><User aria-hidden="true" className="size-4" strokeWidth={1.75} /></AvatarFallback></Avatar>
                            <div className="min-w-0">
                                <p className="truncate font-medium capitalize" data-testid={`guest-${r.number}`}>{r.guest_name ?? '—'}</p>
                                {r.expected_departure !== null ? <p className="text-xs text-muted-foreground">{t('fo.board.occupied', { date: format.date(r.expected_departure) })}</p> : null}
                            </div>
                        </div>
                        {late > 0 ? (
                            <div className="flex items-center justify-between gap-2 border border-warning/40 bg-warning/10 px-3 py-2">
                                <span className="flex items-center gap-1.5 text-xs font-semibold text-warning"><Clock aria-hidden="true" className="size-4" strokeWidth={1.75} />{t('fo.board.lateBy', { n: late })}</span>
                                <Button asChild size="sm"><Link href={`/front-office/stays/${r.stay_id}`}><LogOut aria-hidden="true" className="size-3.5" />{t('fo.board.checkOut')}</Link></Button>
                            </div>
                        ) : null}
                        {r.charges_minor !== null && r.balance_minor !== null && paid !== null ? (
                            <Link className="flex flex-col gap-1.5" data-testid={`bill-${r.number}`} href={`/front-office/stays/${r.stay_id}`}>
                                <span className="flex items-center justify-between text-xs text-muted-foreground"><span>{t('fo.board.payment')}</span><span className="font-semibold text-foreground">{paid}%</span></span>
                                <span aria-hidden="true" className="flex h-1.5 bg-secondary"><span className="block bg-success" style={{ width: `${paid}%` }} /></span>
                                <span className="flex items-center justify-between text-xs">
                                    <span className="text-muted-foreground">{t('fo.board.billedOf', { total: format.money(r.charges_minor, currency) })}</span>
                                    <span className={cn('font-semibold', owes ? 'text-accent' : 'text-success')}>{owes ? t('fo.board.owes', { balance: format.money(r.balance_minor, currency) }) : t('fo.board.settled')}</span>
                                </span>
                            </Link>
                        ) : null}
                    </>
                ) : (
                    <p className="text-xs text-muted-foreground">{state === 'blocked' ? t('fo.board.blocked') : state === 'ready' ? t('fo.board.readyNote') : t('fo.board.vacant')}</p>
                )}

                {state !== 'ready' || r.sellability !== 'sellable' || r.service_flags.length > 0 || r.open_requests > 0 ? (
                <div className="flex flex-wrap items-center gap-1.5">
                    {state !== 'ready' ? <StatusBadge label={t(`hk.status.${r.housekeeping}` as 'hk.status.dirty')} tone={statusTone[r.housekeeping] ?? 'neutral'} /> : null}
                    {r.sellability !== 'sellable' ? <StatusBadge label={t(`fo.board.sell.${r.sellability}` as 'fo.board.sell.out_of_order')} tone="danger" /> : null}
                    {r.service_flags.length > 0 ? <span className="flex flex-wrap gap-1" data-testid={`flags-${r.number}`}>{r.service_flags.map((k) => <StatusBadge key={k} label={t(`hk.flag.kind.${k}` as 'hk.flag.kind.dnd')} tone="pending" />)}</span> : null}
                    {r.open_requests > 0 ? <Link className="text-xs font-medium underline-offset-2 hover:underline" data-testid={`requests-${r.number}`} href={`/front-office/requests?status=active&room=${r.room_id}`}>{t('fo.board.requests', { n: r.open_requests })}</Link> : null}
                </div>
                ) : null}
            </li>
        );
    };

    return (
        <FrontOfficeShell description={t('fo.board.description')} title={t('fo.board.title')} wide>
            <p className="sr-only" data-testid="counts">{t('fo.board.counts', { occupied: c.occupied, ready: c.vacant_ready, notReady: c.vacant_not_ready, blocked: c.blocked })}</p>

            <section aria-label={t('fo.board.hero.title')} className="grid items-center gap-6 border-b-[3px] border-brand bg-primary p-5 text-primary-foreground lg:grid-cols-[auto_1fr_1fr_1fr_auto] lg:p-6">
                <Ring label={t('fo.board.hero.occupancy')} percent={percent} />
                {[
                    { icon: BedDouble, label: t('fo.board.hero.inHouse'), value: c.occupied, hint: t('fo.board.hero.ofRooms', { n: total }), warn: false },
                    { icon: ArrowRightToLine, label: t('fo.board.hero.waiting'), value: board.arrivals.length, hint: board.arrivals.length === 0 ? t('fo.board.hero.noneWaiting') : t('fo.board.arrivals'), warn: false },
                    { icon: Clock, label: t('fo.board.hero.late'), value: lateCount, hint: lateCount === 0 ? t('fo.board.hero.lateNone') : t('fo.board.hero.lateHint'), warn: lateCount > 0 },
                ].map(({ hint, icon: Icon, label, value, warn }) => (
                    <div className="flex flex-col gap-1 lg:border-l lg:border-white/15 lg:pl-6" key={label}>
                        <span className="flex items-center gap-2 text-sm text-white/75"><span className={cn('grid size-8 place-items-center', warn ? 'bg-[#fbbf24]/20 text-[#fbbf24]' : 'bg-white/10')}><Icon aria-hidden="true" className="size-4" strokeWidth={1.75} /></span>{label}</span>
                        <span className={cn('text-4xl font-bold leading-tight', warn && 'text-[#fbbf24]')}>{value}</span>
                        <span className="text-xs text-white/60">{hint}</span>
                    </div>
                ))}
                <div className="lg:border-l lg:border-white/15 lg:pl-6">
                    <p className="mb-2 text-sm text-white/75">{t('fo.board.hero.map', { n: total })}</p>
                    <ul aria-hidden="true" className="flex max-w-[18rem] flex-wrap gap-1">
                        {board.rooms.map((r) => <li className={cn('relative size-7', MAP[stateOf(r)])} key={r.room_id}>{lateDays(r) > 0 ? <span className="absolute right-0.5 top-0.5 size-1.5 rounded-full bg-white" /> : null}</li>)}
                    </ul>
                    <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-white/60">
                        <span className="flex items-center gap-1.5"><i className="inline-block size-2 bg-brand" />{t('fo.board.state.occupied')}</span>
                        <span className="flex items-center gap-1.5"><i className="inline-block size-2 bg-[#34d399]/70" />{t('fo.board.state.ready')}</span>
                        <span className="flex items-center gap-1.5"><i className="inline-block size-1.5 rounded-full bg-white" />{t('fo.board.hero.late')}</span>
                    </p>
                </div>
            </section>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap gap-2" role="group" aria-label={t('fo.board.filters')}>
                    {FILTERS.map((f) => (
                        <Button aria-pressed={filter === f} key={f} onClick={() => setFilter(f)} size="sm" type="button" variant={filter === f ? 'default' : 'outline'}>
                            {f === 'all' ? t('fo.board.filter.all') : f === 'late' ? t('fo.board.hero.late') : t(`fo.board.state.${f}` as 'fo.board.state.occupied')}
                            <span className={cn('px-1.5 text-[11px] font-semibold', filter === f ? 'bg-white/20' : 'bg-secondary')}>{counts[f]}</span>
                        </Button>
                    ))}
                </div>
                <div className="relative w-full sm:w-72">
                    <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input aria-label={t('fo.board.search')} className="pl-9" onChange={(e) => setQuery(e.target.value)} placeholder={t('fo.board.search')} type="search" value={query} />
                </div>
            </div>

            <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                <nav aria-label={t('fo.board.floors')} className="flex shrink-0 flex-col border border-border bg-surface lg:sticky lg:top-16 lg:w-44">
                    <p className="border-b border-border px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{t('fo.board.floors')}</p>
                    {floors.map(([key, rooms]) => (
                        <button className="flex items-center justify-between gap-2 border-l-[3px] border-transparent px-4 py-2 text-left text-sm hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" key={key} onClick={() => document.getElementById(floorId(key))?.scrollIntoView({ behavior: 'smooth', block: 'start' })} type="button">
                            <span>{floorName(key)}</span>
                            <span className="bg-secondary px-1.5 text-xs font-semibold text-muted-foreground">{rooms.filter((r) => r.stay_id !== null).length}/{rooms.length}</span>
                        </button>
                    ))}
                </nav>

                <div className="flex min-w-0 flex-1 flex-col gap-6">
                    {floors.length === 0 ? <p className="border border-dashed border-border bg-surface p-6 text-center text-sm text-muted-foreground">{t('fo.board.noMatch')}</p> : null}
                    {floors.map(([key, rooms]) => (
                        <section aria-labelledby={`${floorId(key)}-h`} className="scroll-mt-20" id={floorId(key)} key={key}>
                            <div className="mb-3 flex items-center gap-3">
                                <h2 className="text-sm font-semibold" id={`${floorId(key)}-h`}>{floorName(key)}</h2>
                                <span className="text-xs text-muted-foreground">{t('fo.board.floorSummary', { rooms: rooms.length, occupied: rooms.filter((r) => r.stay_id !== null).length })}</span>
                                <span aria-hidden="true" className="h-px flex-1 bg-border" />
                            </div>
                            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{rooms.map(card)}</ul>
                        </section>
                    ))}
                </div>
            </div>

            <section aria-labelledby="arr-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="arr-h">{t('fo.board.arrivals')}</h2>
                {board.arrivals.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.board.noArrivals')}</p> : (
                    <ul className="list-panel divide-y divide-border text-sm">
                        {board.arrivals.map((a) => {
                            const ready = board.rooms.filter((r) => r.room_type_id === a.room_type_id && r.stay_id === null && !r.blocked && r.housekeeping === 'ready');
                            return (
                                <li className="flex flex-wrap items-center justify-between gap-2 py-2" key={a.reservation_id}>
                                    <span>{a.number} · {a.guest_name} · {a.type} · {format.date(a.arrival)} – {format.date(a.departure)}</span>
                                    <span className="flex flex-wrap gap-2">
                                        {ready.length === 0 ? <span className="text-xs text-muted-foreground">{t('fo.board.notReadyNote')}</span> : ready.slice(0, 3).map((r) => (
                                            <Link className="border border-border px-2 py-1 text-xs hover:bg-surface-muted" href={`/front-office/reservations/${a.reservation_id}/check-in?room_id=${r.room_id}`} key={r.room_id}>{t('fo.board.checkInTo')}: {r.number}</Link>
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
