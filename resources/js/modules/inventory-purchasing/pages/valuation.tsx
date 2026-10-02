import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = {
    item_id: string; item_code: string; item_name: string; category_name: string; base_unit: string;
    location_id: string; location_code: string; location_name: string; qty_milli: number; value_minor: number; average_cost_minor: number | null;
};
type Report = { currency: string; as_of: string; rows: Row[]; total_value_minor: number };

/** The value of stock at the end of a business date (FR-INV-007). */
export default function ValuationPage({ report }: { report: Report }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const [date, setDate] = useState(report.as_of);
    const money = (minor: number) => format.money(minor, report.currency);

    const columns: DataGridColumn<Row>[] = [
        { id: 'item', label: t('inv.col.item'), value: (r) => r.item_code, searchText: (r) => `${r.item_code} ${r.item_name}`, rowHeader: true, cell: (r) => <><span className="font-medium">{r.item_code}</span> <span className="text-muted-foreground">{r.item_name}</span></> },
        { id: 'location', label: t('inv.col.location'), value: (r) => r.location_code, searchText: (r) => `${r.location_code} ${r.location_name}`, filter: 'select', cell: (r) => r.location_name },
        { id: 'category', label: t('inv.col.category'), value: (r) => r.category_name, filter: 'select', hidden: true },
        { id: 'qty', label: t('inv.col.balance'), align: 'right', value: (r) => r.qty_milli, cell: (r) => `${formatMilli(r.qty_milli, locale)} ${r.base_unit}` },
        { id: 'avg', label: t('inv.col.avgCost'), align: 'right', value: (r) => r.average_cost_minor ?? 0, cell: (r) => (r.average_cost_minor === null ? '—' : `${money(r.average_cost_minor)} / ${r.base_unit}`) },
        { id: 'value', label: t('inv.col.value'), align: 'right', value: (r) => r.value_minor, cell: (r) => <span className="font-medium">{money(r.value_minor)}</span>, footer: <strong data-testid="valuation-total">{money(report.total_value_minor)}</strong> },
    ];

    return (
        <InventoryShell description={t('inv.val.description')} title={t('inv.val.title')}>
            <form
                className="flex max-w-md items-end gap-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.get('/inventory/valuation', date === '' ? {} : { as_of: date }, { preserveScroll: true });
                }}
            >
                <div className="flex-1">
                    <FormField label={t('inv.val.asOf')}>
                        <DatePicker onChange={(e) => setDate(e.target.value)} value={date} />
                    </FormField>
                </div>
                <Button type="submit">{t('inv.val.show')}</Button>
            </form>
            <p className="text-sm text-muted-foreground">{t('inv.val.note')}</p>
            <DataGrid caption={t('inv.val.title')} columns={columns} empty={<EmptyState title={t('inv.val.empty')} />} footerLabel={t('inv.val.total')} getRowId={(r) => `${r.item_id}:${r.location_id}`} id="inv.valuation" rows={report.rows} testId="valuation" />
        </InventoryShell>
    );
}
