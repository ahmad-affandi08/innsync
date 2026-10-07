import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import type { MessageKey } from '@/locales/en/index';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Money = { base: number; service_charge: number; tax: number; total: number };
type Card = { key: string; kind: 'now' | 'period'; business_date: string | null; period: { from: string; to: string } | null; as_of: string; href: string; drill: string; limited: boolean; values: Record<string, any> };
type Snapshot = { period: { preset: string; from: string; to: string }; business_date: string; as_of: string; scope: { property: boolean; departments: string[]; outlets: string[] }; cards: Card[]; alerts: { code: string; count: number; items: string[]; href: string }[] };

type MenuRow = { code: string; name: string; outlet: string; quantity: number; total_minor: number };
type MenuPerformance = { top: MenuRow[]; bottom: MenuRow[] };
type RoomTypeRow = { code: string; name: string; rooms: number; nights: number; revenue_minor: number; adr_minor: number; occupancy_bp: number };
type OutletHours = { code: string; name: string; hours: number[]; total: number };

const REFRESH_MS = 60_000;
const dayOf = (offset: number) => new Date(Date.UTC(2026, 0, 5 + offset)).toISOString().slice(0, 10);
const busiest = (hours: number[]) => (Math.max(...hours) === 0 ? '—' : `${String(hours.indexOf(Math.max(...hours))).padStart(2, '0')}:00`);

type Preferences = { order: string[]; hidden: string[]; saved: boolean };

