import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Money = { base: number; service_charge: number; tax: number; total: number };
type Card = { key: string; kind: 'now' | 'period'; business_date: string | null; period: { from: string; to: string } | null; as_of: string; href: string; values: Record<string, any> };
type Snapshot = { period: { preset: string; from: string; to: string }; business_date: string; as_of: string; cards: Card[]; alerts: { code: string; count: number; items: string[]; href: string }[] };

const REFRESH_MS = 60_000;

export default function DashboardPage({ currency, snapshot: s }: { currency: string; snapshot: Snapshot }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [shownAt, setShownAt] = useState(() => new Date().toISOString());

    // The numbers refresh by themselves, at least once a minute, without reloading the page (FR-DSH-018).
    useEffect(() => {
        const timer = window.setInterval(() => router.reload({ only: ['snapshot'], onSuccess: () => setShownAt(new Date().toISOString()) }), REFRESH_MS);
        return () => window.clearInterval(timer);
    }, []);

    const percent = (bp: number) => `${(bp / 100).toFixed(1)}%`;
    const scope = (c: Card) => (c.kind === 'now' ? t('rpt.dash.now', { date: format.date(c.business_date ?? s.business_date) }) : t('rpt.period.shown', { from: format.date(c.period!.from), to: format.date(c.period!.to) }));

    return (
        <ReportingShell description={t('rpt.dash.description', { date: format.date(s.business_date, 'long') })} title={t('rpt.dash.title')} wide>
            <PeriodPicker from={s.period.from} path="/dashboard" preset={s.period.preset} to={s.period.to} />
            <p aria-live="polite" className="text-xs text-muted-foreground" data-testid="updated">{t('rpt.dash.updated', { time: format.instant(shownAt) })}</p>

            <div className="grid gap-4 md:grid-cols-2">
                {s.cards.map((c) => (
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
