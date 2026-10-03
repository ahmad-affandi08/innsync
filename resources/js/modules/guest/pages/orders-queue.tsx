import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { GuestStaffShell } from '@/modules/guest/components/guest-staff-shell';
import type { QueueOrder, QueueOverview } from '@/modules/guest/lib/guest';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<string, StatusTone> = { none: 'neutral', pending: 'pending', verified: 'success', rejected: 'danger' };

/** What guests ordered from their phones, and the charges to a room that wait for a person to verify the guest. */
export default function OrdersQueuePage({ overview }: { overview: QueueOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [decide, setDecide] = useState<{ order: QueueOrder; accept: boolean; note: string } | null>(null);
    const money = (minor: number) => format.money(minor, 'IDR');
    const reload = ['overview'];
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    async function submit() {
        if (decide === null) return;
        const done = await action.run(`/guest/orders/${decide.order.id}/decide`, { body: { accept: decide.accept, note: decide.note.trim() === '' ? null : decide.note.trim(), lock_version: decide.order.lock_version }, reload });
        if (done !== null) setDecide(null);
    }

    const columns: DataGridColumn<QueueOrder>[] = [
        { id: 'placed', label: t('guest.queue.colWhen'), value: (o) => o.placed_at, cell: (o) => format.instant(o.placed_at) },
        { id: 'where', label: t('guest.queue.colWhere'), value: (o) => o.label, rowHeader: true, cell: (o) => <span>{o.label}<span className="block text-xs text-muted-foreground">{o.bill_number} · {t('guest.queue.lines', { count: o.lines })}</span></span> },
        { id: 'guest', label: t('guest.queue.colGuest'), value: (o) => o.guest_name ?? '', cell: (o) => (o.guest_name === null ? '—' : <span>{o.guest_name}{o.room_number !== null ? <span className="block text-xs text-muted-foreground">{t('guest.queue.room', { room: o.room_number })}</span> : null}</span>) },
        { id: 'total', label: t('guest.queue.colTotal'), align: 'right', value: (o) => o.subtotal_minor, cell: (o) => money(o.subtotal_minor) },
        { id: 'pay', label: t('guest.queue.colPay'), value: (o) => o.payment, cell: (o) => <span>{t(`guest.pay.${o.payment}` as MessageKey)}{o.room_charge !== 'none' ? <span className="mt-1 block"><StatusBadge label={t(`guest.queue.charge.${o.room_charge}` as MessageKey)} tone={TONE[o.room_charge] ?? 'neutral'} /></span> : null}{o.verify_note !== null ? <span className="block text-xs text-muted-foreground">{o.verify_note}</span> : null}</span> },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (o) => (
                <span className="flex flex-wrap gap-2">
                    {o.room_charge === 'pending' ? <><Button onClick={() => { action.clear(); setDecide({ order: o, accept: true, note: '' }); }} size="sm" type="button">{t('guest.queue.verify')}</Button><Button onClick={() => { action.clear(); setDecide({ order: o, accept: false, note: '' }); }} size="sm" type="button" variant="outline">{t('guest.queue.refuse')}</Button></> : null}
                    <Button asChild size="sm" variant="outline"><Link href={`/fnb/bills/${o.bill_id}`}>{t('guest.queue.openBill')}</Link></Button>
                </span>
            ),
        },
    ];

    return (
        <GuestStaffShell description={t('guest.queue.description')} title={t('guest.queue.title')} wide>
            {action.error !== null && decide === null ? failure : null}
            {overview.pending > 0 ? <Alert title={t('guest.queue.pending', { count: overview.pending })} tone="warning" /> : null}
            <DataGrid caption={t('guest.queue.title')} columns={columns} empty={<EmptyState illustration="coffee" title={t('guest.queue.none')} />} getRowId={(o) => o.id} id="guest.orders" rows={overview.orders} testId="guest-orders" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setDecide(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={decide !== null && !decide.accept && decide.note.trim() === ''} loading={action.busy} onClick={() => void submit()} type="button">{decide?.accept ? t('guest.queue.verify') : t('guest.queue.refuse')}</Button></>}
                onClose={() => setDecide(null)}
                open={decide !== null}
                title={decide === null ? '' : decide.accept ? t('guest.queue.verifyTitle', { number: decide.order.bill_number }) : t('guest.queue.refuseTitle', { number: decide.order.bill_number })}
            >
                {decide !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{decide.accept ? t('guest.queue.verifyHint', { guest: decide.order.guest_name ?? '', room: decide.order.room_number ?? '' }) : t('guest.queue.refuseHint')}</p>
                        {failure}
                        <FormField error={action.fieldError('note')} field="note" label={t('guest.queue.note')}><Input maxLength={200} onChange={(e) => setDecide({ ...decide, note: e.target.value })} value={decide.note} /></FormField>
                    </div>
                )}
            </Dialog>
        </GuestStaffShell>
    );
}
