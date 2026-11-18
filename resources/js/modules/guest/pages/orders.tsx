import { Head, Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { GuestShell } from '@/modules/guest/components/guest-shell';
import { qrLabel } from '@/modules/guest/lib/qr';
import type { GuestOrders } from '@/modules/guest/lib/guest';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';
import type { MessageKey } from '@/locales/en/index';

const STEPS = ['new', 'preparing', 'ready', 'served'] as const;

/** How far the order is: as far as its slowest dish that is still on it (a cancelled one does not hold it back); delivered to the room is the end. */
function stage(o: GuestOrders['orders'][number]): number {
    if (o.delivery === 'delivered') return STEPS.length - 1;
    const steps = o.lines.map((l) => STEPS.indexOf(l.status as (typeof STEPS)[number])).filter((i) => i >= 0);

    return steps.length === 0 ? -1 : Math.min(...steps);
}

const TONE: Record<string, StatusTone> = { new: 'pending', preparing: 'info', ready: 'success', served: 'success', voided: 'neutral', removed: 'neutral', pending: 'pending' };

/** The orders of this session and how far each dish is; nothing of any other guest. The page looks again by itself while something is still being made. */
export default function OrdersPage({ view }: { view: GuestOrders }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const busy = view.orders.some((o) => o.in_progress);

    useEffect(() => {
        if (!busy) return;
        const timer = window.setInterval(() => { if (!document.hidden) router.reload({ only: ['view'] }); }, 10000);

        return () => window.clearInterval(timer);
    }, [busy]);

    return (
        <>
            <Head title={t('guest.orders.title')} />
            <GuestShell hotel={view.hotel} subtitle={qrLabel(view.label, t)} title={t('guest.orders.title')}>
                <Button asChild className="self-start" size="sm" variant="outline"><Link href="/g/menu">{t('guest.orders.back')}</Link></Button>
                {view.orders.length === 0 ? <EmptyState illustration="coffee" title={t('guest.orders.none')} /> : null}
                {view.orders.map((o) => (
                    <section aria-label={o.bill_number} className="flex flex-col gap-3 border border-border bg-surface p-4" data-testid="guest-order" key={o.id}>
                        <div className="flex items-center justify-between gap-2">
                            <h2 className="font-semibold">{t('guest.orders.number', { number: o.bill_number })}</h2>
                            <span className="text-xs text-muted-foreground">{format.instant(o.placed_at)}</span>
                        </div>
                        {stage(o) >= 0 ? (
                            <ol aria-label={t('guest.orders.progress')} className="grid grid-cols-4 gap-1 text-[11px]">
                                {STEPS.map((step, i) => (
                                    <li aria-current={i === stage(o) ? 'step' : undefined} className="flex flex-col items-center gap-1 text-center" key={step}>
                                        <span className={cn('grid size-7 place-items-center text-xs font-semibold', i <= stage(o) ? 'bg-brand text-white' : 'border border-border text-muted-foreground')}>{i < stage(o) ? <Check aria-hidden="true" className="size-4" /> : i + 1}</span>
                                        <span className={i === stage(o) ? 'font-semibold' : 'text-muted-foreground'}>{t(`guest.orders.step.${step}` as MessageKey)}</span>
                                    </li>
                                ))}
                            </ol>
                        ) : null}
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
