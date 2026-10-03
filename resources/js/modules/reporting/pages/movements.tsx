import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
    const col = (k: string) => t(`rpt.mov.col.${k}` as 'rpt.mov.col.room');
    const none = <p className="text-sm text-muted-foreground">{t('rpt.mov.empty')}</p>;
    const money = (minor: number) => format.money(minor, context.currency);
    const arrivalColumns: DataGridColumn<Arrival>[] = [
        { id: 'reservation', label: col('reservation'), value: (a) => a.reservation, rowHeader: true },
        { id: 'guest', label: col('guest'), value: (a) => a.guest },
        { id: 'type', label: col('type'), value: (a) => a.room_type, filter: 'select' },
        { id: 'room', label: col('room'), value: (a) => a.room, cell: (a) => a.room ?? '—' },
        { id: 'guests', label: col('guests'), value: (a) => a.adults + a.children, searchText: (a) => pax(a.adults, a.children), cell: (a) => pax(a.adults, a.children) },
        { id: 'until', label: col('until'), value: (a) => a.departure, searchText: (a) => `${a.departure} ${format.date(a.departure)}`, cell: (a) => format.date(a.departure) },
        { id: 'status', label: col('status'), value: (a) => a.status, filter: 'select', filterLabel: (v) => t(`fo.status.${v}` as 'fo.status.tentative'), cell: (a) => t(`fo.status.${a.status}` as 'fo.status.tentative'), hidden: true },
    ];
    const departureColumns: DataGridColumn<Stay>[] = [
        { id: 'room', label: col('room'), value: (s) => s.room, rowHeader: true },
        { id: 'reservation', label: col('reservation'), value: (s) => s.reservation },
        { id: 'guest', label: col('guest'), value: (s) => s.guest },
        { id: 'status', label: col('status'), value: (s) => s.status, filter: 'select', filterLabel: (v) => t(`rpt.mov.status.${v}` as 'rpt.mov.status.in_house'), cell: (s) => t(`rpt.mov.status.${s.status}` as 'rpt.mov.status.in_house') },
        { id: 'balance', label: col('balance'), align: 'right', value: (s) => s.balance_minor, cell: (s) => money(s.balance_minor) },
    ];
    const inHouseColumns: DataGridColumn<Stay>[] = [
        { id: 'room', label: col('room'), value: (s) => s.room, rowHeader: true },
        { id: 'reservation', label: col('reservation'), value: (s) => s.reservation },
        { id: 'guest', label: col('guest'), value: (s) => s.guest },
        { id: 'guests', label: col('guests'), value: (s) => s.adults + s.children, searchText: (s) => pax(s.adults, s.children), cell: (s) => pax(s.adults, s.children) },
        { id: 'until', label: col('until'), value: (s) => s.checked_out ?? s.expected_departure, searchText: (s) => { const d = s.checked_out ?? s.expected_departure; return `${d} ${format.date(d)}`; }, cell: (s) => format.date(s.checked_out ?? s.expected_departure) },
        { id: 'balance', label: col('balance'), align: 'right', value: (s) => s.balance_minor, cell: (s) => money(s.balance_minor) },
    ];

    return (
        <ReportingShell description={t('rpt.mov.description')} title={t('rpt.mov.title')} wide>
            <form className="flex flex-wrap items-end gap-3 print:hidden" onSubmit={(e) => { e.preventDefault(); router.get('/reports/movements', { date }); }}>
                <FormField label={t('rpt.mov.date')}><DatePicker onChange={(e) => setDate(e.target.value)} required value={date} /></FormField>
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

            <Tabs defaultValue="arrivals">
                <TabsList>
                    <TabsTrigger value="arrivals">{t('rpt.mov.arrivals', { n: r.totals.arrivals })}</TabsTrigger>
                    <TabsTrigger value="departures">{t('rpt.mov.departures', { n: r.totals.departures })}</TabsTrigger>
                    <TabsTrigger value="in-house">{t('rpt.mov.inHouse', { n: r.totals.in_house, guests: r.totals.guests_in_house })}</TabsTrigger>
                </TabsList>

            <TabsContent className="flex flex-col gap-3" data-testid="arrivals" value="arrivals">
                <h2 className="sr-only">{t('rpt.mov.arrivals', { n: r.totals.arrivals })}</h2>
                <DataGrid
                    caption={t('rpt.mov.arrivals', { n: r.totals.arrivals })}
                    columns={arrivalColumns}
                    empty={none}
                    getRowId={(a) => a.reservation}
                    id="rpt.movements.arrivals"
                    rows={r.arrivals}
                />
            </TabsContent>

            <TabsContent className="flex flex-col gap-3" data-testid="departures" value="departures">
                <h2 className="sr-only">{t('rpt.mov.departures', { n: r.totals.departures })}</h2>
                <DataGrid
                    caption={t('rpt.mov.departures', { n: r.totals.departures })}
                    columns={departureColumns}
                    empty={none}
                    getRowId={(s) => s.reservation}
                    id="rpt.movements.departures"
                    rows={r.departures}
                />
            </TabsContent>

            <TabsContent className="flex flex-col gap-3" data-testid="in-house" value="in-house">
                <h2 className="sr-only">{t('rpt.mov.inHouse', { n: r.totals.in_house, guests: r.totals.guests_in_house })}</h2>
                <DataGrid
                    caption={t('rpt.mov.inHouse', { n: r.totals.in_house, guests: r.totals.guests_in_house })}
                    columns={inHouseColumns}
                    empty={none}
                    getRowId={(s) => s.reservation}
                    id="rpt.movements.in_house"
                    rows={r.in_house}
                />
            </TabsContent>
            </Tabs>
        </ReportingShell>
    );
}
