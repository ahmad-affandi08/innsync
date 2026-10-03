import { router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'] as const;

type Bucket = (typeof BUCKETS)[number];
type Totals = Record<Bucket, number> & { total_minor: number };
type Row = Totals & { supplier_id: string; supplier_name: string; documents: number };
type Report = { as_of: string; currency: string; rows: Row[]; totals: Totals; buckets: string[] };

/** What is owed to each supplier by how late it is on the chosen date. */
export default function AgingPage({ report }: { report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const money = (minor: number) => format.money(minor, report.currency);

    const columns: DataGridColumn<Row>[] = [
        { id: 'supplier', label: t('fin.col.supplier'), value: (r) => r.supplier_name, rowHeader: true },
        { id: 'documents', label: t('fin.col.documents'), align: 'right', value: (r) => r.documents },
        ...BUCKETS.map((b): DataGridColumn<Row> => ({
            id: b, label: t(`fin.age.${b}` as MessageKey), align: 'right', value: (r) => r[b], cell: (r) => money(r[b]), footer: money(report.totals[b]),
        })),
        { id: 'total', label: t('fin.age.total'), align: 'right', value: (r) => r.total_minor, cell: (r) => money(r.total_minor), footer: money(report.totals.total_minor) },
    ];

    return (
        <FinanceShell
            actions={<Button className="print:hidden" onClick={() => window.print()} type="button" variant="outline">{t('fin.print')}</Button>}
            description={t('fin.age.description')}
            title={t('fin.age.title')}
            wide
        >
            <div className="max-w-xs print:hidden">
                <FormField label={t('fin.age.asOf')}>
                    <DatePicker onChange={(e) => router.get('/finance/aging', e.target.value ? { as_of: e.target.value } : {}, { preserveScroll: true })} value={report.as_of} />
                </FormField>
            </div>
            <p className="hidden text-sm print:block">{t('fin.age.reportTitle', { date: format.date(report.as_of) })}</p>

            <DataGrid caption={t('fin.age.title')} columns={columns} empty={<EmptyState title={t('fin.age.empty')} />} footerLabel={t('fin.age.total')} getRowId={(r) => r.supplier_id} id="fin.aging" rows={report.rows} testId="aging" />
        </FinanceShell>
    );
}
