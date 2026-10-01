import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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

    return (
        <ReportingShell description={t('rpt.reg.description')} title={foreign ? t('rpt.reg.foreignTitle') : t('rpt.reg.title')} wide>
            <PeriodPicker extra={nationality !== '' ? { nationality } : {}} from={r.meta.period.from} path={path} preset={r.meta.period.preset} to={r.meta.period.to} />
            {!foreign && (
                <form className="flex flex-wrap items-end gap-3 print:hidden" onSubmit={(e) => { e.preventDefault(); router.get(path, { from: r.meta.period.from, to: r.meta.period.to, ...(nationality !== '' ? { nationality } : {}) }); }}>
                    <FormField label={t('rpt.reg.nationality')}><Input maxLength={2} onChange={(e) => setNationality(e.target.value.toUpperCase())} value={nationality} /></FormField>
                    <Button size="sm" type="submit" variant="outline">{t('rpt.period.apply')}</Button>
                </form>
            )}
            <Alert title={r.identity_visible ? t('rpt.reg.clear') : t('rpt.reg.masked')} tone={r.identity_visible ? 'info' : 'warning'} />
            {may_export && (
                <div className="flex flex-wrap items-end gap-3 print:hidden">
                    <FormField hint={t('rpt.export.piiNote')} label={t('rpt.export.purpose')}><Input maxLength={300} onChange={(e) => setPurpose(e.target.value)} value={purpose} /></FormField>
                    <Button asChild={purpose.trim() !== ''} disabled={purpose.trim() === ''} size="sm" variant="outline">{purpose.trim() === '' ? <span>{t('rpt.export.csv')}</span> : <a href={`${path}/export?${query}`}>{t('rpt.export.csv')}</a>}</Button>
                </div>
            )}
            <ReportMeta meta={r.meta} />
            {r.rows.length === 0 ? <EmptyState title={t('rpt.reg.empty')} /> : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('rpt.reg.col.room')}</th><th scope="col">{t('rpt.reg.col.name')}</th><th scope="col">{t('rpt.reg.col.nationality')}</th><th scope="col">{t('rpt.reg.col.id')}</th><th scope="col">{t('rpt.reg.col.visa')}</th><th scope="col">{t('rpt.reg.col.guests')}</th><th scope="col">{t('rpt.reg.col.address')}</th><th scope="col">{t('rpt.reg.col.stay')}</th></tr></thead>
                        <tbody>{r.rows.map((row) => (
                            <tr className="border-t border-border align-top" key={row.stay_id}>
                                <th className="py-1 font-medium" scope="row">{row.room}</th>
                                <td>{row.full_name}</td>
                                <td>{row.nationality}</td>
                                <td className="font-mono text-xs">{t(`fo.checkin.idType.${row.id_type}` as 'fo.checkin.idType.ktp')} {row.id_number}{row.id_valid_until !== null ? ` · ${format.date(row.id_valid_until)}` : ''}</td>
                                <td className="font-mono text-xs">{row.visa_number ?? '—'}</td>
                                <td>{t('rpt.reg.guestsCell', { adults: row.adults, children: row.children })}</td>
                                <td className="max-w-48 break-words">{row.address ?? '—'}</td>
                                <td>{format.date(row.checked_in)} – {format.date(row.expected_departure)}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            )}
        </ReportingShell>
    );
}
