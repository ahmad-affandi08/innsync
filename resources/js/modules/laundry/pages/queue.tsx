import { Link } from '@inertiajs/react';
import { ArrowRight, ClipboardList, PackageCheck, Send } from 'lucide-react';

import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { RowAction } from '@/components/ui/row-action';
import { StatusBadge } from '@/components/ui/status-badge';
import { LaundryShell } from '@/modules/laundry/components/laundry-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Order = { id: string; number: string; barcode: string; room_number: string | null; guest_name: string | null; status: string; express: boolean; promised_at: string; overdue: boolean; items: number; has_discrepancy: boolean; lock_version: number };

export const statusTone: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = { sent: 'neutral', received: 'info', washing: 'info', drying: 'info', ironing: 'info', ready: 'success', delivered: 'neutral', cancelled: 'neutral' };

/** The order of the work, from hand-over to the guest's room. */
export const STEPS = ['sent', 'received', 'washing', 'drying', 'ironing', 'ready', 'delivered'] as const;
/** Steps a person moves on with one touch; the others need the order page (a count, a charge to confirm, who took it). */
export const NEXT: Record<string, string> = { received: 'washing', washing: 'drying', drying: 'ironing' };

export default function LaundryQueuePage({ may, orders }: { may: { process: boolean; deliver: boolean }; orders: Order[] }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const page = (o: Order) => `/laundry/orders/${o.id}`;

    function next(o: Order) {
        if (may.process && NEXT[o.status] !== undefined) {
            return <RowAction disabled={action.busy} icon={ArrowRight} label={t('ldy.order.advanceTo', { step: t(`ldy.status.${NEXT[o.status]}` as 'ldy.status.washing') })} onClick={() => void action.run(`${page(o)}/advance`, { body: { lock_version: o.lock_version }, reload: ['orders'] })} tone="success" />;
        }
        if (may.process && o.status === 'sent') return <RowAction href={page(o)} icon={ClipboardList} label={t('ldy.order.count')} tone="success" />;
        if (may.process && o.status === 'ironing') return <RowAction href={page(o)} icon={PackageCheck} label={t('ldy.order.ready')} tone="success" />;
        if (may.deliver && o.status === 'ready') return <RowAction href={page(o)} icon={Send} label={t('ldy.order.deliver')} tone="success" />;

        return null;
    }

    return (
        <LaundryShell description={t('ldy.queue.description')} title={t('ldy.queue.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {orders.length === 0 ? <EmptyState title={t('ldy.queue.empty')} /> : (
                <ul className="list-panel divide-y divide-border text-sm">
                    {orders.map((o) => (
                        <li className="flex flex-wrap items-center justify-between gap-2 py-3" key={o.id}>
                            <div className="flex flex-col gap-1">
                                <Link className="font-medium underline-offset-2 hover:underline" href={`/laundry/orders/${o.id}`}>{o.number} · {o.room_number}{o.guest_name !== null ? ` (${o.guest_name})` : ''}</Link>
                                <span className="text-xs text-muted-foreground">{t('ldy.queue.row', { items: o.items, when: format.instant(o.promised_at) })}</span>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {o.express ? <StatusBadge label={t('ldy.flag.express')} tone="warning" /> : null}
                                {o.overdue ? <StatusBadge label={t('ldy.flag.overdue')} tone="danger" /> : null}
                                {o.has_discrepancy ? <StatusBadge label={t('ldy.flag.discrepancy')} tone="warning" /> : null}
                                <StatusBadge label={t(`ldy.status.${o.status}` as 'ldy.status.sent')} tone={statusTone[o.status] ?? 'neutral'} />
                                {next(o)}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </LaundryShell>
    );
}
