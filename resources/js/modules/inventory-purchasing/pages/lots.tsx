import { router } from '@inertiajs/react';

import { Alert } from '@/components/ui/alert';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Lot = {
    id: string; item: { code: string; name: string; unit: string; department: string }; location: { code: string; name: string }; lot_number: string | null; expires_on: string | null; days_left: number | null;
    status: 'expired' | 'expiring' | 'ok' | 'no_expiry'; remaining_milli: number; received_milli: number;
};
type Overview = { lots: Lot[]; counts: { expired: number; expiring: number }; warn_days: number; business_date: string; department: string | null };

const TONE: Record<Lot['status'], StatusTone> = { expired: 'danger', expiring: 'warning', ok: 'success', no_expiry: 'neutral' };
const qty = (milli: number) => (milli / 1000).toLocaleString(undefined, { maximumFractionDigits: 3 });

/** The batches that hold stock, earliest expiry first, with the ones that are expired or about to expire marked (FR-INV-008, FR-KIT-009). */
export default function LotsPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const label = (s: string) => t(`inv.lot.status.${s}` as MessageKey);
    const go = (next: string) => router.get('/inventory/lots', { ...(next === '' ? {} : { status: next }), ...(overview.department === null ? {} : { department: overview.department }) }, { preserveScroll: true });

    const columns: DataGridColumn<Lot>[] = [
        { id: 'item', label: t('inv.col.item'), value: (l) => l.item.code, searchText: (l) => `${l.item.code} ${l.item.name}`, rowHeader: true, cell: (l) => <><span className="font-medium">{l.item.code}</span> <span className="text-muted-foreground">{l.item.name}</span></> },
        { id: 'location', label: t('inv.col.location'), value: (l) => l.location.code, filter: 'select', cell: (l) => l.location.name },
        { id: 'lot', label: t('inv.lot.number'), value: (l) => l.lot_number ?? '', cell: (l) => l.lot_number ?? '—' },
        { id: 'expires', label: t('inv.lot.expires'), value: (l) => l.expires_on ?? '9999-12-31', cell: (l) => (l.expires_on === null ? '—' : format.date(l.expires_on)) },
        { id: 'days', label: t('inv.lot.daysLeft'), align: 'right', value: (l) => l.days_left ?? 99999, cell: (l) => (l.days_left === null ? '—' : l.days_left) },
        { id: 'remaining', label: t('inv.lot.remaining'), align: 'right', value: (l) => l.remaining_milli, cell: (l) => `${qty(l.remaining_milli)} ${l.item.unit}` },
        { id: 'status', label: t('inv.col.status'), value: (l) => l.status, filter: 'select', filterLabel: label, cell: (l) => <StatusBadge label={label(l.status)} tone={TONE[l.status]} /> },
    ];

    return (
        <InventoryShell description={t('inv.lot.description', { days: overview.warn_days })} title={t('inv.lot.title')} wide>
            {overview.counts.expired > 0 ? <Alert title={t('inv.lot.expiredCount', { count: overview.counts.expired })} tone="danger" /> : null}
            {overview.counts.expiring > 0 ? <Alert title={t('inv.lot.expiringCount', { count: overview.counts.expiring, days: overview.warn_days })} tone="warning" /> : null}
            <div className="max-w-xs">
                <Select aria-label={t('inv.col.status')} onChange={(e) => go(e.target.value)} searchable={false} value={status}>
                    <option value="">{t('inv.lot.all')}</option>
                    {(['expired', 'expiring', 'ok', 'no_expiry'] as const).map((s) => <option key={s} value={s}>{label(s)}</option>)}
                </Select>
            </div>
            <DataGrid caption={t('inv.lot.title')} columns={columns} empty={<EmptyState title={t('inv.lot.empty')} />} getRowId={(l) => l.id} id="inv.lots" rows={overview.lots} testId="lots" />
        </InventoryShell>
    );
}
