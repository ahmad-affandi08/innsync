import { Head, router } from '@inertiajs/react';

import { Alert } from '@/components/ui/alert';
import { EmptyState } from '@/components/ui/empty-state';
import { GuestShell } from '@/modules/guest/components/guest-shell';
import { qrLabel } from '@/modules/guest/lib/qr';
import { StayProof } from '@/modules/guest/components/stay-proof';
import type { GuestBill } from '@/modules/guest/lib/guest';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

/** The bill so far, as it would be handed over at the desk: what was charged by outlet, what was paid and what is owed. */
export default function BillPage({ view }: { view: GuestBill }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const bill = view.bill;
    const money = (minor: number) => format.money(minor, bill?.currency ?? 'IDR');

    return (
        <>
            <Head title={t('guest.bill.title')} />
            <GuestShell hotel={view.hotel} subtitle={qrLabel(view.label, t)} title={t('guest.bill.title')}>
                {!view.verified ? (
                    <>
                        <Alert title={t('guest.help.needsProof')} tone="info" />
                        <StayProof locked={view.locked} onDone={() => router.reload({ only: ['view'] })} />
                    </>
                ) : bill === null ? <EmptyState illustration="checklist" title={t('guest.bill.none')} /> : (
                    <div className="flex flex-col gap-3" data-testid="guest-bill">
                        <p className="text-xs text-muted-foreground">{t('guest.bill.hint')}</p>
                        {bill.outlets.map((o) => (
                            <section aria-label={t(`guest.bill.outlet.${o.outlet}` as MessageKey)} className="border border-border bg-surface" key={o.outlet}>
                                <h2 className="flex justify-between border-b border-border px-3 py-2 text-sm font-semibold"><span>{t(`guest.bill.outlet.${o.outlet}` as MessageKey)}</span><span className="tabular-nums">{money(o.total_minor)}</span></h2>
                                <ul className="divide-y divide-border text-sm">
                                    {o.lines.map((l, i) => <li className="flex justify-between gap-2 px-3 py-1.5" key={i}><span>{format.date(l.date)} · {l.description}</span><span className="tabular-nums">{money(l.total_minor)}</span></li>)}
                                </ul>
                            </section>
                        ))}
                        {bill.payments.length > 0 ? (
                            <section aria-label={t('guest.bill.payments')} className="border border-border bg-surface">
                                <h2 className="border-b border-border px-3 py-2 text-sm font-semibold">{t('guest.bill.payments')}</h2>
                                <ul className="divide-y divide-border text-sm">
                                    {bill.payments.map((p, i) => <li className="flex justify-between gap-2 px-3 py-1.5" key={i}><span>{format.date(p.date)} · {p.method}</span><span className="tabular-nums">{money(p.amount_minor)}</span></li>)}
                                </ul>
                            </section>
                        ) : null}
                        <dl className="flex flex-col gap-1 border border-border bg-surface p-3 text-sm">
                            <div className="flex justify-between"><dt>{t('guest.bill.charged')}</dt><dd className="tabular-nums">{money(bill.total_minor)}</dd></div>
                            <div className="flex justify-between"><dt>{t('guest.bill.paid')}</dt><dd className="tabular-nums">{money(bill.paid_minor)}</dd></div>
                            <div className="flex justify-between border-t border-border pt-2 font-semibold"><dt>{t('guest.bill.balance')}</dt><dd className="tabular-nums" data-testid="guest-balance">{money(bill.balance_minor)}</dd></div>
                        </dl>
                    </div>
                )}
            </GuestShell>
        </>
    );
}
