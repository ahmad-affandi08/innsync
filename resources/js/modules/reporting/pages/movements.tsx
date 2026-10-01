import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Arrival = { reservation: string; guest: string; status: string; adults: number; children: number; room_type: string; room: string | null; departure: string };
type Stay = { room: string; reservation: string; guest: string; adults: number; children: number; status: string; checked_in: string; expected_departure: string; checked_out: string | null; balance_minor: number };
type Report = {
    meta: Meta & { period: { from: string; to: string } }; date: string; expected: boolean; arrivals: Arrival[]; departures: Stay[]; in_house: Stay[];
    totals: { arrivals: number; departures: number; in_house: number; guests_in_house: number };
};

/** The front desk's list for one business date (FR-FO-043). */
export default function MovementsPage({ context, may_export, report: r }: { context: { currency: string }; may_export: boolean; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [date, setDate] = useState(r.date);
    const [purpose, setPurpose] = useState('');
    const query = new URLSearchParams({ date: r.date, purpose }).toString();
    const pax = (a: number, c: number) => t('rpt.mov.guests', { adults: a, children: c });
    const head = (keys: string[]) => <thead><tr className="text-xs text-muted-foreground">{keys.map((k, i) => <th className={i === 0 ? 'py-1 font-medium' : undefined} key={k} scope="col">{t(`rpt.mov.col.${k}` as 'rpt.mov.col.room')}</th>)}</tr></thead>;

    return (
        <ReportingShell description={t('rpt.mov.description')} title={t('rpt.mov.title')} wide>
            <form className="flex flex-wrap items-end gap-3 print:hidden" onSubmit={(e) => { e.preventDefault(); router.get('/reports/movements', { date }); }}>
                <FormField label={t('rpt.mov.date')}><Input onChange={(e) => setDate(e.target.value)} required type="date" value={date} /></FormField>
                <Button size="sm" type="submit" variant="outline">{t('rpt.mov.show')}</Button>
                <Button onClick={() => window.print()} size="sm" type="button" variant="outline">{t('rpt.export.print')}</Button>
            </form>
            {may_export && (
                <div className="flex flex-wrap items-end gap-3 print:hidden">
                    <FormField hint={t('rpt.export.piiNote')} label={t('rpt.export.purpose')}><Input maxLength={300} onChange={(e) => setPurpose(e.target.value)} value={purpose} /></FormField>
                    <Button asChild={purpose.trim() !== ''} disabled={purpose.trim() === ''} size="sm" variant="outline">{purpose.trim() === '' ? <span>{t('rpt.export.csv')}</span> : <a href={`/reports/movements/export?${query}`}>{t('rpt.export.csv')}</a>}</Button>
                </div>
            )}
            {r.expected ? <Alert title={t('rpt.mov.expected')} tone="info" /> : null}
            <ReportMeta meta={r.meta} />

            <section aria-label={t('rpt.mov.arrivals', { n: r.totals.arrivals })} data-testid="arrivals">
                <h2 className="mb-1 text-lg font-semibold">{t('rpt.mov.arrivals', { n: r.totals.arrivals })}</h2>
                {r.arrivals.length === 0 ? <p className="text-sm text-muted-foreground">{t('rpt.mov.empty')}</p> : (
                    <table className="w-full text-left text-sm">{head(['reservation', 'guest', 'type', 'room', 'guests', 'until'])}<tbody>{r.arrivals.map((a) => (
                        <tr className="border-t border-border" key={a.reservation}><th className="py-1 font-medium" scope="row">{a.reservation}</th><td>{a.guest}</td><td>{a.room_type}</td><td>{a.room ?? '—'}</td><td>{pax(a.adults, a.children)}</td><td>{format.date(a.departure)}</td></tr>
                    ))}</tbody></table>
                )}
            </section>

            <section aria-label={t('rpt.mov.departures', { n: r.totals.departures })} data-testid="departures">
                <h2 className="mb-1 text-lg font-semibold">{t('rpt.mov.departures', { n: r.totals.departures })}</h2>
                {r.departures.length === 0 ? <p className="text-sm text-muted-foreground">{t('rpt.mov.empty')}</p> : (
                    <table className="w-full text-left text-sm">{head(['room', 'reservation', 'guest', 'status', 'balance'])}<tbody>{r.departures.map((s) => (
                        <tr className="border-t border-border" key={s.reservation}><th className="py-1 font-medium" scope="row">{s.room}</th><td>{s.reservation}</td><td>{s.guest}</td><td>{t(`rpt.mov.status.${s.status}` as 'rpt.mov.status.in_house')}</td><td>{format.money(s.balance_minor, context.currency)}</td></tr>
                    ))}</tbody></table>
                )}
            </section>

            <section aria-label={t('rpt.mov.inHouse', { n: r.totals.in_house, guests: r.totals.guests_in_house })} data-testid="in-house">
                <h2 className="mb-1 text-lg font-semibold">{t('rpt.mov.inHouse', { n: r.totals.in_house, guests: r.totals.guests_in_house })}</h2>
                {r.in_house.length === 0 ? <p className="text-sm text-muted-foreground">{t('rpt.mov.empty')}</p> : (
                    <table className="w-full text-left text-sm">{head(['room', 'reservation', 'guest', 'guests', 'until', 'balance'])}<tbody>{r.in_house.map((s) => (
                        <tr className="border-t border-border" key={s.reservation}><th className="py-1 font-medium" scope="row">{s.room}</th><td>{s.reservation}</td><td>{s.guest}</td><td>{pax(s.adults, s.children)}</td><td>{format.date(s.checked_out ?? s.expected_departure)}</td><td>{format.money(s.balance_minor, context.currency)}</td></tr>
                    ))}</tbody></table>
                )}
            </section>
        </ReportingShell>
    );
}
