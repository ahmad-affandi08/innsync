import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ConfirmDialog, Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type RoomType = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    max_adults: number;
    max_children: number;
    sort_order: number;
    is_active: boolean;
    lock_version: number;
};

type Room = { id: string; number: string; room_type_id: string; floor: string | null; building: string | null; is_active: boolean; lock_version: number };

type TypeForm = { id: string | null; code: string; name: string; description: string; maxAdults: string; maxChildren: string; sortOrder: string; lockVersion: number; reason: string };
type RoomForm = { id: string | null; number: string; roomTypeId: string; floor: string; building: string; lockVersion: number; reason: string };
type Toggle = { kind: 'type' | 'room'; id: string; name: string; active: boolean; lockVersion: number };

const emptyType: TypeForm = { id: null, code: '', name: '', description: '', maxAdults: '2', maxChildren: '0', sortOrder: '0', lockVersion: 0, reason: '' };
const emptyRoom: RoomForm = { id: null, number: '', roomTypeId: '', floor: '', building: '', lockVersion: 0, reason: '' };

export default function RoomCatalogPage({ rooms, types }: { rooms: Room[]; types: RoomType[] }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [typeForm, setTypeForm] = useState<TypeForm | null>(null);
    const [roomForm, setRoomForm] = useState<RoomForm | null>(null);
    const [toggle, setToggle] = useState<Toggle | null>(null);
    const [reason, setReason] = useState('');
    const typeName = (id: string) => types.find((x) => x.id === id)?.name ?? '';

    function closeAll() {
        action.clear();
        setTypeForm(null);
        setRoomForm(null);
        setToggle(null);
        setReason('');
    }

    async function saveType() {
        if (typeForm === null) return;
        const body = {
            code: typeForm.code,
            name: typeForm.name,
            description: typeForm.description || null,
            max_adults: Number(typeForm.maxAdults),
            max_children: Number(typeForm.maxChildren),
            sort_order: Number(typeForm.sortOrder || 0),
            lock_version: typeForm.lockVersion,
            reason: typeForm.reason,
        };
        const done = await action.run(typeForm.id === null ? '/property/room-types' : `/property/room-types/${typeForm.id}`, {
            method: typeForm.id === null ? 'POST' : 'PUT',
            body,
            reload: ['types', 'rooms'],
        });
        if (done !== null) closeAll();
    }

    async function saveRoom() {
        if (roomForm === null) return;
        const body = { number: roomForm.number, room_type_id: roomForm.roomTypeId, floor: roomForm.floor || null, building: roomForm.building || null, lock_version: roomForm.lockVersion, reason: roomForm.reason };
        const done = await action.run(roomForm.id === null ? '/property/rooms' : `/property/rooms/${roomForm.id}`, {
            method: roomForm.id === null ? 'POST' : 'PUT',
            body,
            reload: ['types', 'rooms'],
        });
        if (done !== null) closeAll();
    }

    async function applyToggle() {
        if (toggle === null) return;
        const path = toggle.kind === 'type' ? `/property/room-types/${toggle.id}/active` : `/property/rooms/${toggle.id}/active`;
        const done = await action.run(path, { body: { active: toggle.active, lock_version: toggle.lockVersion, reason }, reload: ['types', 'rooms'] });
        if (done !== null) closeAll();
    }

    const open = (fn: () => void) => () => {
        action.clear();
        fn();
    };

    return (
        <PropertyShell description={t('property.rooms.description')} title={t('property.rooms.title')}>
            {action.error !== null && toggle === null && typeForm === null && roomForm === null ? (
                <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} />
            ) : null}

            <section aria-labelledby="types-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="types-h">{t('property.types.heading')}</h2>
                    <Button onClick={open(() => setTypeForm(emptyType))} size="sm" type="button">{t('property.types.add')}</Button>
                </div>
                {types.length === 0 ? (
                    <EmptyState title={t('property.types.empty')} />
                ) : (
                    <ul className="divide-y divide-border border-y border-border">
                        {types.map((type) => (
                            <li className="flex flex-wrap items-center justify-between gap-3 py-3" key={type.id}>
                                <div>
                                    <p className="text-sm font-medium">{type.code} · {type.name}</p>
                                    <p className="text-xs text-muted-foreground">{t('property.types.occupancy', { adults: type.max_adults, children: type.max_children })}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <StatusBadge label={type.is_active ? t('property.status.active') : t('property.status.inactive')} tone={type.is_active ? 'success' : 'neutral'} />
                                    <Button onClick={open(() => setTypeForm({ id: type.id, code: type.code, name: type.name, description: type.description ?? '', maxAdults: String(type.max_adults), maxChildren: String(type.max_children), sortOrder: String(type.sort_order), lockVersion: type.lock_version, reason: '' }))} size="sm" type="button" variant="outline">{t('property.action.edit')}</Button>
                                    <Button onClick={open(() => setToggle({ kind: 'type', id: type.id, name: type.name, active: !type.is_active, lockVersion: type.lock_version }))} size="sm" type="button" variant="outline">{type.is_active ? t('property.action.deactivate') : t('property.action.activate')}</Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section aria-labelledby="rooms-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="rooms-h">{t('property.rooms.heading')}</h2>
                    <Button disabled={types.every((x) => !x.is_active)} onClick={open(() => setRoomForm(emptyRoom))} size="sm" type="button">{t('property.rooms.add')}</Button>
                </div>
                {rooms.length === 0 ? (
                    <EmptyState title={t('property.rooms.empty')} />
                ) : (
                    <ul className="divide-y divide-border border-y border-border">
                        {rooms.map((room) => (
                            <li className="flex flex-wrap items-center justify-between gap-3 py-3" key={room.id}>
                                <div>
                                    <p className="text-sm font-medium">{room.number}</p>
                                    <p className="text-xs text-muted-foreground">{typeName(room.room_type_id)}{room.building !== null ? ` · ${t('property.rooms.building')} ${room.building}` : ''}{room.floor !== null ? ` · ${t('property.rooms.floor')} ${room.floor}` : ''}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <StatusBadge label={room.is_active ? t('property.status.active') : t('property.status.inactive')} tone={room.is_active ? 'success' : 'neutral'} />
                                    <Button onClick={open(() => setRoomForm({ id: room.id, number: room.number, roomTypeId: room.room_type_id, floor: room.floor ?? '', building: room.building ?? '', lockVersion: room.lock_version, reason: '' }))} size="sm" type="button" variant="outline">{t('property.action.edit')}</Button>
                                    <Button onClick={open(() => setToggle({ kind: 'room', id: room.id, name: room.number, active: !room.is_active, lockVersion: room.lock_version }))} size="sm" type="button" variant="outline">{room.is_active ? t('property.action.deactivate') : t('property.action.activate')}</Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={closeAll} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void saveType()} type="button">{t('property.action.save')}</Button>
                </>}
                onClose={closeAll}
                open={typeForm !== null}
                title={typeForm?.id === null ? t('property.types.add') : t('property.types.edit')}
            >
                {typeForm !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        {typeForm.id === null && (
                            <FormField field="code" error={action.fieldError('code')} hint={t('property.types.codeHint')} label={t('property.types.code')}>
                                <Input maxLength={20} onChange={(e) => setTypeForm({ ...typeForm, code: e.target.value })} value={typeForm.code} />
                            </FormField>
                        )}
                        <FormField field="name" error={action.fieldError('name')} label={t('property.types.name')}>
                            <Input maxLength={100} onChange={(e) => setTypeForm({ ...typeForm, name: e.target.value })} value={typeForm.name} />
                        </FormField>
                        <FormField field="description" error={action.fieldError('description')} label={t('property.types.descriptionField')}>
                            <Textarea maxLength={500} onChange={(e) => setTypeForm({ ...typeForm, description: e.target.value })} value={typeForm.description} />
                        </FormField>
                        <div className="grid grid-cols-3 gap-3">
                            <FormField field="max_adults" error={action.fieldError('max_adults')} label={t('property.types.maxAdults')}>
                                <Input inputMode="numeric" onChange={(e) => setTypeForm({ ...typeForm, maxAdults: e.target.value })} value={typeForm.maxAdults} />
                            </FormField>
                            <FormField field="max_children" error={action.fieldError('max_children')} label={t('property.types.maxChildren')}>
                                <Input inputMode="numeric" onChange={(e) => setTypeForm({ ...typeForm, maxChildren: e.target.value })} value={typeForm.maxChildren} />
                            </FormField>
                            <FormField label={t('property.types.sortOrder')}>
                                <Input inputMode="numeric" onChange={(e) => setTypeForm({ ...typeForm, sortOrder: e.target.value })} value={typeForm.sortOrder} />
                            </FormField>
                        </div>
                        <FormField field="reason" error={action.fieldError('reason')} hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                            <Input maxLength={500} onChange={(e) => setTypeForm({ ...typeForm, reason: e.target.value })} value={typeForm.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={closeAll} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void saveRoom()} type="button">{t('property.action.save')}</Button>
                </>}
                onClose={closeAll}
                open={roomForm !== null}
                title={roomForm?.id === null ? t('property.rooms.add') : t('property.rooms.edit')}
            >
                {roomForm !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        {roomForm.id === null && (
                            <FormField field="number" error={action.fieldError('number')} hint={t('property.rooms.numberHint')} label={t('property.rooms.number')}>
                                <Input maxLength={20} onChange={(e) => setRoomForm({ ...roomForm, number: e.target.value })} value={roomForm.number} />
                            </FormField>
                        )}
                        <FormField field="room_type_id" error={action.fieldError('room_type_id')} label={t('property.rooms.type')}>
                            <Select onChange={(e) => setRoomForm({ ...roomForm, roomTypeId: e.target.value })} value={roomForm.roomTypeId}>
                                <option value="">{t('property.rooms.chooseType')}</option>
                                {types.filter((x) => x.is_active).map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}
                            </Select>
                        </FormField>
                        <FormField field="floor" error={action.fieldError('floor')} label={t('property.rooms.floor')}>
                            <Input maxLength={10} onChange={(e) => setRoomForm({ ...roomForm, floor: e.target.value })} value={roomForm.floor} />
                        </FormField>
                        <FormField field="building" error={action.fieldError('building')} label={t('property.rooms.building')}>
                            <Input maxLength={40} onChange={(e) => setRoomForm({ ...roomForm, building: e.target.value })} value={roomForm.building} />
                        </FormField>
                        <FormField field="reason" error={action.fieldError('reason')} hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                            <Input maxLength={500} onChange={(e) => setRoomForm({ ...roomForm, reason: e.target.value })} value={roomForm.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={toggle?.active ? t('property.action.activate') : t('property.action.deactivate')}
                consequence={toggle?.active ? t('property.activate.consequence') : t('property.deactivate.consequence')}
                destructive={toggle?.active === false}
                onCancel={closeAll}
                onConfirm={() => void applyToggle()}
                open={toggle !== null}
                pending={action.busy}
                title={toggle?.active ? t('property.activate.title', { name: toggle.name }) : t('property.deactivate.title', { name: toggle?.name ?? '' })}
            >
                <div className="flex flex-col gap-3">
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField field="reason" error={action.fieldError('reason')} hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                        <Input maxLength={500} onChange={(e) => setReason(e.target.value)} value={reason} />
                    </FormField>
                </div>
            </ConfirmDialog>
        </PropertyShell>
    );
}
