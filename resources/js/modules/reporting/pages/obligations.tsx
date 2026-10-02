import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Split = { room: number; laundry: number; other: number; total: number; outlets: Record<string, number> };
type Row = {
    month: string; tax: Split; service_charge: Split; employee_estimate_minor: number; due_date: string; status: 'open' | 'due' | 'overdue' | 'reported' | 'nothing';
    filing: { reported_on: string; reference: string; tax_minor: number } | null;
};
type Timeline = {
    outlets: { code: string; name: string }[]; business_date: string; rows: Row[]; totals: { tax: number; service_charge: number; employee_estimate: number }; notes: string[]; may_manage: boolean;
    settings: { tax_report_day: number; service_employee_share_bp: number; lock_version: number | null; configured: boolean };
};

const TONE = { open: 'neutral', due: 'info', overdue: 'danger', reported: 'success', nothing: 'neutral' } as const;

/** Tax and service charge by month, with the date the tax is to be reported by and whether it was (FR-DSH-013, FR-DSH-014). */
export default function ObligationsPage({ context, timeline: tl }: { context: { currency: string }; timeline: Timeline }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const money = (minor: number) => format.money(minor, context.currency);
    const [form, setForm] = useState({ day: String(tl.settings.tax_report_day), share: String(tl.settings.service_employee_share_bp / 100), reason: '' });
    const [filing, setFiling] = useState<{ month: string; date: string; reference: string } | null>(null);

    async function saveSettings() {
        const done = await action.run('/reports/obligations/settings', {
            body: { tax_report_day: Number(form.day), service_employee_share_bp: Math.round(Number(form.share) * 100), lock_version: tl.settings.lock_version, reason: form.reason.trim() },
        });
        if (done !== null) { setForm({ ...form, reason: '' }); router.reload({ only: ['timeline'] }); }
    }

    async function report() {
        if (filing === null) return;
        const done = await action.run('/reports/obligations/filings', { body: { month: filing.month, reported_on: filing.date, reference: filing.reference.trim() } });
        if (done !== null) { setFiling(null); router.reload({ only: ['timeline'] }); }
    }

    const col = (k: string) => t(`rpt.obl.col.${k}` as 'rpt.obl.col.month');
    const columns: DataGridColumn<Row>[] = [
        { id: 'month', label: col('month'), value: (r) => r.month, rowHeader: true },
        { id: 'taxRooms', label: col('taxRooms'), align: 'right', value: (r) => r.tax.room, cell: (r) => money(r.tax.room) },
        { id: 'taxLaundry', label: col('taxLaundry'), align: 'right', value: (r) => r.tax.laundry, cell: (r) => money(r.tax.laundry) },
        ...tl.outlets.map((o): DataGridColumn<Row> => ({ id: `outlet-${o.code}`, label: t('rpt.obl.col.outlet', { name: o.name }), align: 'right', value: (r) => r.tax.outlets[o.code] ?? 0, cell: (r) => money(r.tax.outlets[o.code] ?? 0), hidden: true })),
        { id: 'taxOther', label: col('taxOther'), align: 'right', value: (r) => r.tax.other, cell: (r) => money(r.tax.other) },
        { id: 'taxTotal', label: col('taxTotal'), align: 'right', className: 'font-medium', value: (r) => r.tax.total, cell: (r) => money(r.tax.total), footer: <span data-testid="total-tax">{money(tl.totals.tax)}</span> },
        { id: 'service', label: col('service'), align: 'right', value: (r) => r.service_charge.total, cell: (r) => money(r.service_charge.total), footer: <span data-testid="total-service">{money(tl.totals.service_charge)}</span> },
        { id: 'staff', label: col('staff'), align: 'right', value: (r) => r.employee_estimate_minor, cell: (r) => money(r.employee_estimate_minor), footer: money(tl.totals.employee_estimate) },
        { id: 'due', label: col('due'), value: (r) => r.due_date, searchText: (r) => `${r.due_date} ${format.date(r.due_date)}`, cell: (r) => format.date(r.due_date) },
        {
            id: 'status',
            label: col('status'),
            value: (r) => r.status,
            filter: 'select',
            filterLabel: (v) => t(`rpt.obl.status.${v}` as 'rpt.obl.status.open'),
            searchText: (r) => `${t(`rpt.obl.status.${r.status}` as 'rpt.obl.status.open')} ${r.filing?.reference ?? ''}`,
            cell: (r) => (
                <>
                    <StatusBadge label={t(`rpt.obl.status.${r.status}` as 'rpt.obl.status.open')} tone={TONE[r.status]} />
                    {r.filing !== null ? <span className="block text-xs text-muted-foreground">{t('rpt.obl.reportedOn', { date: format.date(r.filing.reported_on), reference: r.filing.reference })}</span> : null}
                </>
            ),
        },
        {
            id: 'action',
            label: t('rpt.obl.markReported'),
            header: <span className="sr-only">{t('rpt.obl.markReported')}</span>,
            cell: (r) => (tl.may_manage && (r.status === 'due' || r.status === 'overdue') ? <Button onClick={() => setFiling({ month: r.month, date: tl.business_date, reference: '' })} size="sm" type="button" variant="outline">{t('rpt.obl.markReported')}</Button> : null),
        },
    ];

    return (
        <ReportingShell description={t('rpt.obl.description')} title={t('rpt.obl.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {!tl.settings.configured ? <Alert title={t('rpt.obl.baseline', { day: tl.settings.tax_report_day, share: tl.settings.service_employee_share_bp / 100 })} tone="warning" /> : null}

            <DataGrid
                caption={t('rpt.obl.title')}
                columns={columns}
                footerLabel={t('rpt.flash.total')}
                getRowId={(r) => r.month}
                id="rpt.obligations"
                rowTestId={(r) => `month-${r.month}`}
                rows={tl.rows}
                testId="obligations"
            />
            <ul className="list-disc pl-5 text-xs text-muted-foreground">{[t('rpt.obl.note.definition'), t('rpt.obl.note.outlets'), t('rpt.obl.note.baseline')].map((n) => <li key={n}>{n}</li>)}</ul>

            {filing !== null && (
                <section aria-labelledby="obl-file-h" className="flex max-w-xl flex-col gap-3 border border-border p-4">
                    <h2 className="text-lg font-semibold" id="obl-file-h">{t('rpt.obl.filingTitle', { month: filing.month })}</h2>
                    <FormField error={action.fieldError('reported_on')} label={t('rpt.obl.reportedDate')}><Input onChange={(e) => setFiling({ ...filing, date: e.target.value })} type="date" value={filing.date} /></FormField>
                    <FormField error={action.fieldError('reference')} hint={t('rpt.obl.referenceHint')} label={t('rpt.obl.reference')}><Input maxLength={60} onChange={(e) => setFiling({ ...filing, reference: e.target.value })} value={filing.reference} /></FormField>
                    <div className="flex gap-2"><Button loading={action.busy} onClick={() => void report()} type="button">{t('rpt.obl.confirmReported')}</Button><Button disabled={action.busy} onClick={() => setFiling(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button></div>
                </section>
            )}

            {tl.may_manage && (
                <section aria-labelledby="obl-set-h" className="flex max-w-xl flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="obl-set-h">{t('rpt.obl.settings')}</h2>
                    <FormField error={action.fieldError('tax_report_day')} hint={t('rpt.obl.dayHint')} label={t('rpt.obl.day')}><Input max={28} min={1} onChange={(e) => setForm({ ...form, day: e.target.value })} type="number" value={form.day} /></FormField>
                    <FormField error={action.fieldError('service_employee_share_bp')} hint={t('rpt.obl.shareHint')} label={t('rpt.obl.share')}><Input inputMode="decimal" onChange={(e) => setForm({ ...form, share: e.target.value })} value={form.share} /></FormField>
                    <FormField error={action.fieldError('reason')} label={t('rpt.obl.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} /></FormField>
                    <div><Button disabled={form.reason.trim() === ''} loading={action.busy} onClick={() => void saveSettings()} type="button">{t('rpt.obl.saveSettings')}</Button></div>
                </section>
            )}
        </ReportingShell>
    );
}
