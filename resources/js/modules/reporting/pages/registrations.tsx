import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PeriodPicker } from '@/modules/reporting/components/period-picker';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = {
    stay_id: string; reservation: string; room: string; full_name: string; nationality: string; id_type: string; id_number: string; id_valid_until: string | null; visa_number: string | null;
    adults: number; children: number; address: string | null; checked_in: string; expected_departure: string; checked_out: string | null;
};
type Report = { meta: Meta & { period: { preset: string; from: string; to: string } }; identity_visible: boolean; rows: Row[] };

export default function RegistrationsPage({ foreign, may_export, report: r }: { foreign: boolean; may_export: boolean; report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const path = foreign ? '/reports/foreign-guests' : '/reports/registrations';
    const [nationality, setNationality] = useState(r.meta.filters.nationality ?? '');
    const [purpose, setPurpose] = useState('');
    const query = new URLSearchParams({ from: r.meta.period.from, to: r.meta.period.to, ...(nationality !== '' ? { nationality } : {}), purpose }).toString();

    const columns: DataGridColumn<Row>[] = [
        { id: 'room', label: t('rpt.reg.col.room'), value: (row) => row.room, rowHeader: true },
        { id: 'name', label: t('rpt.reg.col.name'), value: (row) => row.full_name },
        { id: 'nationality', label: t('rpt.reg.col.nationality'), value: (row) => row.nationality, filter: 'select' },
        {
            id: 'id', label: t('rpt.reg.col.id'), value: (row) => row.id_number, searchText: (row) => `${row.id_type} ${row.id_number}`, className: 'font-mono text-xs',
            cell: (row) => `${t(`fo.checkin.idType.${row.id_type}` as 'fo.checkin.idType.ktp')} ${row.id_number}${row.id_valid_until !== null ? ` · ${format.date(row.id_valid_until)}` : ''}`,
        },
        { id: 'visa', label: t('rpt.reg.col.visa'), value: (row) => row.visa_number ?? '', cell: (row) => row.visa_number ?? '—', className: 'font-mono text-xs', hidden: true },
        { id: 'guests', label: t('rpt.reg.col.guests'), value: (row) => row.adults + row.children, cell: (row) => t('rpt.reg.guestsCell', { adults: row.adults, children: row.children }) },
        { id: 'address', label: t('rpt.reg.col.address'), value: (row) => row.address ?? '', cell: (row) => row.address ?? '—', className: 'max-w-48 break-words', hidden: true },
        { id: 'stay', label: t('rpt.reg.col.stay'), value: (row) => row.checked_in, searchText: (row) => `${row.checked_in} ${row.expected_departure}`, cell: (row) => `${format.date(row.checked_in)} – ${format.date(row.expected_departure)}` },
    ];

    return (
        <ReportingShell description={t('rpt.reg.description')} title={foreign ? t('rpt.reg.foreignTitle') : t('rpt.reg.title')} wide>
            <PeriodPicker extra={nationality !== '' ? { nationality } : {}} from={r.meta.period.from} path={path} preset={r.meta.period.preset} to={r.meta.period.to} />
            {!foreign && (
                <form className="flex flex-wrap items-end gap-3 print:hidden" onSubmit={(e) => { e.preventDefault(); router.get(path, { from: r.meta.period.from, to: r.meta.period.to, ...(nationality !== '' ? { nationality } : {}) }); }}>
                    <FormField label={t('rpt.reg.nationality')}><Input maxLength={2} onChange={(e) => setNationality(e.target.value.toUpperCase())} value={nationality} /></FormField>
                    <Button type="submit" variant="outline">{t('rpt.period.apply')}</Button>
                </form>
            )}
            <Alert title={r.identity_visible ? t('rpt.reg.clear') : t('rpt.reg.masked')} tone={r.identity_visible ? 'info' : 'warning'} />
            {may_export && (
                <div className="flex flex-wrap items-end gap-3 print:hidden">
                    <FormField hint={t('rpt.export.piiNote')} label={t('rpt.export.purpose')}><Input maxLength={300} onChange={(e) => setPurpose(e.target.value)} value={purpose} /></FormField>
                    <Button asChild={purpose.trim() !== ''} disabled={purpose.trim() === ''} size="sm" variant="outline">{purpose.trim() === '' ? <span>{t('rpt.export.csv')}</span> : <a href={`${path}/export?${query}`}>{t('rpt.export.csv')}</a>}</Button>
                    <Button asChild={purpose.trim() !== ''} disabled={purpose.trim() === ''} size="sm" variant="outline">{purpose.trim() === '' ? <span>{t('rpt.export.pdf')}</span> : <a href={`${path}/export?${query}&format=pdf`}>{t('rpt.export.pdf')}</a>}</Button>
                </div>
            )}
            <ReportMeta meta={r.meta} />
            <DataGrid
                caption={foreign ? t('rpt.reg.foreignTitle') : t('rpt.reg.title')}
                columns={columns}
                empty={<EmptyState title={t('rpt.reg.empty')} />}
                getRowId={(row) => row.stay_id}
                id={foreign ? 'rpt.foreign' : 'rpt.registrations'}
                rows={r.rows}
            />
        </ReportingShell>
    );
}
