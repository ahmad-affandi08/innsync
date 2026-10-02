import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Money = { base: number; service_charge: number; tax: number; total: number };
type Card = { key: string; kind: 'now' | 'period'; business_date: string | null; period: { from: string; to: string } | null; as_of: string; href: string; values: Record<string, any> };
type Snapshot = { period: { preset: string; from: string; to: string }; business_date: string; as_of: string; cards: Card[]; alerts: { code: string; count: number; items: string[]; href: string }[] };

const REFRESH_MS = 60_000;

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
                                {c.key === 'revenue' && <p className="text-5xl font-semibold">{format.money((c.values.net as Money).total, currency)}</p>}
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
                    <article aria-labelledby={`card-${c.key}`} className="flex flex-col gap-2 border border-border p-4" data-testid={`card-${c.key}`} key={c.key}>
                        <header className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="text-lg font-semibold" id={`card-${c.key}`}>{t(`rpt.card.${c.key}` as 'rpt.card.occupancy')}</h2>
                            <span className="text-xs text-muted-foreground">{scope(c)}</span>
                        </header>

                        {c.key === 'occupancy' && (
                            <>
                                <p className="text-2xl font-semibold" data-testid="occupancy-line">{t('rpt.card.occupancy.line', { occupied: c.values.occupied, sellable: c.values.sellable, percent: percent(c.values.occupancy_bp) })}</p>
                                <p className="text-sm">{t('rpt.card.occupancy.more', { available: c.values.available, blocked: c.values.blocked, guests: c.values.guests })}</p>
                            </>
                        )}
                        {c.key === 'movements' && (
                            <>
                                <p className="text-sm">{t('rpt.card.movements.arrivals', { waiting: c.values.arrivals_expected, done: c.values.arrivals_checked_in })}</p>
                                <p className="text-sm">{t('rpt.card.movements.departures', { waiting: c.values.departures_expected, done: c.values.departures_done })}</p>
                            </>
                        )}
                        {c.key === 'activity' && <p className="text-sm">{t('rpt.card.activity.line', { in: c.values.checked_in, out: c.values.checked_out, new: c.values.new_reservations })}</p>}
                        {c.key === 'revenue' && (
                            <>
                                <p className="text-2xl font-semibold" data-testid="revenue-net">{format.money((c.values.net as Money).total, currency)}</p>
                                <dl className="grid grid-cols-[1fr_auto] gap-x-4 text-sm">
                                    <dt>{t('rpt.card.revenue.room')}</dt><dd>{format.money((c.values.room as Money).total, currency)}</dd>
                                    <dt>{t('rpt.card.revenue.laundry')}</dt><dd>{format.money((c.values.laundry as Money).total, currency)}</dd>
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

                        <details className="text-xs text-muted-foreground">
                            <summary className="cursor-pointer">{t('rpt.dash.definition')}</summary>
                            <p className="mt-1">{t(`rpt.card.${c.key}.def` as 'rpt.card.occupancy.def')}</p>
                            <p className="mt-1">{t('rpt.dash.asOf', { time: format.instant(c.as_of) })}</p>
                        </details>
                        <Link className="text-sm font-medium underline-offset-2 hover:underline" href={c.href}>{t('rpt.dash.openSource')}</Link>
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
