import { router, usePage } from '@inertiajs/react';
import { ArrowLeftToLine, ArrowRightToLine, Ban, Clock, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Select } from '@/components/ui/select';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useAutoApply } from '@/shared/lib/use-auto-apply';
import { cn } from '@/shared/lib/utils';

type Night = {
    date: string; total: number; blocked: number; held: number; sold: number; available: number; allowance: number; oversold: boolean;
    restrictions: { stop_sell: boolean; closed_to_arrival: boolean; closed_to_departure: boolean; min_stay: number | null; has_price: boolean } | null;
};
type TypeRow = { id: string; code: string; name: string; nights: Night[] };
type Calendar = { from: string; days: number; types: TypeRow[] };
type State = 'oversold' | 'soldOut' | 'low' | 'open';
type Tip = { code: string; night: Night; left: number; top: number | null; bottom: number | null };

const PRESET_DAYS = [7, 14, 30];

/** State is text and shape as well as color (NFR-27): the number, a word, a marker and the colour. */
function stateOf(n: Night): State {
    if (n.available < 0) return 'oversold';
    if (n.available === 0) return 'soldOut';

    return n.total > 0 && n.available / n.total < 0.5 ? 'low' : 'open';
}

const CELL: Record<State, string> = { oversold: 'bg-danger/15 text-danger', soldOut: 'bg-accent/15 text-accent', low: 'bg-warning/10 text-warning', open: 'bg-success/10 text-success' };
const SWATCH: Record<State, string> = { oversold: 'bg-danger/25', soldOut: 'bg-accent/25', low: 'bg-warning/20', open: 'bg-success/20' };
const utcDay = (iso: string) => new Date(`${iso.slice(0, 10)}T00:00:00Z`);

