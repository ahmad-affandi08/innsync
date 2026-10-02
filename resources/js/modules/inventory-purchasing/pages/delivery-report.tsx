import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DateRangePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { ORDER_TONE } from '@/modules/inventory-purchasing/lib/purchasing';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type SupplierRow = {
    supplier_id: string; supplier_name: string; orders: number; receipts: number; dated_orders: number; on_time_orders: number; late_orders: number;
    on_time_bp: number | null; average_days_late: number; fill_bp: number | null; refusal_bp: number | null;
};
type OrderRow = {
    id: string; number: string; supplier_name: string; status: string; expected_date: string | null; first_received_on: string; last_received_on: string; days_late: number | null;
    ordered_milli: number; accepted_milli: number; refused_milli: number; receipts: number; complete: boolean; overdue: boolean;
};
type Report = { from: string; to: string; suppliers: SupplierRow[]; orders: OrderRow[] };

/** How reliably each supplier delivers (FR-PUR-010). */
export default function DeliveryReportPage({ report }: { report: Report }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const [period, setPeriod] = useState({ from: report.from, to: report.to });
    const qty = (milli: number) => formatMilli(milli, locale);
    // Basis points are shown as a percentage (8696 -> 86.96 %); nothing is calculated from them here.
    const percent = (bp: number | null) => (bp === null ? '—' : `${format.number(bp / 100)} %`);
    const status = (s: string) => t(`inv.po.status.${s}` as MessageKey);

    function timing(o: OrderRow): { label: string; tone: StatusTone } {
        if (o.overdue) return { label: t('inv.rep.del.overdue'), tone: 'danger' };
        if (o.days_late === null) return { label: t('inv.rep.del.noDate'), tone: 'neutral' };

        return o.days_late === 0 ? { label: t('inv.rep.del.onTime'), tone: 'success' } : { label: t('inv.rep.del.lateBy', { count: o.days_late }), tone: 'warning' };
    }

    const supplierColumns: DataGridColumn<SupplierRow>[] = [
        { id: 'supplier', label: t('inv.po.supplier'), value: (s) => s.supplier_name, rowHeader: true },
        { id: 'orders', label: t('inv.rep.del.col.orders'), align: 'right', value: (s) => s.orders },
        { id: 'receipts', label: t('inv.rep.del.col.receipts'), align: 'right', value: (s) => s.receipts, hidden: true },
        { id: 'dated', label: t('inv.rep.del.col.dated'), align: 'right', value: (s) => s.dated_orders, hidden: true },
        { id: 'onTime', label: t('inv.rep.del.col.onTime'), align: 'right', value: (s) => s.on_time_orders },
        { id: 'late', label: t('inv.rep.del.col.late'), align: 'right', value: (s) => s.late_orders },
        { id: 'onTimeRate', label: t('inv.rep.del.col.onTimeRate'), align: 'right', value: (s) => s.on_time_bp ?? -1, cell: (s) => <span className="font-medium">{percent(s.on_time_bp)}</span> },
        { id: 'avgLate', label: t('inv.rep.del.col.avgLate'), align: 'right', value: (s) => s.average_days_late, cell: (s) => format.number(s.average_days_late) },
        { id: 'fill', label: t('inv.rep.del.col.fillRate'), align: 'right', value: (s) => s.fill_bp ?? -1, cell: (s) => percent(s.fill_bp) },
        { id: 'refusal', label: t('inv.rep.del.col.refusalRate'), align: 'right', value: (s) => s.refusal_bp ?? -1, cell: (s) => percent(s.refusal_bp) },
    ];

    const orderColumns: DataGridColumn<OrderRow>[] = [
        { id: 'number', label: t('inv.col.number'), value: (o) => o.number, rowHeader: true },
        { id: 'supplier', label: t('inv.po.supplier'), value: (o) => o.supplier_name, filter: 'select' },
        { id: 'status', label: t('inv.col.status'), value: (o) => o.status, filter: 'select', filterLabel: status, cell: (o) => <StatusBadge label={status(o.status)} tone={ORDER_TONE[o.status] ?? 'neutral'} /> },
        { id: 'expected', label: t('inv.po.expected'), value: (o) => o.expected_date ?? '', cell: (o) => (o.expected_date === null ? '—' : format.date(o.expected_date)) },
        { id: 'first', label: t('inv.rep.del.col.firstReceived'), value: (o) => o.first_received_on, cell: (o) => format.date(o.first_received_on), hidden: true },
        { id: 'last', label: t('inv.rep.del.col.lastReceived'), value: (o) => o.last_received_on, cell: (o) => format.date(o.last_received_on) },
        { id: 'timing', label: t('inv.rep.del.col.timing'), value: (o) => timing(o).label, filter: 'select', cell: (o) => { const x = timing(o); return <StatusBadge label={x.label} tone={x.tone} />; } },
        { id: 'ordered', label: t('inv.rep.del.col.ordered'), align: 'right', value: (o) => o.ordered_milli, cell: (o) => qty(o.ordered_milli) },
        { id: 'accepted', label: t('inv.rep.del.col.accepted'), align: 'right', value: (o) => o.accepted_milli, cell: (o) => qty(o.accepted_milli) },
        { id: 'refused', label: t('inv.rep.del.col.refused'), align: 'right', value: (o) => o.refused_milli, cell: (o) => qty(o.refused_milli) },
        { id: 'receipts', label: t('inv.rep.del.col.receipts'), align: 'right', value: (o) => o.receipts, hidden: true },
    ];

    return (
        <InventoryShell
            actions={<Button className="print:hidden" onClick={() => window.print()} type="button" variant="outline">{t('rpt.export.print')}</Button>}
            description={t('inv.rep.del.description')}
            title={t('inv.rep.del.title')}
            wide
        >
            <div className="flex flex-wrap items-end gap-3 border border-border bg-surface p-4 print:hidden">
                <FormField label={t('inv.rep.period')}>
                    <DateRangePicker className="min-h-8 py-1" onChange={(range) => { setPeriod(range); router.get('/inventory/reports/deliveries', range, { preserveScroll: true }); }} value={period} />
                </FormField>
            </div>

            <section aria-labelledby="del-suppliers-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="del-suppliers-h">{t('inv.rep.del.suppliers')}</h2>
                <DataGrid caption={t('inv.rep.del.suppliers')} columns={supplierColumns} empty={<EmptyState title={t('inv.rep.del.noSuppliers')} />} getRowId={(s) => s.supplier_id} id="inv.report.deliveries.suppliers" rows={report.suppliers} testId="delivery-suppliers" />
            </section>

            <section aria-labelledby="del-orders-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="del-orders-h">{t('inv.rep.del.orders')}</h2>
                <DataGrid caption={t('inv.rep.del.orders')} columns={orderColumns} empty={<EmptyState title={t('inv.rep.del.noOrders')} />} getRowId={(o) => o.id} id="inv.report.deliveries.orders" rows={report.orders} testId="delivery-orders" />
            </section>
        </InventoryShell>
    );
}
