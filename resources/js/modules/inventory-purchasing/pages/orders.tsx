import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { blankOrder, orderBody, OrderHeaderFields, OrderLineFields, type OrderFormState, type Party, type RequestLineChoice } from '@/modules/inventory-purchasing/components/order-form';
import { ORDER_STATUSES, ORDER_TONE, type ItemChoice } from '@/modules/inventory-purchasing/lib/purchasing';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Order = {
    id: string; number: string; revision: number; status: string; order_date: string; expected_date: string | null; supplier: Party; location: Party; total_minor: number;
};
type Overview = {
    currency: string; orders: Order[]; suppliers: Party[]; locations: Party[]; departments: string[]; items: ItemChoice[]; request_lines: RequestLineChoice[]; tax_bp: number; may: { manage: boolean };
};

export default function OrdersPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<OrderFormState | null>(null);
    const [badPrices, setBadPrices] = useState<string[]>([]);
    const [badTax, setBadTax] = useState(false);
    const label = (s: string) => t(`inv.po.status.${s}` as MessageKey);
    const numberOf = (o: Order) => (o.revision > 0 ? `${o.number} ${t('inv.po.rev', { revision: o.revision })}` : o.number);

    function openNew() {
        action.clear();
        setBadPrices([]);
        setBadTax(false);
        setForm(blankOrder(overview));
    }

    async function create() {
        if (form === null) return;
        const { bad, lines, taxBp } = orderBody(form, overview.currency);

        setBadPrices(bad);
        setBadTax(taxBp === null);
        if (bad.length > 0 || taxBp === null) return;
        const done = await action.run<{ order: { id: string } }>('/inventory/orders', {
            body: { supplier_id: form.supplier_id, location_id: form.location_id, expected_date: form.expected_date || null, tax_bp: taxBp, note: form.note || null, lines },
        });
        if (done !== null) router.visit(`/inventory/orders/${done.order.id}`);
    }

    const columns: DataGridColumn<Order>[] = [
        { id: 'number', label: t('inv.col.number'), value: (o) => numberOf(o), rowHeader: true },
        { id: 'supplier', label: t('inv.po.supplier'), value: (o) => o.supplier.code, searchText: (o) => `${o.supplier.code} ${o.supplier.name}`, filter: 'select', cell: (o) => o.supplier.name },
        { id: 'location', label: t('inv.col.location'), value: (o) => o.location.code, searchText: (o) => `${o.location.code} ${o.location.name}`, filter: 'select', cell: (o) => o.location.name, hidden: true },
        { id: 'expected', label: t('inv.po.expected'), value: (o) => o.expected_date ?? '', cell: (o) => (o.expected_date === null ? '—' : format.date(o.expected_date)) },
        { id: 'total', label: t('inv.req.total'), align: 'right', value: (o) => o.total_minor, cell: (o) => format.money(o.total_minor, overview.currency) },
        { id: 'state', label: t('inv.col.status'), value: (o) => o.status, filter: 'select', filterLabel: label, cell: (o) => <StatusBadge label={label(o.status)} tone={ORDER_TONE[o.status] ?? 'neutral'} /> },
        { id: 'actions', label: t('inv.col.actions'), cell: (o) => <Button onClick={() => router.visit(`/inventory/orders/${o.id}`)} size="sm" type="button" variant="outline">{t('inv.po.open')}</Button> },
    ];

    return (
        <InventoryShell actions={overview.may.manage ? <Button onClick={openNew} type="button">{t('inv.po.new')}</Button> : undefined} description={t('inv.po.description')} title={t('inv.po.title')} wide>
            <div className="max-w-xs">
                <Select aria-label={t('inv.col.status')} onChange={(e) => router.get('/inventory/orders', e.target.value ? { status: e.target.value } : {}, { preserveScroll: true })} searchable={false} value={status}>
                    <option value="">{t('inv.po.allStatuses')}</option>
                    {ORDER_STATUSES.map((s) => <option key={s} value={s}>{label(s)}</option>)}
                </Select>
            </div>

            <DataGrid caption={t('inv.po.title')} columns={columns} empty={<EmptyState title={t('inv.po.empty')} />} getRowId={(o) => o.id} id="inv.orders" rows={overview.orders} testId="orders" />

            <Dialog
                className="w-[min(64rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void create()} type="button">{t('inv.po.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('inv.po.new')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-4">
                        <p className="text-sm text-muted-foreground">{t('inv.po.hint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <OrderHeaderFields badTax={badTax} fieldError={action.fieldError} form={form} locations={overview.locations} onChange={(patch) => setForm({ ...form, ...patch })} suppliers={overview.suppliers} />
                        <OrderLineFields badPrices={badPrices} currency={overview.currency} departments={overview.departments} fieldError={action.fieldError} form={form} items={overview.items} onChange={setForm} requestLines={overview.request_lines} />
                    </div>
                )}
            </Dialog>
        </InventoryShell>
    );
}
