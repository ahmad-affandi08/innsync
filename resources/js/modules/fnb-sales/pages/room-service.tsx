import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { TimeInput } from '@/components/ui/time-input';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FnbShell } from '@/modules/fnb-sales/components/fnb-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Order = { id: string; bill_id: string; bill_number: string; room: { id: string; number: string }; guest_name: string | null; promised_at: string; status: 'ordered' | 'on_the_way' | 'delivered' | 'cancelled'; late: boolean; late_minutes: number; delivered_at: string | null; lock_version: number; next: 'on_the_way' | 'delivered' | null };
type Board = { now: string; orders: Order[]; outlets: { id: string; code: string; name: string }[]; rooms: { id: string; number: string; guest_name: string }[] };

const TONE: Record<Order['status'], StatusTone> = { ordered: 'pending', on_the_way: 'info', delivered: 'success', cancelled: 'neutral' };

/** Room service: the orders for the rooms, the time promised and how far each delivery is. */
export default function RoomServicePage({ board }: { board: Board }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [add, setAdd] = useState<{ outletId: string; roomId: string; time: string; covers: string; note: string } | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const reload = ['board'];
    const clock = (iso: string) => new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit', timeZone: format.timeZone ?? 'UTC' }).format(new Date(iso));

    async function place() {
        if (add === null) return;
        const result = await action.run('/fnb/room-service', { idempotencyKey: newIdempotencyKey(), body: { outlet_id: add.outletId, room_id: add.roomId, promised_time: add.time, covers: Number(add.covers), note: add.note.trim() === '' ? null : add.note.trim() }, reload });

        if (result !== null) setAdd(null);
    }

    const columns: DataGridColumn<Order>[] = [
        { id: 'promised', label: t('fnb.rs.promised'), value: (o) => o.promised_at, rowHeader: true, cell: (o) => <span>{clock(o.promised_at)}{o.late ? <span className="block text-xs text-danger">{t('fnb.rs.late', { n: o.late_minutes })}</span> : null}</span> },
        { id: 'room', label: t('fnb.mini.room'), value: (o) => o.room.number, cell: (o) => <span>{o.room.number}<span className="block text-xs text-muted-foreground">{o.guest_name ?? ''}</span></span> },
        { id: 'bill', label: t('fnb.rs.bill'), value: (o) => o.bill_number, cell: (o) => <a className="underline" href={`/fnb/bills/${o.bill_id}`}>{o.bill_number}</a> },
        { id: 'status', label: t('hr.col.status'), value: (o) => o.status, filter: 'select', filterLabel: (v) => t(`fnb.rs.status.${v}` as MessageKey), cell: (o) => <StatusBadge label={t(`fnb.rs.status.${o.status}` as MessageKey)} tone={TONE[o.status]} /> },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (o) => (o.next === null ? null : <Button disabled={action.busy} onClick={() => void action.run(`/fnb/room-service/${o.id}/status`, { body: { status: o.next, lock_version: o.lock_version }, reload })} size="sm" type="button">{t(`fnb.rs.mark.${o.next}` as MessageKey)}</Button>) },
    ];

    return (
        <FnbShell actions={<Button disabled={board.outlets.length === 0 || board.rooms.length === 0} onClick={() => { action.clear(); setAdd({ outletId: board.outlets[0]?.id ?? '', roomId: board.rooms[0]?.id ?? '', time: '', covers: '1', note: '' }); }} type="button">{t('fnb.rs.new')}</Button>} description={t('fnb.rs.description')} title={t('fnb.rs.title')} wide>
            {action.error !== null && add === null ? failure : null}
            {board.outlets.length === 0 ? <p className="text-sm text-muted-foreground">{t('fnb.rs.noOutlet')}</p> : null}
            <DataGrid caption={t('fnb.rs.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('fnb.rs.none')} />} getRowId={(o) => o.id} id="fnb.rs" rows={board.orders} testId="fnb-rs" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAdd(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={add === null || add.time === '' || add.roomId === '' || add.outletId === ''} loading={action.busy} onClick={() => void place()} type="button">{t('fnb.rs.place')}</Button></>}
                onClose={() => setAdd(null)}
                open={add !== null}
                title={t('fnb.rs.new')}
            >
                {add !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('outlet_id')} field="outlet_id" label={t('fnb.pos.outlet')}><Select onChange={(e) => setAdd({ ...add, outletId: e.target.value })} value={add.outletId}>{board.outlets.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('room_id')} field="room_id" label={t('fnb.mini.room')}><Select onChange={(e) => setAdd({ ...add, roomId: e.target.value })} value={add.roomId}>{board.rooms.map((r) => <option key={r.id} value={r.id}>{r.number} · {r.guest_name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('promised_time')} field="promised_time" hint={t('fnb.rs.timeHint')} label={t('fnb.rs.promised')}><TimeInput onChange={(e) => setAdd({ ...add, time: e.target.value })} value={add.time} /></FormField>
                        <FormField error={action.fieldError('covers')} field="covers" label={t('fnb.rs.covers')}><Input inputMode="numeric" onChange={(e) => setAdd({ ...add, covers: e.target.value.replace(/\D/g, '') })} value={add.covers} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('note')} field="note" label={t('fnb.rs.note')}><Input maxLength={200} onChange={(e) => setAdd({ ...add, note: e.target.value })} value={add.note} /></FormField></div>
                    </div>
                )}
            </Dialog>
        </FnbShell>
    );
}
