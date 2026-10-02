import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Card } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { apiRequest } from '@/shared/api/http';
import { useTranslation } from '@/shared/i18n/i18n';

type Today = {
    business_date: string;
    occupancy: { occupied: number; sellable: number; occupancy_bp: number; available: number; guests: number } | null;
    movements: { arrivals_expected: number; arrivals_checked_in: number; departures_expected: number; departures_done: number } | null;
    alerts: number;
};

/** Today's few numbers at the top of the home page. A person who may not see the dashboard sees nothing here, not an error. */
export function TodaySummary() {
    const { t } = useTranslation();
    const [today, setToday] = useState<Today | null>(null);

    useEffect(() => {
        let alive = true;

        apiRequest<Today>('/dashboard/today').then((data) => alive && setToday(data), () => undefined);

        return () => { alive = false; };
    }, []);

    if (today === null || (today.occupancy === null && today.movements === null)) return null;

    const o = today.occupancy;
    const m = today.movements;

    return (
        <section aria-labelledby="today-h" className="flex flex-col gap-3" data-testid="today-summary">
            <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground" id="today-h">{t('home.today')}</h2>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {o !== null ? (
                    <Card className="flex flex-col gap-2 border-t-2 border-t-brand p-5">
                        <p className="text-sm text-muted-foreground">{t('home.today.occupancy')}</p>
                        <p className="text-3xl font-semibold tabular-nums tracking-tight">{(o.occupancy_bp / 100).toFixed(1)}%</p>
                        <Progress aria-label={t('home.today.occupancy')} value={Math.min(100, o.occupancy_bp / 100)} />
                        <p className="text-xs text-muted-foreground">{t('home.today.rooms', { occupied: o.occupied, sellable: o.sellable })} · {t('home.today.available', { count: o.available })}</p>
                    </Card>
                ) : null}
                {m !== null ? (
                    <>
                        <Card className="flex flex-col gap-2 border-t-2 border-t-brand p-5">
                            <p className="text-sm text-muted-foreground">{t('home.today.arrivals')}</p>
                            <p className="text-3xl font-semibold tabular-nums tracking-tight">{m.arrivals_expected + m.arrivals_checked_in}</p>
                            <p className="text-xs text-muted-foreground">{t('home.today.arrivalsOf', { done: m.arrivals_checked_in, total: m.arrivals_expected + m.arrivals_checked_in })}</p>
                        </Card>
                        <Card className="flex flex-col gap-2 border-t-2 border-t-brand p-5">
                            <p className="text-sm text-muted-foreground">{t('home.today.departures')}</p>
                            <p className="text-3xl font-semibold tabular-nums tracking-tight">{m.departures_expected + m.departures_done}</p>
                            <p className="text-xs text-muted-foreground">{t('home.today.departuresOf', { done: m.departures_done, total: m.departures_expected + m.departures_done })}</p>
                        </Card>
                    </>
                ) : null}
                <Card className="flex flex-col gap-2 border-t-2 border-t-brand p-5">
                    <p className="text-sm text-muted-foreground">{o !== null ? t('home.today.inHouse') : t('home.today.alerts')}</p>
                    <p className="text-3xl font-semibold tabular-nums tracking-tight">{o !== null ? o.guests : today.alerts}</p>
                    <p className="text-xs text-muted-foreground">
                        {today.alerts > 0 ? <Link className="font-medium text-accent hover:underline" href="/dashboard">{t('home.today.alerts')}: {today.alerts}</Link> : <Link className="hover:underline" href="/dashboard">{t('home.today.alertsOpen')}</Link>}
                    </p>
                </Card>
            </div>
        </section>
    );
}
