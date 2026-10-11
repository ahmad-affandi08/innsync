import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Entry = { id: string; guest_name: string; phone: string | null; email: string | null; room_type: string; room_type_id: string; arrival: string; departure: string; adults: number; children: number; note: string | null; status: string; available: boolean; reservation_id: string | null; cancel_reason: string | null; lock_version: number };
type Overview = { today: string; types: { id: string; code: string; name: string; max_adults: number; max_children: number }[]; plans: { id: string; code: string; name: string }[]; entries: Entry[]; available_now: number; may_write: boolean };

const TONE: Record<string, StatusTone> = { waiting: 'pending', offered: 'info', booked: 'success', cancelled: 'neutral', lapsed: 'warning' };

/** The waiting list: guests who asked for a room type on dates that were sold out. The desk sees which can be sold now, calls the guest and books through the ordinary reservation rules. */
export default function WaitlistPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const blank = { guest: '', phone: '', email: '', type: overview.types[0]?.id ?? '', arrival: overview.today, departure: '', adults: '2', children: '0', note: '' };
    const [add, setAdd] = useState<typeof blank | null>(null);
    const [book, setBook] = useState<{ entry: Entry; plan: string; status: string; key: string } | null>(null);
    const [cancel, setCancel] = useState<{ entry: Entry; reason: string } | null>(null);
    const reload = ['overview'];

    async function save() {
        if (add === null) return;
        const done = await action.run('/front-office/waitlist', { body: { guest_name: add.guest, phone: add.phone || null, email: add.email || null, room_type_id: add.type, arrival: add.arrival, departure: add.departure, adults: Number(add.adults) || 1, children: Number(add.children) || 0, note: add.note || null }, reload });
        if (done !== null) setAdd(null);
    }

    async function doBook() {
        if (book === null) return;
        const done = await action.run<{ reservation_id: string }>(`/front-office/waitlist/${book.entry.id}/book`, { idempotencyKey: book.key, body: { rate_plan_id: book.plan, status: book.status, lock_version: book.entry.lock_version }, reload });
        if (done !== null) {
            setBook(null);
            router.visit(`/front-office/reservations/${done.reservation_id}`);
        }
    }

    async function doCancel() {
        if (cancel === null) return;
        const done = await action.run(`/front-office/waitlist/${cancel.entry.id}/cancel`, { body: { reason: cancel.reason.trim(), lock_version: cancel.entry.lock_version }, reload });
        if (done !== null) setCancel(null);
    }

    const live = (e: Entry) => e.status === 'waiting' || e.status === 'offered';
    const columns: DataGridColumn<Entry>[] = [
        { id: 'guest', label: t('fo.wl.col.guest'), value: (e) => e.guest_name, rowHeader: true, cell: (e) => <span>{e.guest_name}<span className="block text-xs text-muted-foreground">{[e.phone, e.email].filter((x) => x !== null).join(' · ')}</span></span> },
        { id: 'type', label: t('fo.wl.col.type'), value: (e) => e.room_type, filter: 'select', filterLabel: (v) => v },
        { id: 'arrival', label: t('fo.res.arrival'), value: (e) => e.arrival, searchText: (e) => `${e.arrival} ${format.date(e.arrival)}`, cell: (e) => format.date(e.arrival) },
        { id: 'departure', label: t('fo.res.departure'), value: (e) => e.departure, searchText: (e) => `${e.departure} ${format.date(e.departure)}`, cell: (e) => format.date(e.departure) },
        { id: 'guests', label: t('fo.wl.col.guests'), align: 'right', value: (e) => e.adults + e.children, cell: (e) => `${e.adults}+${e.children}` },
        { id: 'status', label: t('fo.wl.col.status'), value: (e) => e.status, filter: 'select', filterLabel: (v) => t(`fo.wl.status.${v}` as 'fo.wl.status.waiting'), cell: (e) => <span className="flex flex-wrap items-center gap-2"><StatusBadge label={t(`fo.wl.status.${e.status}` as 'fo.wl.status.waiting')} tone={TONE[e.status] ?? 'neutral'} />{e.available ? <StatusBadge label={t('fo.wl.canSell')} tone="success" /> : null}</span> },
        { id: 'note', label: t('fo.wl.col.note'), value: (e) => e.cancel_reason ?? e.note ?? '', cell: (e) => e.cancel_reason ?? e.note ?? '' },
        {
            id: 'actions', label: '', value: () => '', sortable: false,
            cell: (e) => (overview.may_write && live(e)
                ? <span className="flex flex-wrap gap-2">
                    {e.status === 'waiting' ? <Button disabled={action.busy} onClick={() => void action.run(`/front-office/waitlist/${e.id}/offer`, { body: { lock_version: e.lock_version }, reload })} size="sm" type="button" variant="outline">{t('fo.wl.called')}</Button> : null}
                    <Button disabled={!e.available} onClick={() => { action.clear(); setBook({ entry: e, plan: overview.plans[0]?.id ?? '', status: 'confirmed', key: newIdempotencyKey() }); }} size="sm" type="button">{t('fo.wl.book')}</Button>
                    <Button onClick={() => { action.clear(); setCancel({ entry: e, reason: '' }); }} size="sm" type="button" variant="outline">{t('fo.wl.cancel')}</Button>
                </span>
                : null),
        },
    ];

    return (
        <FrontOfficeShell description={t('fo.wl.description')} title={t('fo.wl.title')} wide>
            {overview.available_now > 0 ? <Alert title={t('fo.wl.nowTitle', { count: overview.available_now })} tone="success">{t('fo.wl.now')}</Alert> : null}
            {action.error !== null && add === null && book === null && cancel === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {overview.may_write ? <div className="print:hidden"><Button onClick={() => { action.clear(); setAdd({ ...blank }); }} type="button">{t('fo.wl.add')}</Button></div> : null}
            <DataGrid caption={t('fo.wl.title')} columns={columns} empty={<EmptyState title={t('fo.wl.none')} />} getRowId={(e) => e.id} id="fo.waitlist" rows={overview.entries} testId="waitlist" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAdd(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void save()} type="button">{t('fo.wl.add')}</Button></>}
                onClose={() => setAdd(null)}
                open={add !== null}
                title={t('fo.wl.add')}
            >
                {add !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={action.fieldError('guest_name')} field="guest_name" label={t('fo.wl.col.guest')}><Input maxLength={150} onChange={(e) => setAdd({ ...add, guest: e.target.value })} value={add.guest} /></FormField>
                        <FormField error={action.fieldError('room_type_id')} field="room_type_id" label={t('fo.wl.col.type')}><Select onChange={(e) => setAdd({ ...add, type: e.target.value })} value={add.type}>{overview.types.map((ty) => <option key={ty.id} value={ty.id}>{ty.code} · {ty.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('phone')} field="phone" label={t('fo.wl.phone')}><Input inputMode="tel" maxLength={30} onChange={(e) => setAdd({ ...add, phone: e.target.value })} value={add.phone} /></FormField>
                        <FormField error={action.fieldError('email')} field="email" hint={t('fo.wl.contactHint')} label={t('fo.wl.email')}><Input inputMode="email" maxLength={120} onChange={(e) => setAdd({ ...add, email: e.target.value })} value={add.email} /></FormField>
                        <FormField error={action.fieldError('arrival')} field="arrival" label={t('fo.res.arrival')}><DatePicker min={overview.today} onChange={(e) => setAdd({ ...add, arrival: e.target.value })} value={add.arrival} /></FormField>
                        <FormField error={action.fieldError('departure')} field="departure" label={t('fo.res.departure')}><DatePicker min={add.arrival} onChange={(e) => setAdd({ ...add, departure: e.target.value })} value={add.departure} /></FormField>
                        <FormField error={action.fieldError('adults')} field="adults" label={t('fo.wl.adults')}><Input inputMode="numeric" maxLength={2} onChange={(e) => setAdd({ ...add, adults: e.target.value })} value={add.adults} /></FormField>
                        <FormField error={action.fieldError('children')} field="children" label={t('fo.wl.children')}><Input inputMode="numeric" maxLength={2} onChange={(e) => setAdd({ ...add, children: e.target.value })} value={add.children} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('note')} field="note" label={t('fo.wl.col.note')}><Input maxLength={300} onChange={(e) => setAdd({ ...add, note: e.target.value })} value={add.note} /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setBook(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={book?.plan === ''} loading={action.busy} onClick={() => void doBook()} type="button">{t('fo.wl.book')}</Button></>}
                onClose={() => setBook(null)}
                open={book !== null}
                title={t('fo.wl.bookTitle')}
            >
                {book !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{t('fo.wl.bookBody', { guest: book.entry.guest_name, type: book.entry.room_type, from: format.date(book.entry.arrival), to: format.date(book.entry.departure) })}</p>
                        <FormField error={action.fieldError('rate_plan_id')} field="rate_plan_id" label={t('fo.wl.plan')}><Select onChange={(e) => setBook({ ...book, plan: e.target.value })} value={book.plan}>{overview.plans.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('status')} field="status" label={t('fo.wl.bookAs')}><Select onChange={(e) => setBook({ ...book, status: e.target.value })} value={book.status}><option value="confirmed">{t('fo.wl.confirmed')}</option><option value="tentative">{t('fo.wl.tentative')}</option></Select></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setCancel(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={cancel?.reason.trim() === ''} loading={action.busy} onClick={() => void doCancel()} type="button">{t('fo.wl.cancel')}</Button></>}
                onClose={() => setCancel(null)}
                open={cancel !== null}
                title={t('fo.wl.cancelTitle')}
            >
                {cancel !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('reason')} field="reason" hint={t('fo.wl.cancelHint')} label={t('fo.lock.reason')}><Input maxLength={200} onChange={(e) => setCancel({ ...cancel, reason: e.target.value })} value={cancel.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
