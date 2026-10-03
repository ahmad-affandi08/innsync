import { Link, router } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { useCustomerKindLabel } from '@/modules/finance/lib/finance';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

const BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'] as const;

type Bucket = (typeof BUCKETS)[number];
type Totals = Record<Bucket, number> & { total_minor: number };
type Row = Totals & { customer_id: string; customer_code: string; customer_name: string; customer_kind: string; documents: number };
type Report = { as_of: string; currency: string; rows: Row[]; totals: Totals; buckets: string[] };

/** What each customer owes by how late it is on the chosen date. */
export default function ReceivableAgingPage({ aging }: { aging: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const kindLabel = useCustomerKindLabel();
    const money = (minor: number) => format.money(minor, aging.currency);

    const columns: DataGridColumn<Row>[] = [
        {
            id: 'customer', label: t('fin.ar.customer'), value: (r) => r.customer_name, searchText: (r) => `${r.customer_code} ${r.customer_name}`, rowHeader: true,
            cell: (r) => <Link className="font-medium underline" href={`/finance/receivables?customer_id=${r.customer_id}&status=open`}>{r.customer_name}</Link>,
        },
        { id: 'kind', label: t('fin.arc.kind'), value: (r) => r.customer_kind, filter: 'select', filterLabel: kindLabel, cell: (r) => kindLabel(r.customer_kind), hidden: true },
        { id: 'documents', label: t('fin.col.documents'), align: 'right', value: (r) => r.documents },
        ...BUCKETS.map((b): DataGridColumn<Row> => ({
            id: b, label: t(`fin.age.${b}` as MessageKey), align: 'right', value: (r) => r[b], cell: (r) => money(r[b]), footer: money(aging.totals[b]),
        })),
        { id: 'total', label: t('fin.age.total'), align: 'right', value: (r) => r.total_minor, cell: (r) => money(r.total_minor), footer: money(aging.totals.total_minor) },
    ];

    return (
        <FinanceShell
            actions={<Button className="print:hidden" onClick={() => window.print()} type="button" variant="outline">{t('fin.print')}</Button>}
            description={t('fin.ar.age.description')}
            title={t('fin.ar.age.title')}
            wide
        >
            <div className="max-w-xs print:hidden">
                <FormField label={t('fin.age.asOf')}>
                    <DatePicker onChange={(e) => router.get('/finance/receivables/aging', e.target.value ? { as_of: e.target.value } : {}, { preserveScroll: true })} value={aging.as_of} />
                </FormField>
            </div>
            <p className="hidden text-sm print:block">{t('fin.age.reportTitle', { date: format.date(aging.as_of) })}</p>

            <DataGrid caption={t('fin.ar.age.title')} columns={columns} empty={<EmptyState title={t('fin.ar.age.empty')} />} footerLabel={t('fin.age.total')} getRowId={(r) => r.customer_id} id="fin.receivableAging" rows={aging.rows} testId="receivable-aging" />
        </FinanceShell>
    );
}