export default function DashboardPage({ currency, preferences, snapshot: s, tv }: { currency: string; preferences: Preferences; snapshot: Snapshot; tv: boolean }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const action = useServerAction();
    const [shownAt, setShownAt] = useState(() => new Date().toISOString());
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState<Preferences>(preferences);
    const labelOf = (key: string) => t(`rpt.card.${key}` as 'rpt.card.occupancy');
    // The cards this person may see, in the order they chose and without the ones they hid.
    const cards = [...s.cards].filter((c) => !preferences.hidden.includes(c.key)).sort((a, b) => preferences.order.indexOf(a.key) - preferences.order.indexOf(b.key));
    const editable = [...s.cards].sort((a, b) => draft.order.indexOf(a.key) - draft.order.indexOf(b.key));

    function move(key: string, by: -1 | 1) {
        const keys = editable.map((c) => c.key);
        const i = keys.indexOf(key);
        const j = i + by;
        if (j < 0 || j >= keys.length) return;
        [keys[i], keys[j]] = [keys[j]!, keys[i]!];
        setDraft({ ...draft, order: [...keys, ...draft.order.filter((k) => !keys.includes(k))] });
    }

    async function save() {
        const done = await action.run('/dashboard/preferences', { body: { order: draft.order, hidden: draft.hidden } });
        if (done !== null) { setEditing(false); router.reload({ only: ['preferences'] }); }
    }

    async function reset() {
        const done = await action.run('/dashboard/preferences', { method: 'DELETE' });
        if (done !== null) { setEditing(false); setDraft({ order: preferences.order, hidden: [], saved: false }); router.reload({ only: ['preferences'] }); }
    }

    // The numbers refresh by themselves, at least once a minute, without reloading the page (FR-DSH-018).
    useEffect(() => {
        const timer = window.setInterval(() => router.reload({ only: ['snapshot'], onSuccess: () => setShownAt(new Date().toISOString()) }), REFRESH_MS);
        return () => window.clearInterval(timer);
    }, []);

    const percent = (bp: number) => `${(bp / 100).toFixed(1)}%`;
    useEffect(() => setDraft(preferences), [preferences]);
    const scope = (c: Card) => (c.kind === 'now' ? t('rpt.dash.now', { date: format.date(c.business_date ?? s.business_date) }) : t('rpt.period.shown', { from: format.date(c.period!.from), to: format.date(c.period!.to) }));

    if (tv) {
        // The television view (FR-DSH-019): the same cards, large, with no menu, links or forms, refreshed on its own.
        return (
            <>
                <Head title={t('rpt.dash.title')} />
                <main className="min-h-screen bg-surface p-8 text-xl" data-testid="tv-mode">
                    <header className="mb-6 flex flex-wrap items-baseline justify-between gap-4">
                        <h1 className="text-4xl font-semibold">{t('rpt.dash.title')} · {format.date(s.business_date, 'long')}</h1>
                        <p className="text-base text-muted-foreground" data-testid="updated">{t('rpt.dash.updated', { time: format.instant(shownAt) })}</p>
                    </header>
                    <div className="grid gap-6 md:grid-cols-2">
                        {cards.map((c) => (
                            <article className="flex flex-col gap-3 border border-border p-6" data-testid={`card-${c.key}`} key={c.key}>
                                <h2 className="text-2xl font-semibold">{labelOf(c.key)}</h2>
                                {c.key === 'occupancy' && <p className="text-5xl font-semibold">{percent(c.values.occupancy_bp)} <span className="text-2xl font-normal">{c.values.occupied}/{c.values.sellable}</span></p>}
                                {c.key === 'movements' && <><p>{t('rpt.card.movements.arrivals', { waiting: c.values.arrivals_expected, done: c.values.arrivals_checked_in })}</p><p>{t('rpt.card.movements.departures', { waiting: c.values.departures_expected, done: c.values.departures_done })}</p></>}
                                {c.key === 'activity' && <p>{t('rpt.card.activity.line', { in: c.values.checked_in, out: c.values.checked_out, new: c.values.new_reservations })}</p>}
                                {c.key === 'staff' && <p className="text-5xl font-semibold">{c.values.present}<span className="text-2xl font-normal"> / {c.values.expected}</span></p>}
                                {c.key === 'revenue' && <p className="text-5xl font-semibold">{format.money((c.values.net as Money).total, currency)}</p>}
                                {c.key === 'spend' && <p className="text-5xl font-semibold">{format.money(c.values.owed_minor, currency)}</p>}
                                {c.key === 'stock' && <p className="text-5xl font-semibold">{((c.values.departments ?? []) as { count: number }[]).reduce((n, d) => n + d.count, 0)}</p>}
                                {c.key === 'maintenance' && <p className="text-5xl font-semibold">{c.values.open}<span className="text-2xl font-normal"> · {t('rpt.card.maintenance.overdue', { n: c.values.overdue })}</span></p>}
                                {c.key === 'products' && <p className="text-3xl font-semibold">{(((c.values.menu as MenuPerformance).top[0]?.name) ?? '—')}</p>}
                                {c.key === 'outlet_hours' && <ul className="text-2xl">{((c.values.outlets ?? []) as OutletHours[]).map((o) => <li key={o.code}>{o.name}: {busiest(o.hours)}</li>)}</ul>}
                                {c.key === 'arrivals' && <p className="text-5xl font-semibold">{c.values.total}</p>}
                            </article>
                        ))}
                    </div>
                    {s.alerts.length > 0 ? <ul className="mt-6 flex flex-wrap gap-4 text-2xl">{s.alerts.map((a) => <li className="border border-warning px-4 py-2" key={a.code}>{t(`rpt.alert.${a.code}` as 'rpt.alert.oversold')}: {a.count}</li>)}</ul> : null}
                </main>
            </>
        );
    }

    return (
        <ReportingShell description={t('rpt.dash.description', { date: format.date(s.business_date, 'long') })} title={t('rpt.dash.title')} wide>
            <PeriodPicker from={s.period.from} path="/dashboard" preset={s.period.preset} to={s.period.to} />
            {s.scope.property ? null : <Alert title={t('rpt.dash.limited')} tone="info">{t('rpt.dash.limitedDetail', { departments: s.scope.departments.map((d) => t(`inv.dept.${d}` as MessageKey)).join(', ') || '-', outlets: s.scope.outlets.length })}</Alert>}
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p aria-live="polite" className="text-xs text-muted-foreground" data-testid="updated">{t('rpt.dash.updated', { time: format.instant(shownAt) })}</p>
                <div className="flex gap-2">
                    <Button onClick={() => setEditing(!editing)} size="sm" type="button" variant="outline">{t('rpt.dash.customize')}</Button>
                    <Button asChild size="sm" variant="outline"><a href="/dashboard?tv=1" rel="noreferrer" target="_blank">{t('rpt.dash.tv')}</a></Button>
                </div>
            </div>

            {editing && (
                <section aria-labelledby="dash-edit-h" className="flex max-w-xl flex-col gap-2 border border-border p-4" data-testid="dash-customize">
                    <h2 className="text-lg font-semibold" id="dash-edit-h">{t('rpt.dash.customize')}</h2>
                    <p className="text-xs text-muted-foreground">{t('rpt.dash.customizeNote')}</p>
                    <ul className="flex flex-col gap-1">
                        {editable.map((c, i) => (
                            <li className="flex items-center gap-2 text-sm" key={c.key}>
                                <label className="flex flex-1 items-center gap-2"><input checked={!draft.hidden.includes(c.key)} onChange={(e) => setDraft({ ...draft, hidden: e.target.checked ? draft.hidden.filter((k) => k !== c.key) : [...draft.hidden, c.key] })} type="checkbox" />{labelOf(c.key)}</label>
                                <Button aria-label={`${t('rpt.dash.up')} ${labelOf(c.key)}`} disabled={i === 0} onClick={() => move(c.key, -1)} size="sm" type="button" variant="outline">↑</Button>
                                <Button aria-label={`${t('rpt.dash.down')} ${labelOf(c.key)}`} disabled={i === editable.length - 1} onClick={() => move(c.key, 1)} size="sm" type="button" variant="outline">↓</Button>
                            </li>
                        ))}
                    </ul>
                    {action.fieldError('hidden') ? <p className="text-sm text-danger">{action.fieldError('hidden')}</p> : null}
                    <div className="flex gap-2">
                        <Button loading={action.busy} onClick={() => void save()} size="sm" type="button">{t('rpt.dash.saveLayout')}</Button>
                        <Button disabled={action.busy} onClick={() => void reset()} size="sm" type="button" variant="outline">{t('rpt.dash.resetLayout')}</Button>
                    </div>
                </section>
            )}

            <div className="grid gap-4 md:grid-cols-2">
                {cards.map((c) => (
                    <article aria-labelledby={`card-${c.key}`} className="flex flex-col gap-3 border border-t-2 border-border border-t-brand bg-surface p-5" data-testid={`card-${c.key}`} key={c.key}>
                        <header className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground" id={`card-${c.key}`}>{t(`rpt.card.${c.key}` as 'rpt.card.occupancy')}</h2>
                            <span className="text-xs text-muted-foreground">{scope(c)}</span>
                        </header>

                        {c.key === 'occupancy' && (
                            <>
                                <p className="text-3xl font-semibold tabular-nums tracking-tight" data-testid="occupancy-line">{t('rpt.card.occupancy.line', { occupied: c.values.occupied, sellable: c.values.sellable, percent: percent(c.values.occupancy_bp) })}</p>
                                <p className="text-sm">{t('rpt.card.occupancy.more', { available: c.values.available, blocked: c.values.blocked, guests: c.values.guests })}</p>
                            </>
                        )}
                        {c.key === 'movements' && (
                            <>
                                <p className="text-sm">{t('rpt.card.movements.arrivals', { waiting: c.values.arrivals_expected, done: c.values.arrivals_checked_in })}</p>
                                <p className="text-sm">{t('rpt.card.movements.departures', { waiting: c.values.departures_expected, done: c.values.departures_done })}</p>
                            </>
                        )}
                        {c.key === 'activity' && (
                            <dl className="grid grid-cols-3 gap-4" data-testid="activity-line">
                                {([['in', c.values.checked_in], ['out', c.values.checked_out], ['new', c.values.new_reservations]] as const).map(([k, n]) => (
                                    <div key={k}>
                                        <dd className="text-3xl font-semibold tabular-nums tracking-tight">{String(n)}</dd>
                                        <dt className="text-xs text-muted-foreground">{t(`rpt.card.activity.${k}` as 'rpt.card.activity.in')}</dt>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {c.key === 'staff' && (
                            <>
                                <p className="text-3xl font-semibold tabular-nums tracking-tight" data-testid="staff-line">{t('rpt.card.staff.line', { present: c.values.present, expected: c.values.expected })}</p>
                                <ul className="flex flex-col gap-1 text-sm">{((c.values.groups ?? []) as { department: string; code: string; expected: number; present: number }[]).map((g) => <li className="flex justify-between" key={`${g.department}-${g.code}`}><span>{t(`hr.department.${g.department}` as 'hr.department.general')} · {g.code}</span><span className="tabular-nums">{g.present}/{g.expected}</span></li>)}</ul>
                                {c.values.off === null ? null : <p className="text-sm" data-testid="staff-off">{t('rpt.card.staff.off', { n: c.values.off ?? 0 })}</p>}
                                {((c.values.leave ?? []) as { name: string; type: string }[]).length > 0 ? <p className="text-sm" data-testid="staff-leave">{t('rpt.card.staff.leave')}: {((c.values.leave ?? []) as { name: string; type: string }[]).map((l) => `${l.name} (${l.type})`).join(', ')}</p> : null}
                                {((c.values.absent ?? []) as { name: string }[]).length > 0 ? <p className="text-sm text-danger" data-testid="staff-absent">{t('rpt.card.staff.absent')}: {((c.values.absent ?? []) as { name: string }[]).map((a) => a.name).join(', ')}</p> : null}
                            </>
                        )}
                        {c.key === 'spend' && (
                            <>
                                <p className="text-3xl font-semibold tabular-nums tracking-tight" data-testid="spend-paid">{format.money(c.values.paid_minor, currency)}</p>
                                <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm [&>dd]:text-right [&>dd]:tabular-nums">
                                    <dt>{t('rpt.card.spend.owed')}</dt><dd>{format.money(c.values.owed_minor, currency)}</dd>
                                    <dt>{t('rpt.card.spend.overdue')}</dt><dd>{format.money(c.values.overdue_minor, currency)}</dd>
                                    <dt>{t('rpt.card.spend.due7')}</dt><dd>{format.money(c.values.due7_minor, currency)}</dd>
                                    <dt>{t('rpt.card.spend.due30')}</dt><dd>{format.money(c.values.due30_minor, currency)}</dd>
                                </dl>
                                <ul className="flex flex-col gap-1 text-xs text-muted-foreground">{((c.values.upcoming ?? []) as { supplier: string; document: string; due: string; owed_minor: number }[]).map((u) => <li key={`${u.supplier}-${u.document}`}>{format.date(u.due, 'short')} · {u.supplier} · {u.document} · {format.money(u.owed_minor, currency)}</li>)}</ul>
                            </>
                        )}
                        {c.key === 'stock' && (
                            <>
                                {((c.values.departments ?? []) as { department: string; count: number; items: string[] }[]).length === 0 ? <p className="text-sm" data-testid="stock-none">{t('rpt.card.stock.none')}</p> : (
                                    <ul className="flex flex-col gap-2 text-sm" data-testid="stock-departments">{((c.values.departments ?? []) as { department: string; count: number; items: string[] }[]).map((d) => <li key={d.department}><span className="flex justify-between font-medium"><span className="capitalize">{d.department.replace('_', ' ')}</span><span className="tabular-nums">{d.count}</span></span><span className="block text-xs text-muted-foreground">{d.items.join(', ')}</span></li>)}</ul>
                                )}
                            </>
                        )}
                        {c.key === 'maintenance' && (
                            <>
                                <dl className="grid grid-cols-3 gap-4" data-testid="maintenance-line">
                                    {([['open', c.values.open], ['done', c.values.done_today], ['overdue', c.values.overdue]] as const).map(([k, n]) => (
                                        <div key={k}>
                                            <dd className="text-3xl font-semibold tabular-nums tracking-tight">{String(n)}</dd>
                                            <dt className="text-xs text-muted-foreground">{t(`rpt.card.maintenance.${k}` as 'rpt.card.maintenance.open')}</dt>
                                        </div>
                                    ))}
                                </dl>
                                <p className="text-sm">{(c.values.out_of_order as string[]).length === 0 ? t('rpt.card.maintenance.noOoo') : t('rpt.card.maintenance.ooo', { rooms: (c.values.out_of_order as string[]).join(', ') })}</p>
                            </>
                        )}
                        {c.key === 'revenue' && (
                            <>
                                <p className="text-3xl font-semibold tabular-nums tracking-tight" data-testid="revenue-net">{format.money((c.values.net as Money).total, currency)}</p>
                                <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm [&>dd]:text-right [&>dd]:tabular-nums">
                                    <dt>{t('rpt.card.revenue.room')}</dt><dd>{format.money((c.values.room as Money).total, currency)}</dd>
                                    <dt>{t('rpt.card.revenue.laundry')}</dt><dd>{format.money((c.values.laundry as Money).total, currency)}</dd>
                                    {((c.values.outlets ?? []) as { code: string; name: string; total: number }[]).map((o) => <Fragment key={o.code}><dt>{o.name}</dt><dd>{format.money(o.total, currency)}</dd></Fragment>)}
                                    <dt>{t('rpt.card.revenue.other')}</dt><dd>{format.money((c.values.other as Money).total, currency)}</dd>
                                </dl>
                                <ul className="flex flex-col gap-1 text-xs text-muted-foreground">
                                    {(['previous', 'week_earlier', 'month_earlier'] as const).map((k) => {
                                        const other = c.values[k] as { from: string; to: string; net_minor: number };
                                        const label = t(`rpt.card.revenue.${k === 'previous' ? 'previous' : k === 'week_earlier' ? 'week' : 'month'}` as 'rpt.card.revenue.previous', { from: format.date(other.from), to: format.date(other.to) });
                                        const delta = other.net_minor === 0 ? t('rpt.card.revenue.noChange') : `${(((c.values.net as Money).total - other.net_minor) * 100 / other.net_minor).toFixed(1)}%`;
                                        return <li key={k}>{label}: {format.money(other.net_minor, currency)} · {delta}</li>;
                                    })}
                                </ul>
                            </>
                        )}

                        {c.key === 'products' && (
                            <div className="flex flex-col gap-3" data-testid="products-card">
                                {([['top', (c.values.menu as MenuPerformance).top], ['bottom', (c.values.menu as MenuPerformance).bottom]] as const).map(([k, rows]) => (
                                    <section key={k}>
                                        <h3 className="text-sm font-semibold">{t(`rpt.card.products.${k}` as 'rpt.card.products.top')}</h3>
                                        {rows.length === 0 ? <p className="text-xs text-muted-foreground">{t('rpt.card.products.none')}</p> : (
                                            <ol className="flex flex-col gap-0.5 text-sm" data-testid={`menu-${k}`}>{rows.map((r) => <li className="flex justify-between gap-2" key={`${k}-${r.code}`}><span>{r.name}<span className="text-xs text-muted-foreground"> · {r.outlet}</span></span><span className="tabular-nums">{r.quantity} · {format.money(r.total_minor, currency)}</span></li>)}</ol>
                                        )}
                                    </section>
                                ))}
                                <section>
                                    <h3 className="text-sm font-semibold">{t('rpt.card.products.roomTypes')}</h3>
                                    <table className="w-full text-sm" data-testid="room-types">
                                        <thead><tr className="text-left text-xs text-muted-foreground"><th scope="col">{t('rpt.card.products.type')}</th><th className="text-right" scope="col">{t('rpt.card.products.nights')}</th><th className="text-right" scope="col">{t('rpt.card.products.occupancy')}</th><th className="text-right" scope="col">ADR</th></tr></thead>
                                        <tbody>{((c.values.room_types ?? []) as RoomTypeRow[]).map((r) => <tr key={r.code}><th className="text-left font-normal" scope="row">{r.name}</th><td className="text-right tabular-nums">{r.nights}</td><td className="text-right tabular-nums">{percent(r.occupancy_bp)}</td><td className="text-right tabular-nums">{format.money(r.adr_minor, currency)}</td></tr>)}</tbody>
                                    </table>
                                </section>
                            </div>
                        )}
                        {c.key === 'outlet_hours' && (
                            <div className="flex flex-col gap-3" data-testid="outlet-hours-card">
                                {((c.values.outlets ?? []) as OutletHours[]).length === 0 ? <p className="text-sm">{t('rpt.card.outlet_hours.none')}</p> : ((c.values.outlets ?? []) as OutletHours[]).map((o) => {
                                    const max = Math.max(1, ...o.hours);

                                    return (
                                        <section key={o.code}>
                                            <h3 className="flex justify-between text-sm font-semibold"><span>{o.name}</span><span className="text-xs font-normal text-muted-foreground">{t('rpt.card.outlet_hours.total', { n: o.total, hour: busiest(o.hours) })}</span></h3>
                                            <div aria-label={t('rpt.card.outlet_hours.chart', { name: o.name })} className="flex h-20 items-end gap-0.5" data-testid={`hours-${o.code}`} role="img">
                                                {o.hours.map((n, h) => <span className="flex-1 bg-accent" key={h} style={{ height: `${Math.round((n * 100) / max)}%`, minHeight: n > 0 ? 2 : 0 }} title={`${String(h).padStart(2, '0')}:00 · ${n}`} />)}
                                            </div>
                                            <div aria-hidden="true" className="flex justify-between text-[10px] text-muted-foreground"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
                                        </section>
                                    );
                                })}
                            </div>
                        )}
                        {c.key === 'arrivals' && (() => {
                            const cells = (c.values.cells ?? []) as number[][];
                            const max = Math.max(1, ...cells.flat());

                            return (
                                <div className="flex flex-col gap-1" data-testid="arrivals-card">
                                    <p className="text-sm">{t('rpt.card.arrivals.total', { n: c.values.total })}</p>
                                    <div aria-label={t('rpt.card.arrivals.chart')} className="grid gap-px" role="img" style={{ gridTemplateColumns: 'auto repeat(24, minmax(0, 1fr))' }}>
                                        {cells.map((row, d) => (
                                            <Fragment key={d}>
                                                <span className="pr-1 text-[10px] text-muted-foreground">{format.weekday(dayOf(d)).slice(0, 3)}</span>
                                                {row.map((n, h) => <span className="aspect-square bg-accent" key={h} style={{ opacity: n === 0 ? 0.06 : 0.2 + (0.8 * n) / max }} title={`${format.weekday(dayOf(d))} ${String(h).padStart(2, '0')}:00 · ${n}`} />)}
                                            </Fragment>
                                        ))}
                                    </div>
                                    <div aria-hidden="true" className="flex justify-between pl-6 text-[10px] text-muted-foreground"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div>
                                </div>
                            );
                        })()}

                        <details className="text-xs text-muted-foreground">
                            <summary className="cursor-pointer">{t('rpt.dash.definition')}</summary>
                            <p className="mt-1">{t(`rpt.card.${c.key}.def` as 'rpt.card.occupancy.def')}</p>
                            <p className="mt-1">{t('rpt.dash.asOf', { time: format.instant(c.as_of) })}</p>
                        </details>
                        <div className="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3 text-sm font-medium">
                            <Link className="inline-flex items-center gap-1 text-accent hover:underline" data-testid={`drill-${c.key}`} href={c.drill}>{t('rpt.dash.openRows')}<ArrowRight aria-hidden="true" className="size-4" /></Link>
                            <Link className="inline-flex items-center gap-1 text-accent hover:underline" href={c.href}>{t('rpt.dash.openSource')}</Link>
                        </div>
                    </article>
                ))}
            </div>

            <section aria-labelledby="alerts-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="alerts-h">{t('rpt.dash.alerts')}</h2>
                {s.alerts.length === 0 ? <p className="text-sm text-muted-foreground">{t('rpt.dash.noAlerts')}</p> : (
                    <ul className="flex flex-col gap-2">
                        {s.alerts.map((a) => (
                            <li key={a.code}>
                                <Alert actions={<Link className="text-sm font-medium underline-offset-2 hover:underline" href={a.href}>{t('rpt.dash.openSource')}</Link>} title={`${t(`rpt.alert.${a.code}` as 'rpt.alert.oversold')}: ${a.count}`} tone="warning">
                                    {a.items.join(' · ')}
                                </Alert>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </ReportingShell>
    );
}
