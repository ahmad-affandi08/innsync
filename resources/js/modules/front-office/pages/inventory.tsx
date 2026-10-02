import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { ConfirmDialog, Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Block = { id: string; room_id: string; kind: string; from: string; to: string; reason: string };
type Hold = { id: string; room_type_id: string; from: string; to: string; rooms: number; reason: string; expires_at: string | null };
type Type = { id: string; code: string; name: string; allowance: number; lock_version: number };
type Room = { id: string; number: string; room_type_id: string };

const KINDS = ['out_of_order', 'out_of_service'] as const;

export default function InventoryPage({ blocks, holds, rooms, types }: { blocks: Block[]; holds: Hold[]; rooms: Room[]; types: Type[] }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['blocks', 'holds', 'types'];
    const [blockForm, setBlockForm] = useState<{ roomId: string; kind: string; from: string; to: string; reason: string } | null>(null);
    const [holdForm, setHoldForm] = useState<{ typeId: string; from: string; to: string; rooms: string; reason: string; expires: string } | null>(null);
    const [release, setRelease] = useState<{ kind: 'block' | 'hold'; id: string } | null>(null);
    const [reason, setReason] = useState('');
    const [oversold, setOversold] = useState<string[]>([]);
    const [allowances, setAllowances] = useState<Record<string, { rooms: string; reason: string; saved: boolean }>>({});
    const roomNumber = (id: string) => rooms.find((r) => r.id === id)?.number ?? '';
    const typeCode = (id: string) => types.find((x) => x.id === id)?.code ?? '';
    const error = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    function closeAll() {
        action.clear();
        setBlockForm(null);
        setHoldForm(null);
        setRelease(null);
        setReason('');
    }

    async function saveBlock() {
        if (blockForm === null) return;
        const done = await action.run<{ oversold_nights: string[] }>('/front-office/room-blocks', { body: { room_id: blockForm.roomId, kind: blockForm.kind, from: blockForm.from, to: blockForm.to, reason: blockForm.reason }, reload });
        if (done !== null) {
            setOversold(done.oversold_nights);
            closeAll();
        }
    }

    async function saveHold() {
        if (holdForm === null) return;
        const done = await action.run('/front-office/holds', { body: { room_type_id: holdForm.typeId, from: holdForm.from, to: holdForm.to, rooms: Number(holdForm.rooms), reason: holdForm.reason, expires_at: holdForm.expires || null }, reload });
        if (done !== null) closeAll();
    }

    async function doRelease() {
        if (release === null) return;
        const done = await action.run(release.kind === 'block' ? `/front-office/room-blocks/${release.id}/release` : `/front-office/holds/${release.id}/release`, { body: { reason }, reload });
        if (done !== null) closeAll();
    }

    async function saveAllowance(type: Type) {
        const form = allowances[type.id] ?? { rooms: String(type.allowance), reason: '', saved: false };
        const done = await action.run(`/front-office/overbooking/${type.id}`, { body: { rooms: Number(form.rooms), lock_version: type.lock_version, reason: form.reason }, reload });
        if (done !== null) setAllowances({ ...allowances, [type.id]: { ...form, reason: '', saved: true } });
    }

    const footer = (onSave: () => void) => (<>
        <Button disabled={action.busy} onClick={closeAll} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
        <Button loading={action.busy} onClick={onSave} type="button">{t('property.action.save')}</Button>
    </>);
    const reasonField = (value: string, set: (v: string) => void) => (
        <FormField field="reason" error={action.fieldError('reason')} hint={t('property.field.reasonHint')} label={t('property.field.reason')}><Input maxLength={500} onChange={(e) => set(e.target.value)} value={value} /></FormField>
    );

    return (
        <FrontOfficeShell description={t('fo.inv.description')} title={t('fo.inv.title')}>
            {blockForm === null && holdForm === null && release === null ? error : null}
            {oversold.length > 0 ? <Alert title={t('fo.inv.oversoldWarning', { nights: oversold.map((d) => format.date(d)).join(', ') })} tone="warning" /> : null}

            <section aria-labelledby="blocks-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="blocks-h">{t('fo.inv.blocks')}</h2>
                    <Button disabled={rooms.length === 0} onClick={() => { action.clear(); setOversold([]); setBlockForm({ roomId: '', kind: 'out_of_order', from: '', to: '', reason: '' }); }} size="sm" type="button">{t('fo.inv.block')}</Button>
                </div>
                {blocks.length === 0 ? <EmptyState title={t('fo.inv.blocksEmpty')} /> : (
                    <ul className="divide-y divide-border border-y border-border">{blocks.map((b) => (
                        <li className="flex flex-wrap items-center justify-between gap-3 py-3" key={b.id}>
                            <div><p className="text-sm font-medium">{roomNumber(b.room_id)} · {t(`fo.inv.kind.${b.kind}` as 'fo.inv.kind.out_of_order')}</p><p className="text-xs text-muted-foreground">{format.date(b.from)} – {format.date(b.to)} · {b.reason}</p></div>
                            <Button onClick={() => { action.clear(); setRelease({ kind: 'block', id: b.id }); }} size="sm" type="button" variant="outline">{t('fo.inv.release')}</Button>
                        </li>
                    ))}</ul>
                )}
            </section>

            <section aria-labelledby="holds-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="holds-h">{t('fo.inv.holds')}</h2>
                    <Button disabled={types.length === 0} onClick={() => { action.clear(); setHoldForm({ typeId: '', from: '', to: '', rooms: '1', reason: '', expires: '' }); }} size="sm" type="button">{t('fo.inv.hold')}</Button>
                </div>
                {holds.length === 0 ? <EmptyState title={t('fo.inv.holdsEmpty')} /> : (
                    <ul className="divide-y divide-border border-y border-border">{holds.map((h) => (
                        <li className="flex flex-wrap items-center justify-between gap-3 py-3" key={h.id}>
                            <div><p className="text-sm font-medium">{t('fo.inv.holdRow', { rooms: h.rooms, type: typeCode(h.room_type_id) })}</p><p className="text-xs text-muted-foreground">{format.date(h.from)} – {format.date(h.to)} · {h.reason}{h.expires_at ? ` · ${format.instant(h.expires_at)}` : ''}</p></div>
                            <Button onClick={() => { action.clear(); setRelease({ kind: 'hold', id: h.id }); }} size="sm" type="button" variant="outline">{t('fo.inv.release')}</Button>
                        </li>
                    ))}</ul>
                )}
            </section>

            <section aria-labelledby="ob-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="ob-h">{t('fo.inv.allowance')}</h2>
                <p className="text-xs text-muted-foreground">{t('fo.inv.allowanceHint')}</p>
                {types.map((type) => {
                    const form = allowances[type.id] ?? { rooms: String(type.allowance), reason: '', saved: false };

                    return (
                        <form className="grid gap-3 sm:grid-cols-4" key={type.id} onSubmit={(e) => { e.preventDefault(); void saveAllowance(type); }}>
                            <p className="self-end pb-2 text-sm font-medium">{type.code} · {type.name}</p>
                            <FormField label={t('fo.inv.allowanceRooms')}><Input inputMode="numeric" onChange={(e) => setAllowances({ ...allowances, [type.id]: { ...form, rooms: e.target.value, saved: false } })} value={form.rooms} /></FormField>
                            <FormField label={t('property.field.reason')}><Input maxLength={500} onChange={(e) => setAllowances({ ...allowances, [type.id]: { ...form, reason: e.target.value, saved: false } })} value={form.reason} /></FormField>
                            <div className="flex items-end gap-2"><Button disabled={action.busy} type="submit" variant="outline">{t('property.action.save')}</Button>{form.saved && <span className="pb-2 text-xs text-success">{t('fo.inv.allowanceSaved')}</span>}</div>
                        </form>
                    );
                })}
            </section>

            <Dialog footer={footer(() => void saveBlock())} onClose={closeAll} open={blockForm !== null} title={t('fo.inv.block')}>
                {blockForm !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        <FormField field="room_id" error={action.fieldError('room_id')} label={t('fo.inv.room')}>
                            <Select onChange={(e) => setBlockForm({ ...blockForm, roomId: e.target.value })} value={blockForm.roomId}>
                                <option value="">—</option>{rooms.map((r) => <option key={r.id} value={r.id}>{r.number} ({typeCode(r.room_type_id)})</option>)}
                            </Select>
                        </FormField>
                        <FormField field="kind" error={action.fieldError('kind')} label={t('fo.inv.kind')}><Select onChange={(e) => setBlockForm({ ...blockForm, kind: e.target.value })} value={blockForm.kind}>{KINDS.map((k) => <option key={k} value={k}>{t(`fo.inv.kind.${k}`)}</option>)}</Select></FormField>
                        <div className="grid grid-cols-2 gap-3">
                            <FormField field="from" error={action.fieldError('from')} label={t('fo.inv.from')}><DatePicker onChange={(e) => setBlockForm({ ...blockForm, from: e.target.value })} value={blockForm.from} /></FormField>
                            <FormField field="to" error={action.fieldError('to')} label={t('fo.inv.to')}><DatePicker onChange={(e) => setBlockForm({ ...blockForm, to: e.target.value })} value={blockForm.to} /></FormField>
                        </div>
                        {reasonField(blockForm.reason, (v) => setBlockForm({ ...blockForm, reason: v }))}
                    </div>
                )}
            </Dialog>

            <Dialog footer={footer(() => void saveHold())} onClose={closeAll} open={holdForm !== null} title={t('fo.inv.hold')}>
                {holdForm !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        <FormField field="room_type_id" error={action.fieldError('room_type_id')} label={t('fo.res.roomType')}>
                            <Select onChange={(e) => setHoldForm({ ...holdForm, typeId: e.target.value })} value={holdForm.typeId}><option value="">—</option>{types.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}</Select>
                        </FormField>
                        <div className="grid grid-cols-3 gap-3">
                            <FormField field="from" error={action.fieldError('from')} label={t('fo.inv.from')}><DatePicker onChange={(e) => setHoldForm({ ...holdForm, from: e.target.value })} value={holdForm.from} /></FormField>
                            <FormField field="to" error={action.fieldError('to')} label={t('fo.inv.to')}><DatePicker onChange={(e) => setHoldForm({ ...holdForm, to: e.target.value })} value={holdForm.to} /></FormField>
                            <FormField field="rooms" error={action.fieldError('rooms')} label={t('fo.inv.holdRooms')}><Input inputMode="numeric" onChange={(e) => setHoldForm({ ...holdForm, rooms: e.target.value })} value={holdForm.rooms} /></FormField>
                        </div>
                        <FormField field="expires_at" error={action.fieldError('expires_at')} hint={t('fo.inv.expiresHint')} label={t('fo.inv.expires')}><Input onChange={(e) => setHoldForm({ ...holdForm, expires: e.target.value })} value={holdForm.expires} /></FormField>
                        {reasonField(holdForm.reason, (v) => setHoldForm({ ...holdForm, reason: v }))}
                    </div>
                )}
            </Dialog>

            <ConfirmDialog cancelLabel={t('ui.dialog.cancel')} confirmLabel={t('fo.inv.release')} consequence={t('fo.inv.release.consequence')} onCancel={closeAll} onConfirm={() => void doRelease()} open={release !== null} pending={action.busy} title={t('fo.inv.release.title')}>
                <div className="flex flex-col gap-3">{error}{reasonField(reason, setReason)}</div>
            </ConfirmDialog>
        </FrontOfficeShell>
    );
}
