import { Head, Link, router } from '@inertiajs/react';
import { useEffect } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { GuestShell } from '@/modules/guest/components/guest-shell';
import { qrLabel } from '@/modules/guest/lib/qr';
import type { GuestOrders } from '@/modules/guest/lib/guest';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<string, StatusTone> = { new: 'pending', preparing: 'info', ready: 'success', served: 'success', voided: 'neutral', removed: 'neutral', pending: 'pending' };

/** The orders of this session and how far each dish is; nothing of any other guest. The page looks again by itself while something is still being made. */
export default function OrdersPage({ view }: { view: GuestOrders }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const busy = view.orders.some((o) => o.in_progress);

    useEffect(() => {
        if (!busy) return;
        const timer = window.setInterval(() => router.reload({ only: ['view'] }), 10000);

        return () => window.clearInterval(timer);
    }, [busy]);

    return (
        <>
            <Head title={t('guest.orders.title')} />
            <GuestShell hotel={view.hotel} subtitle={qrLabel(view.label, t)} title={t('guest.orders.title')}>
                <Button asChild size="sm" variant="outline"><Link href="/g/menu">{t('guest.orders.back')}</Link></Button>
                {view.orders.length === 0 ? <EmptyState illustration="coffee" title={t('guest.orders.none')} /> : null}
                {view.orders.map((o) => (
                    <section aria-label={o.bill_number} className="flex flex-col gap-2 border border-border bg-surface p-3" data-testid="guest-order" key={o.id}>
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="font-semibold">{t('guest.orders.number', { number: o.bill_number })}</h2>
                            <span className="text-xs text-muted-foreground">{format.instant(o.placed_at)}</span>
                        </div>
                        {o.delivery !== null ? <StatusBadge label={t(`guest.delivery.${o.delivery}` as MessageKey)} tone={o.delivery === 'delivered' ? 'success' : 'info'} /> : null}
                        <ul className="flex flex-col divide-y divide-border text-sm">
                            {o.lines.map((l, i) => (
                                <li className="flex items-center justify-between gap-2 py-1.5" key={i}>
                                    <span>{l.quantity} × {l.name}{l.variant !== null ? ` (${l.variant})` : ''}</span>
                                    <StatusBadge label={t(`guest.line.${l.status}` as MessageKey)} tone={TONE[l.status] ?? 'neutral'} />
                                </li>
                            ))}
                        </ul>
                        <p className="flex justify-between text-sm font-medium"><span>{t('guest.cart.subtotal')}</span><span className="tabular-nums">{format.money(o.subtotal_minor, o.currency ?? 'IDR')}</span></p>
                        {o.room_charge === 'pending' ? <Alert title={t('guest.orders.roomPending')} tone="info" /> : null}
                        {o.room_charge === 'verified' ? <Alert title={t('guest.orders.roomVerified')} tone="success" /> : null}
                        {o.room_charge === 'rejected' ? <Alert title={t('guest.orders.roomRejected')} tone="warning" /> : null}
                        {o.payment === 'qris' ? <p className="text-xs text-muted-foreground">{t('guest.menu.qrisAtCashier')}</p> : null}
                    </section>
                ))}
            </GuestShell>
        </>
    );
}
