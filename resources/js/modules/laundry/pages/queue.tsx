import { Link } from '@inertiajs/react';

import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { LaundryShell } from '@/modules/laundry/components/laundry-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Order = { id: string; number: string; barcode: string; room_number: string | null; status: string; express: boolean; promised_at: string; overdue: boolean; items: number; has_discrepancy: boolean };

export const statusTone: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = { sent: 'neutral', received: 'info', washing: 'info', drying: 'info', ironing: 'info', ready: 'success', delivered: 'neutral', cancelled: 'neutral' };

export default function LaundryQueuePage({ orders }: { orders: Order[] }) {
    const { t } = useTranslation();
    const format = useFormatters();

    return (
        <LaundryShell description={t('ldy.queue.description')} title={t('ldy.queue.title')} wide>
            {orders.length === 0 ? <EmptyState title={t('ldy.queue.empty')} /> : (
                <ul className="divide-y divide-border border-y border-border text-sm">
                    {orders.map((o) => (
                        <li className="flex flex-wrap items-center justify-between gap-2 py-3" key={o.id}>
                            <div className="flex flex-col gap-1">
                                <Link className="font-medium underline-offset-2 hover:underline" href={`/laundry/orders/${o.id}`}>{o.number} · {o.room_number}</Link>
                                <span className="text-xs text-muted-foreground">{t('ldy.queue.row', { items: o.items, when: format.instant(o.promised_at) })}</span>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {o.express ? <StatusBadge label={t('ldy.flag.express')} tone="warning" /> : null}
                                {o.overdue ? <StatusBadge label={t('ldy.flag.overdue')} tone="danger" /> : null}
                                {o.has_discrepancy ? <StatusBadge label={t('ldy.flag.discrepancy')} tone="warning" /> : null}
                                <StatusBadge label={t(`ldy.status.${o.status}` as 'ldy.status.sent')} tone={statusTone[o.status] ?? 'neutral'} />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </LaundryShell>
    );
}
