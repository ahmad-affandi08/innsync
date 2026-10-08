import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import type { MessageKey } from '@/locales/en/index';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';

type Bar = { kind: 'reservation' | 'block'; id: string | null; number: string | null; label: string; status: string; start: string; end: string };
type Room = { id: string; number: string; type_id: string; floor: string | null; bars: Bar[] };
type Chart = {
    from: string;
    days: number;
    dates: string[];
    today: string;
    types: { id: string; code: string; name: string }[];
    rooms: Room[];
    unassigned: { type_id: string; bars: Bar[] }[];
};

const DAY = 86_400_000;
const LABEL_W = '7.5rem';
const STATUS_CLASS: Record<string, string> = {
    checked_in: 'bg-foreground text-background',
    confirmed: 'bg-accent text-accent-foreground',
    guaranteed: 'bg-accent text-accent-foreground',
    tentative: 'border border-dashed border-foreground/60 bg-surface-muted text-foreground',
    completed: 'border border-border bg-surface-muted text-muted-foreground',
    out_of_order: 'bg-danger/20 text-foreground',
    out_of_service: 'bg-foreground/15 text-foreground',
};

const dayIndex = (from: string, date: string) => Math.round((Date.parse(`${date}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / DAY);
const shift = (date: string, days: number) => new Date(Date.parse(`${date}T00:00:00Z`) + days * DAY).toISOString().slice(0, 10);

/** The room calendar: every room against the nights, who is where, which rooms are out of sale and the bookings that have no room yet. A column is a night. */
export default function TapeChartPage({ chart }: { chart: Chart }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const weekday = new Intl.DateTimeFormat(typeof document === 'undefined' ? 'id' : document.documentElement.lang || 'id', { weekday: 'short', timeZone: 'UTC' });
    const cols = `${LABEL_W} repeat(${chart.days}, minmax(2.75rem, 1fr))`;
    const go = (from: string | null, days = chart.days) => router.get('/front-office/room-calendar', { ...(from === null ? {} : { from }), days }, { preserveScroll: true });

    function row(key: string, label: React.ReactNode, bars: Bar[], sub?: string) {
        return (
            <div className="relative grid border-b border-border" key={key} style={{ gridTemplateColumns: cols }}>
                <div className="sticky left-0 z-10 flex min-w-0 flex-col justify-center border-r border-border bg-surface px-2 py-1" style={{ gridColumn: 1, gridRow: 1 }}>
                    <span className="truncate text-sm font-medium">{label}</span>
                    {sub ? <span className="truncate text-xs text-muted-foreground">{sub}</span> : null}
                </div>
                {chart.dates.map((d, i) => <div aria-hidden="true" className={cn('min-h-10 border-r border-border/60', d === chart.today && 'bg-accent/10')} key={d} style={{ gridColumn: i + 2, gridRow: 1 }} />)}
                {bars.map((b, i) => {
                    const from = Math.max(0, dayIndex(chart.from, b.start));
                    const to = Math.min(chart.days, dayIndex(chart.from, b.end));
                    if (to <= from) return null;
                    const text = b.kind === 'block' ? `${t(`fo.tape.${b.status}` as MessageKey)}: ${b.label}` : `${b.label} · ${b.number}`;
                    const cls = cn('z-[1] m-0.5 flex min-w-0 items-center overflow-hidden px-1.5 text-xs font-medium', STATUS_CLASS[b.status] ?? 'bg-surface-muted');
                    const style = { gridColumn: `${from + 2} / ${to + 2}`, gridRow: 1 } as const;

                    return b.id === null
                        ? <div className={cls} key={`${key}-${i}`} style={style} title={text}><span className="truncate">{text}</span></div>
                        : <Link className={cn(cls, 'hover:opacity-90')} href={`/front-office/reservations/${b.id}`} key={`${key}-${i}`} style={style} title={`${text} · ${format.date(b.start)} – ${format.date(b.end)}`}><span className="truncate">{text}</span></Link>;
                })}
            </div>
        );
    }

    return (
        <FrontOfficeShell description={t('fo.tape.description')} title={t('fo.tape.title')} wide>
            <div className="flex flex-wrap items-center gap-2">
                <Button aria-label={t('fo.tape.earlier')} onClick={() => go(shift(chart.from, -chart.days))} size="sm" type="button" variant="outline"><ChevronLeft aria-hidden="true" className="size-4" /></Button>
                <Button onClick={() => go(null)} size="sm" type="button" variant="outline">{t('fo.tape.today')}</Button>
                <Button aria-label={t('fo.tape.later')} onClick={() => go(shift(chart.from, chart.days))} size="sm" type="button" variant="outline"><ChevronRight aria-hidden="true" className="size-4" /></Button>
                <span className="px-2 text-sm text-muted-foreground">{format.date(chart.dates[0])} – {format.date(chart.dates[chart.dates.length - 1])}</span>
                <div className="ml-auto flex gap-1" role="group" aria-label={t('fo.tape.span')}>
                    {[7, 14, 30].map((n) => <Button key={n} onClick={() => go(chart.from, n)} size="sm" type="button" variant={chart.days === n ? 'default' : 'outline'}>{t('fo.tape.days', { count: n })}</Button>)}
                </div>
            </div>

            <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label={t('fo.tape.legend')}>
                {(['checked_in', 'confirmed', 'tentative', 'completed', 'out_of_order'] as const).map((s) => (
                    <li className="flex items-center gap-1.5" key={s}><span aria-hidden="true" className={cn('inline-block h-3 w-5', STATUS_CLASS[s])} />{t(`fo.tape.status.${s}` as MessageKey)}</li>
                ))}
            </ul>

            {chart.rooms.length === 0 ? <EmptyState title={t('fo.tape.empty')} /> : (
                <div className="overflow-x-auto border border-border" data-testid="tape-chart">
                    <div style={{ minWidth: `calc(${LABEL_W} + ${chart.days} * 2.75rem)` }}>
                        <div className="sticky top-0 z-20 grid border-b border-border bg-surface" style={{ gridTemplateColumns: cols }}>
                            <div className="sticky left-0 z-30 border-r border-border bg-surface px-2 py-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{t('fo.tape.room')}</div>
                            {chart.dates.map((d) => (
                                <div className={cn('border-r border-border/60 px-1 py-1 text-center text-xs', d === chart.today && 'bg-accent/10 font-semibold')} key={d}>
                                    <div className="text-muted-foreground">{weekday.format(new Date(`${d}T00:00:00Z`))}</div>
                                    <div className="tabular-nums">{d.slice(8)}</div>
                                </div>
                            ))}
                        </div>
                        {chart.types.map((type) => {
                            const rooms = chart.rooms.filter((r) => r.type_id === type.id);
                            const open = chart.unassigned.find((u) => u.type_id === type.id);
                            if (rooms.length === 0 && !open) return null;

                            return (
                                <div key={type.id}>
                                    <div className="border-b border-border bg-surface-muted px-2 py-1 text-xs font-semibold uppercase tracking-wide">{type.name} <span className="font-normal text-muted-foreground">{type.code}</span></div>
                                    {open ? row(`${type.id}-open`, t('fo.tape.noRoom'), open.bars) : null}
                                    {rooms.map((r) => row(r.id, r.number, r.bars, r.floor ?? undefined))}
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
            <p className="text-xs text-muted-foreground">{t('fo.tape.note')}</p>
        </FrontOfficeShell>
    );
}