/** Rooms still sellable per room type and night, as one heat map: the colour says how many are left, the figure says how many, and a marker names a stop, a closed day or a missing price (FR-FO-002). */
export default function AvailabilityPage({ calendar, plans, selected_plan }: { calendar: Calendar; plans: { id: string; code: string; name: string }[]; selected_plan: string | null }) {
    const { locale, t } = useTranslation();
    const format = useFormatters();
    const today = ((usePage().props as { shell?: { businessDate?: string | null } | null }).shell?.businessDate ?? '').slice(0, 10);
    const [form, setForm] = useState({ from: calendar.from, days: String(calendar.days), plan: selected_plan ?? '' });
    const [tip, setTip] = useState<Tip | null>(null);

    function show() {
        if (form.from === '' || !/^\d+$/.test(form.days)) return;
        router.get('/front-office/availability', { from: form.from, days: form.days, plan: form.plan || undefined }, { preserveScroll: true, preserveState: true, replace: true });
    }
    useAutoApply([form.from, form.days, form.plan], show);

    const dates = (calendar.types[0]?.nights ?? []).map((n) => n.date);
    const month = new Intl.DateTimeFormat(locale, { month: 'short', timeZone: 'UTC' });
    const days = [...new Set([...PRESET_DAYS, calendar.days])].sort((a, b) => a - b);
    const occupancy = dates.map((_, i) => {
        let capacity = 0;
        let sold = 0;

        for (const type of calendar.types) {
            const n = type.nights[i];
            if (n === undefined) continue;
            const room = Math.max(0, n.total - n.blocked);
            capacity += room;
            sold += Math.min(n.sold, room);
        }

        return capacity === 0 ? 0 : Math.round((sold * 100) / capacity);
    });

    function showTip(e: React.SyntheticEvent<HTMLElement>, code: string, night: Night) {
        const r = e.currentTarget.getBoundingClientRect();
        const below = r.bottom + 190 < window.innerHeight;
        setTip({ code, night, left: Math.min(Math.max(8, r.left + r.width / 2 - 104), window.innerWidth - 224), top: below ? r.bottom + 6 : null, bottom: below ? null : window.innerHeight - r.top + 6 });
    }

    const marker = (n: Night) => {
        const r = n.restrictions;
        if (r === null) return null;
        const items: { key: string; icon: typeof Ban; label: string; tone: string }[] = [];
        if (r.stop_sell) items.push({ key: 'stop', icon: Ban, label: t('fo.availability.marker.stop'), tone: 'text-danger' });
        if (r.closed_to_arrival) items.push({ key: 'cta', icon: ArrowRightToLine, label: t('fo.availability.marker.cta'), tone: 'text-muted-foreground' });
        if (r.closed_to_departure) items.push({ key: 'ctd', icon: ArrowLeftToLine, label: t('fo.availability.marker.ctd'), tone: 'text-muted-foreground' });
        if (r.min_stay !== null) items.push({ key: 'min', icon: Clock, label: t('fo.availability.marker.min', { n: r.min_stay }), tone: 'text-muted-foreground' });
        if (!r.has_price) items.push({ key: 'price', icon: TriangleAlert, label: t('fo.availability.marker.noPrice'), tone: 'text-warning' });

        return items.length === 0 ? null : (
            <div className="mt-1 flex flex-col items-center gap-0.5">
                {items.map(({ icon: Icon, key, label, tone }) => <span className={cn('flex items-center gap-1 text-[10px] font-normal leading-tight', tone)} key={key}><Icon aria-hidden="true" className="size-2.5" strokeWidth={2} />{label}</span>)}
            </div>
        );
    };

    return (
        <FrontOfficeShell description={t('fo.availability.description')} title={t('fo.availability.title')} wide>
            <form className="flex flex-wrap items-end justify-between gap-4" onSubmit={(e) => { e.preventDefault(); show(); }}>
                <div className="flex flex-wrap items-end gap-3">
                    <FormField label={t('fo.availability.from')}><DatePicker onChange={(e) => setForm({ ...form, from: e.target.value })} value={form.from} /></FormField>
                    <div className="flex flex-col gap-1.5">
                        <span className="text-sm font-medium" id="avail-days">{t('fo.availability.days')}</span>
                        <div aria-labelledby="avail-days" className="flex" role="group">
                            {days.map((d) => (
                                <Button aria-pressed={String(d) === form.days} className="-ml-px first:ml-0" key={d} onClick={() => setForm({ ...form, days: String(d) })} type="button" variant={String(d) === form.days ? 'default' : 'outline'}>{d}</Button>
                            ))}
                        </div>
                    </div>
                    <FormField label={t('fo.availability.plan')}>
                        <Select onChange={(e) => setForm({ ...form, plan: e.target.value })} value={form.plan}>
                            <option value="">{t('fo.availability.noPlan')}</option>
                            {plans.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
                        </Select>
                    </FormField>
                </div>
                <ul aria-label={t('fo.availability.legend')} className="flex flex-wrap items-center gap-x-4 gap-y-1 pb-2 text-xs text-muted-foreground">
                    <li className="flex items-center gap-1.5"><i className={cn('inline-block size-3', SWATCH.open)} />{t('fo.availability.state.open')}</li>
                    <li className="flex items-center gap-1.5"><i className={cn('inline-block size-3', SWATCH.low)} />{t('fo.availability.state.low')}</li>
                    <li className="flex items-center gap-1.5"><i className={cn('inline-block size-3', SWATCH.soldOut)} />▲ {t('fo.availability.state.soldOut')}</li>
                    <li className="flex items-center gap-1.5"><i className={cn('inline-block size-3', SWATCH.oversold)} />▲▲ {t('fo.availability.state.oversold')}</li>
                </ul>
            </form>

            {calendar.types.length === 0 ? <EmptyState title={t('fo.availability.empty')} /> : (
                /* The region is the scroller (keyboard focusable), so a wide range of days scrolls inside it and the type column stays in view. */
                <div aria-label={t('fo.availability.title')} className="overflow-x-auto border border-border bg-surface" onScroll={() => setTip(null)} role="region" tabIndex={0}>
                    <table className="w-full min-w-max border-separate border-spacing-0 text-center">
                        <thead>
                            <tr>
                                <th className="sticky left-0 z-10 w-44 min-w-44 border-b border-border bg-surface px-3 pb-2 text-left align-bottom text-[11px] font-semibold uppercase tracking-wider text-muted-foreground" scope="col">{t('fo.availability.type')}</th>
                                {dates.map((date) => {
                                    const isToday = date === today;
                                    const weekend = [0, 6].includes(utcDay(date).getUTCDay());

                                    return (
                                        <th className={cn('min-w-[5rem] border-b border-t-[3px] border-b-border px-1 pb-2 pt-1.5 font-medium', isToday ? 'border-t-brand bg-brand/10' : weekend ? 'border-t-transparent bg-secondary' : 'border-t-transparent bg-surface')} key={date} scope="col">
                                            <span className={cn('block text-[11px] font-semibold uppercase tracking-wide', isToday ? 'text-accent' : weekend ? 'text-danger' : 'text-muted-foreground')}>{isToday ? t('fo.availability.today') : format.weekday(date)}</span>
                                            <span className="block text-lg font-bold leading-tight text-foreground">{Number(date.slice(8, 10))}</span>
                                            <span className="block text-[11px] text-muted-foreground">{month.format(utcDay(date))}</span>
                                        </th>
                                    );
                                })}
                            </tr>
                        </thead>
                        <tbody>
                            {calendar.types.map((type) => (
                                <tr key={type.id}>
                                    <th className="sticky left-0 z-10 border-b border-border bg-surface px-3 py-2 text-left font-normal" scope="row">
                                        <span className="block text-[15px] font-bold">{type.code}</span>
                                        <span className="block text-xs text-muted-foreground">{type.name}</span>
                                    </th>
                                    {type.nights.map((n) => {
                                        const state = stateOf(n);
                                        const sold = n.total > 0 ? Math.min(100, Math.max(0, Math.round((n.sold * 100) / n.total))) : 0;

                                        return (
                                            <td
                                                aria-label={t('fo.availability.cellLabel', { type: type.code, date: format.date(n.date), available: n.available, total: n.total, sold: n.sold, blocked: n.blocked, held: n.held, allowance: n.allowance })}
                                                className={cn('border-b border-r border-b-border border-r-surface px-1 pb-1.5 pt-2 align-top', CELL[state])}
                                                key={n.date}
                                                onMouseEnter={(e) => showTip(e, type.code, n)}
                                                onMouseLeave={() => setTip(null)}
                                            >
                                                <span className="block text-xl font-bold leading-none">{state === 'oversold' ? '▲▲ ' : state === 'soldOut' ? '▲ ' : ''}{n.available}</span>
                                                <span className="mt-0.5 block text-[10px] font-normal">{state === 'soldOut' ? t('fo.availability.state.soldOut') : state === 'oversold' ? t('fo.availability.state.oversoldShort') : t('fo.availability.of', { total: n.total })}</span>
                                                <span aria-hidden="true" className="mx-1.5 mt-1.5 flex h-[3px] bg-foreground/10"><span className="block bg-primary" style={{ width: `${sold}%` }} /></span>
                                                {marker(n)}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                            <tr>
                                <th className="sticky left-0 z-10 border-t-2 border-t-primary bg-secondary px-3 py-2 text-left font-normal" scope="row">
                                    <span className="block text-[13px] font-bold">{t('fo.availability.occupancy')}</span>
                                    <span className="block text-[11px] text-muted-foreground">{t('fo.availability.occupancyHint')}</span>
                                </th>
                                {occupancy.map((p, i) => (
                                    <td className="border-t-2 border-t-primary bg-secondary px-1 py-2" key={dates[i]}>
                                        <span className="block text-[15px] font-bold">{p}%</span>
                                        <span aria-hidden="true" className="mx-2 mt-1 flex h-1 bg-border"><span className="block bg-brand" style={{ width: `${p}%` }} /></span>
                                    </td>
                                ))}
                            </tr>
                        </tbody>
                    </table>
                </div>
            )}

            {tip !== null ? (
                <div aria-hidden="true" className="pointer-events-none fixed z-50 w-52 border border-input bg-surface text-xs shadow-[0_8px_24px_rgb(16_24_40/0.18)]" style={{ left: tip.left, top: tip.top ?? undefined, bottom: tip.bottom ?? undefined }}>
                    <p className="bg-primary px-3 py-2 font-semibold text-primary-foreground">{tip.code} · {format.weekday(tip.night.date)}, {format.date(tip.night.date, 'short')}</p>
                    <dl className="grid gap-1.5 px-3 py-2.5">
                        {([['total', tip.night.total], ['sold', tip.night.sold], ['blocked', tip.night.blocked], ['held', tip.night.held], ...(tip.night.allowance > 0 ? [['allowance', tip.night.allowance]] : []), ['available', tip.night.available]] as [string, number][]).map(([k, v]) => (
                            <div className="flex justify-between gap-3" key={k}><dt className="text-muted-foreground">{t(`fo.availability.tip.${k}` as 'fo.availability.tip.total')}</dt><dd className={cn('font-semibold', k === 'available' && 'text-foreground')}>{v}</dd></div>
                        ))}
                    </dl>
                </div>
            ) : null}
        </FrontOfficeShell>
    );
}
