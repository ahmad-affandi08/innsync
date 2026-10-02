import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Money = { base: number; service_charge: number; tax: number; total: number };
type Audit = {
    business_date: string; next_business_date: string; completed_at: string;
    report: {
        currency: string; rooms_total: number; in_house: number; occupancy_bp: number; room_nights_charged: number; room_nights_skipped: number; arrivals: number; departures: number;
        revenue: { room: Money; other: Money; net: Money }; collected: Record<string, number>;
    };
    waivers: { gate: string; reason: string; count: number }[];
};

export default function NightAuditReportPage({ audit: a }: { audit: Audit }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const r = a.report;
    const rows: [string, Money][] = [[t('fo.audit.report.room'), r.revenue.room], [t('fo.audit.report.other'), r.revenue.other], [t('fo.audit.report.net'), r.revenue.net]];
    const methods = Object.entries(r.collected);

    return (
        <FrontOfficeShell description={t('fo.audit.report.description', { at: format.instant(a.completed_at.replace(' ', 'T') + 'Z'), next: format.date(a.next_business_date, 'long') })} title={t('fo.audit.report.title', { date: format.date(a.business_date, 'long') })}>
            <div><Button asChild size="sm" variant="outline"><Link href="/front-office/night-audit">{t('fo.audit.report.back')}</Link></Button></div>

            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
                <dt className="text-muted-foreground">{t('fo.audit.report.occupancy')}</dt><dd data-testid="occupancy">{(r.occupancy_bp / 100).toFixed(2)}% ({r.in_house}/{r.rooms_total})</dd>
                <dt className="text-muted-foreground">{t('fo.audit.report.charged')}</dt><dd>{r.room_nights_charged}</dd>
                <dt className="text-muted-foreground">{t('fo.audit.report.skipped')}</dt><dd>{r.room_nights_skipped}</dd>
                <dt className="text-muted-foreground">{t('fo.audit.report.arrivals')}</dt><dd>{r.arrivals}</dd>
                <dt className="text-muted-foreground">{t('fo.audit.report.departures')}</dt><dd>{r.departures}</dd>
            </dl>

            <section aria-labelledby="rev-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="rev-h">{t('fo.audit.report.revenue')}</h2>
                <p className="text-xs text-muted-foreground">{t('fo.audit.report.revenueNote')}</p>
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead scope="col"><span className="sr-only">{t('fo.audit.report.revenue')}</span></TableHead>
                            <TableHead className="text-right" scope="col">{t('rates.quote.base')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('rates.quote.service')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('rates.quote.tax')}</TableHead>
                            <TableHead className="text-right" scope="col">{t('rates.quote.total')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>{rows.map(([label, m]) => (
                        <TableRow key={label}>
                            <TableHead className="font-medium text-foreground" scope="row">{label}</TableHead>
                            <TableCell className="text-right tabular-nums">{format.money(m.base, r.currency)}</TableCell>
                            <TableCell className="text-right tabular-nums">{format.money(m.service_charge, r.currency)}</TableCell>
                            <TableCell className="text-right tabular-nums">{format.money(m.tax, r.currency)}</TableCell>
                            <TableCell className="text-right font-medium tabular-nums">{format.money(m.total, r.currency)}</TableCell>
                        </TableRow>
                    ))}</TableBody>
                </Table>
            </section>

            <section aria-labelledby="col-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="col-h">{t('fo.audit.report.collected')}</h2>
                {methods.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.audit.report.collectedNone')}</p> : (
                    <ul className="divide-y divide-border border-y border-border text-sm">{methods.map(([method, amount]) => (
                        <li className="flex justify-between gap-2 py-2" key={method}><span>{t(`fo.folio.method.${method}` as 'fo.folio.method.cash')}</span><span>{format.money(amount, r.currency)}</span></li>
                    ))}</ul>
                )}
            </section>

            {a.waivers.length > 0 && (
                <section aria-labelledby="waive-h" className="flex flex-col gap-2">
                    <h2 className="text-lg font-semibold" id="waive-h">{t('fo.audit.report.waivers')}</h2>
                    <ul className="list-disc pl-5 text-sm">{a.waivers.map((w) => <li key={w.gate}>{t('fo.audit.report.waiverRow', { gate: t(`fo.audit.gate.${w.gate}` as 'fo.audit.gate.pending_arrivals'), n: w.count, reason: w.reason })}</li>)}</ul>
                </section>
            )}
        </FrontOfficeShell>
    );
}
