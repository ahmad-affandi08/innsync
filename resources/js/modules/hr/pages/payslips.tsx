import { Link } from '@inertiajs/react';

import { Alert } from '@/components/ui/alert';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { HrShell } from '@/modules/hr/components/hr-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Slip = { run_id: string; period: string; number: string; gross_minor: number; net_minor: number };

/** A person's own payslips, one for each month that was paid. */
export default function PayslipsPage({ payslips }: { payslips: { linked: boolean; currency: string; slips: Slip[] } }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const columns: DataGridColumn<Slip>[] = [
        { id: 'period', label: t('hr.run.period'), value: (s) => s.period, rowHeader: true, cell: (s) => <Link className="underline" href={`/hr/payslips/${s.run_id}`}>{s.period}</Link> },
        { id: 'gross', label: t('hr.run.gross'), align: 'right', value: (s) => s.gross_minor, cell: (s) => format.money(s.gross_minor, payslips.currency) },
        { id: 'net', label: t('hr.run.net'), align: 'right', value: (s) => s.net_minor, cell: (s) => <strong>{format.money(s.net_minor, payslips.currency)}</strong> },
    ];

    return (
        <HrShell description={t('hr.slip.listDescription')} title={t('hr.slip.listTitle')}>
            {!payslips.linked ? <Alert title={t('hr.att.noEmployee')} tone="warning" /> : null}
            <DataGrid caption={t('hr.slip.listTitle')} columns={columns} empty={<EmptyState illustration="checklist" title={t('hr.slip.none')} />} getRowId={(s) => s.run_id} id="hr.slips" rows={payslips.slips} testId="hr-payslips" />
        </HrShell>
    );
}
