import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DateRangePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Select } from '@/components/ui/select';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Row = {
    department: string; supplier_id: string; supplier_name: string; item_id: string; item_code: string; item_name: string; base_unit: string;
    received_base_milli: number; returned_base_milli: number; net_base_milli: number; received_value_minor: number; returned_value_minor: number; net_value_minor: number;
};
type Report = {
    currency: string; from: string; to: string; rows: Row[]; total_received_minor: number; total_returned_minor: number; total_net_minor: number;
    departments: string[]; suppliers: { id: string; name: string }[];
};
type Group = { key: string; label: string; received: number; returned: number; net: number; qty: { unit: string; received: number; returned: number; net: number } | null };
type GroupBy = 'none' | 'department' | 'supplier' | 'item';

/** What was bought in a period, by department, supplier and item (FR-PUR-009). */
export default function PurchaseReportPage({ report, filters }: { report: Report; filters: { supplier: string; department: string } }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const [period, setPeriod] = useState({ from: report.from, to: report.to });
    const [supplier, setSupplier] = useState(filters.supplier);
    const [department, setDepartment] = useState(filters.department);
    const [groupBy, setGroupBy] = useState<GroupBy>('none');
    const money = (minor: number) => format.money(minor, report.currency);
    const qty = (milli: number, unit: string) => `${formatMilli(milli, locale)} ${unit}`;
    const dept = (d: string) => (d === 'none' ? t('inv.rep.noDepartment') : t(`inv.dept.${d}` as MessageKey));

    function go(next: { from?: string; to?: string; supplier?: string; department?: string }) {
        const query = { from: next.from ?? period.from, to: next.to ?? period.to, supplier: next.supplier ?? supplier, department: next.department ?? department };

        router.get('/inventory/reports/purchases', Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '')), { preserveScroll: true });
    }

    const groups = useMemo<Group[]>(() => {
        if (groupBy === 'none') return [];
        const byKey = new Map<string, Group>();

        for (const r of report.rows) {
            const [key, label] = groupBy === 'department' ? [r.department, dept(r.department)] : groupBy === 'supplier' ? [r.supplier_id, r.supplier_name] : [r.item_id, `${r.item_code} · ${r.item_name}`];
            const g = byKey.get(key) ?? { key, label, received: 0, returned: 0, net: 0, qty: groupBy === 'item' ? { unit: r.base_unit, received: 0, returned: 0, net: 0 } : null };

            g.received += r.received_value_minor;
            g.returned += r.returned_value_minor;
            g.net += r.net_value_minor;

            if (g.qty !== null) {
                g.qty.received += r.received_base_milli;
                g.qty.returned += r.returned_base_milli;
                g.qty.net += r.net_base_milli;
            }

            byKey.set(key, g);
        }

        return [...byKey.values()].sort((a, b) => a.label.localeCompare(b.label));
    }, [groupBy, report.rows, locale]);

    const groupColumns: DataGridColumn<Group>[] = [
        { id: 'key', label: t(`inv.rep.group.${groupBy === 'none' ? 'item' : groupBy}` as MessageKey), value: (g) => g.label, rowHeader: true },
        { id: 'rq', label: t('inv.rep.col.receivedQty'), align: 'right', value: (g) => g.qty?.received ?? 0, cell: (g) => (g.qty === null ? '—' : qty(g.qty.received, g.qty.unit)) },
        { id: 'tq', label: t('inv.rep.col.returnedQty'), align: 'right', value: (g) => g.qty?.returned ?? 0, cell: (g) => (g.qty === null ? '—' : qty(g.qty.returned, g.qty.unit)) },
        { id: 'nq', label: t('inv.rep.col.netQty'), align: 'right', value: (g) => g.qty?.net ?? 0, cell: (g) => (g.qty === null ? '—' : qty(g.qty.net, g.qty.unit)) },
        { id: 'rv', label: t('inv.rep.col.receivedValue'), align: 'right', value: (g) => g.received, cell: (g) => money(g.received), footer: <strong>{money(report.total_received_minor)}</strong> },
        { id: 'tv', label: t('inv.rep.col.returnedValue'), align: 'right', value: (g) => g.returned, cell: (g) => money(g.returned), footer: <strong>{money(report.total_returned_minor)}</strong> },
        { id: 'nv', label: t('inv.rep.col.netValue'), align: 'right', value: (g) => g.net, cell: (g) => <span className="font-medium">{money(g.net)}</span>, footer: <strong>{money(report.total_net_minor)}</strong> },
    ];

    const columns: DataGridColumn<Row>[] = [
        { id: 'department', label: t('inv.col.department'), value: (r) => r.department, filter: 'select', filterLabel: dept, cell: (r) => dept(r.department) },
        { id: 'supplier', label: t('inv.po.supplier'), value: (r) => r.supplier_name, filter: 'select' },
        { id: 'item', label: t('inv.col.item'), value: (r) => r.item_code, searchText: (r) => `${r.item_code} ${r.item_name}`, rowHeader: true, cell: (r) => <><span className="font-medium">{r.item_code}</span> <span className="text-muted-foreground">{r.item_name}</span></> },
        { id: 'rq', label: t('inv.rep.col.receivedQty'), align: 'right', value: (r) => r.received_base_milli, cell: (r) => qty(r.received_base_milli, r.base_unit) },
        { id: 'tq', label: t('inv.rep.col.returnedQty'), align: 'right', value: (r) => r.returned_base_milli, cell: (r) => qty(r.returned_base_milli, r.base_unit) },
        { id: 'nq', label: t('inv.rep.col.netQty'), align: 'right', value: (r) => r.net_base_milli, cell: (r) => qty(r.net_base_milli, r.base_unit) },
        { id: 'rv', label: t('inv.rep.col.receivedValue'), align: 'right', value: (r) => r.received_value_minor, cell: (r) => money(r.received_value_minor), footer: <strong data-testid="purchases-total-received">{money(report.total_received_minor)}</strong> },
        { id: 'tv', label: t('inv.rep.col.returnedValue'), align: 'right', value: (r) => r.returned_value_minor, cell: (r) => money(r.returned_value_minor), footer: <strong data-testid="purchases-total-returned">{money(report.total_returned_minor)}</strong> },
        { id: 'nv', label: t('inv.rep.col.netValue'), align: 'right', value: (r) => r.net_value_minor, cell: (r) => <span className="font-medium">{money(r.net_value_minor)}</span>, footer: <strong data-testid="purchases-total-net">{money(report.total_net_minor)}</strong> },
    ];

    return (
        <InventoryShell
            actions={<Button className="print:hidden" onClick={() => window.print()} type="button" variant="outline">{t('rpt.export.print')}</Button>}
            description={t('inv.rep.purchases.description')}
            title={t('inv.rep.purchases.title')}
            wide
        >
            <form
                className="flex flex-wrap items-end gap-3 border border-border bg-surface p-4 print:hidden"
                onSubmit={(e) => {
                    e.preventDefault();
                    go({});
                }}
            >
                <FormField label={t('inv.rep.period')}>
                    <DateRangePicker className="min-h-8 py-1" onChange={(range) => { setPeriod(range); go(range); }} value={period} />
                </FormField>
                <FormField label={t('inv.po.supplier')}>
                    <Select className="min-w-48" onChange={(e) => { setSupplier(e.target.value); go({ supplier: e.target.value }); }} value={supplier}>
                        <option value="">{t('inv.rep.allSuppliers')}</option>
                        {report.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </Select>
                </FormField>
                <FormField label={t('inv.col.department')}>
                    <Select className="min-w-48" onChange={(e) => { setDepartment(e.target.value); go({ department: e.target.value }); }} value={department}>
                        <option value="">{t('inv.rep.allDepartments')}</option>
                        {report.departments.map((d) => <option key={d} value={d}>{dept(d)}</option>)}
                    </Select>
                </FormField>
                <FormField label={t('inv.rep.groupBy')}>
                    <Select className="min-w-44" onChange={(e) => setGroupBy(e.target.value as GroupBy)} searchable={false} value={groupBy}>
                        {(['none', 'department', 'supplier', 'item'] as const).map((g) => <option key={g} value={g}>{t(`inv.rep.group.${g}` as MessageKey)}</option>)}
                    </Select>
                </FormField>
            </form>
            <p className="text-sm text-muted-foreground">{t('inv.rep.purchases.note')}</p>

            {groupBy === 'none' ? null : (
                <section aria-labelledby="pur-groups-h" className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold" id="pur-groups-h">{t('inv.rep.groupTotals', { group: t(`inv.rep.group.${groupBy}` as MessageKey).toLowerCase() })}</h2>
                    <DataGrid caption={t('inv.rep.groupTotals', { group: t(`inv.rep.group.${groupBy}` as MessageKey).toLowerCase() })} columns={groupColumns} empty={<EmptyState title={t('inv.rep.purchases.empty')} />} footerLabel={t('inv.rep.total')} getRowId={(g) => g.key} id={`inv.report.purchases.groups.${groupBy}`} rows={groups} testId="purchases-groups" />
                </section>
            )}

            <section aria-labelledby="pur-detail-h" className="flex flex-col gap-3">
                {groupBy === 'none' ? null : <h2 className="text-lg font-semibold" id="pur-detail-h">{t('inv.rep.detail')}</h2>}
                <DataGrid caption={t('inv.rep.purchases.title')} columns={columns} empty={<EmptyState title={t('inv.rep.purchases.empty')} />} footerLabel={t('inv.rep.total')} getRowId={(r) => `${r.department}:${r.supplier_id}:${r.item_id}`} id="inv.report.purchases" rows={report.rows} testId="purchases" />
            </section>
        </InventoryShell>
    );
}
