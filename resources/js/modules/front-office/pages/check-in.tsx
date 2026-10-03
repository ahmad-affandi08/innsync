import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Reservation = { id: string; number: string; status: string; guest_name: string; arrival: string; departure: string; adults: number; children: number };
type Room = { id: string; number: string; floor: string | null; ready: boolean };
type Profile = { nationality: string; id_valid_until: string | null; visa_number: string | null; address: string | null };
type Match = { guest_id: string; full_name: string; stays: number; last_stay: string | null; profile: Profile | null; history: { arrival: string; departure: string; room: string | null }[]; preferences: string | null };
type Done = { id: string; room_number: string; warnings: string[] };

const ID_TYPES = ['ktp', 'passport', 'sim', 'kitas', 'other'] as const;
const WARNINGS = ['id_expired', 'id_expires_during_stay', 'visa_missing'] as const;

export default function CheckInPage({ preselect, reservation: r, rooms, stay }: { preselect: string | null; reservation: Reservation; rooms: Room[]; stay: { id: string } | null }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const lookup = useServerAction();
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const [form, setForm] = useState({
        roomId: rooms.find((x) => x.id === preselect && x.ready)?.id ?? rooms.find((x) => x.ready)?.id ?? '', fullName: r.guest_name, nationality: 'ID', idType: 'ktp', idNumber: '', idValidUntil: '', visa: '', address: '', preferences: '', adults: String(r.adults), children: String(r.children),
    });
    const [matches, setMatches] = useState<Match[] | null>(null);
    const [done, setDone] = useState<Done | null>(null);
    const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));
    const ready = r.status === 'confirmed' || r.status === 'guaranteed';

    async function find() {
        setMatches(null);
        const result = await lookup.run<{ matches: Match[] }>(`/front-office/reservations/${r.id}/guest-lookup`, { body: { id_type: form.idType, id_number: form.idNumber } });
        if (result !== null) setMatches(result.matches);
    }

    async function submit() {
        const result = await action.run<{ stay: { id: string; room_number: string; warnings: string[] } }>(`/front-office/reservations/${r.id}/check-in`, {
            idempotencyKey: intent,
            body: {
                room_id: form.roomId, full_name: form.fullName, nationality: form.nationality.toUpperCase(), id_type: form.idType, id_number: form.idNumber,
                id_valid_until: form.idValidUntil || null, visa_number: form.visa || null, address: form.address, preferences: form.preferences.trim() || null, adults: Number(form.adults), children: Number(form.children),
            },
        });
        if (result !== null) {
            setIntent(newIdempotencyKey());
            setDone({ id: result.stay.id, room_number: result.stay.room_number, warnings: result.stay.warnings });
        }
    }

    return (
        <FrontOfficeShell description={t('fo.checkin.description')} title={t('fo.checkin.title', { number: r.number })}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button asChild size="sm" variant="outline"><Link href={`/front-office/reservations/${r.id}`}>{t('fo.checkin.back')}</Link></Button>
                <p className="text-sm text-muted-foreground">{format.date(r.arrival, 'long')} – {format.date(r.departure, 'long')}</p>
            </div>

            {done !== null ? (
                <div className="flex flex-col gap-3">
                    <Alert title={t('fo.checkin.done', { room: done.room_number })} tone="success" />
                    {WARNINGS.filter((w) => done.warnings.includes(w)).map((w) => <Alert key={w} title={t(`fo.checkin.warning.${w}`)} tone="warning" />)}
                    <div><Button asChild><Link href={`/front-office/stays/${done.id}`}>{t('fo.checkin.goToStay')}</Link></Button></div>
                </div>
            ) : stay !== null ? (
                <div className="flex flex-col gap-3">
                    <Alert title={t('fo.checkin.alreadyIn')} tone="info" />
                    <div><Button asChild><Link href={`/front-office/stays/${stay.id}`}>{t('fo.checkin.goToStay')}</Link></Button></div>
                </div>
            ) : !ready ? <Alert title={t('fo.checkin.notReady')} tone="warning" /> : (
                <form className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
                    {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                    <FormField field="room_id" error={action.fieldError('room_id')} hint={rooms.length === 0 ? t('fo.checkin.noRooms') : undefined} label={t('fo.checkin.room')}>
                        <Select onChange={(e) => set({ roomId: e.target.value })} value={form.roomId}>
                            {rooms.map((room) => <option disabled={!room.ready} key={room.id} value={room.id}>{room.number}{room.floor !== null ? ` · ${room.floor}` : ''}{room.ready ? '' : ` (${t('fo.checkin.notReady')})`}</option>)}
                        </Select>
                    </FormField>
                    <FormField field="full_name" error={action.fieldError('full_name')} label={t('fo.checkin.fullName')}><Input autoComplete="off" maxLength={150} onChange={(e) => set({ fullName: e.target.value })} required value={form.fullName} /></FormField>
                    <FormField field="id_type" error={action.fieldError('id_type')} label={t('fo.checkin.idType')}>
                        <Select onChange={(e) => set({ idType: e.target.value })} value={form.idType}>{ID_TYPES.map((x) => <option key={x} value={x}>{t(`fo.checkin.idType.${x}`)}</option>)}</Select>
                    </FormField>
                    <FormField field="id_number" error={action.fieldError('id_number')} label={t('fo.checkin.idNumber')}><Input autoComplete="off" maxLength={40} onChange={(e) => { set({ idNumber: e.target.value }); setMatches(null); }} required value={form.idNumber} /></FormField>
                    <div className="flex flex-col gap-2 sm:col-span-2" aria-live="polite">
                        <div><Button disabled={lookup.busy || form.idNumber.trim() === ''} onClick={() => void find()} size="sm" type="button" variant="outline">{t('fo.checkin.lookup')}</Button></div>
                        {lookup.error !== null ? <ErrorState {...errorCopy} error={lookup.error} onRefresh={() => window.location.reload()} /> : null}
                        {matches !== null && matches.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.checkin.lookupNone')}</p> : null}
                        {matches?.map((m) => (
                            <div className="flex flex-wrap items-center gap-2 text-sm" key={m.guest_id}>
                                <span>{m.last_stay === null ? `${m.full_name}: ${t('fo.checkin.lookupNever')}` : t('fo.checkin.lookupFound', { name: m.full_name, n: m.stays, date: format.date(m.last_stay) })}</span>
                                <Button onClick={() => set({ fullName: m.full_name })} size="sm" type="button" variant="outline">{t('fo.checkin.useName')}</Button>
                                {m.profile !== null ? (
                                    <Button
                                        onClick={() => set({ fullName: m.full_name, nationality: m.profile?.nationality ?? form.nationality, idValidUntil: m.profile?.id_valid_until ?? form.idValidUntil, visa: m.profile?.visa_number ?? form.visa, address: m.profile?.address ?? form.address, preferences: m.preferences ?? form.preferences })}
                                        size="sm" type="button" variant="outline"
                                    >{t('fo.checkin.useProfile')}</Button>
                                ) : null}
                                {m.history.length > 0 ? (
                                    <ul className="basis-full list-disc pl-5 text-xs text-muted-foreground" data-testid="guest-history">
                                        {m.history.map((h) => <li key={`${h.arrival}-${h.room ?? ''}`}>{t('fo.checkin.historyRow', { arrival: format.date(h.arrival), departure: format.date(h.departure), room: h.room ?? '-' })}</li>)}
                                    </ul>
                                ) : null}
                                {m.preferences !== null ? <p className="basis-full text-xs text-muted-foreground">{t('fo.checkin.knownPreferences', { text: m.preferences })}</p> : null}
                            </div>
                        ))}
                    </div>
                    <FormField field="id_valid_until" error={action.fieldError('id_valid_until')} label={t('fo.checkin.idValidUntil')}><DatePicker onChange={(e) => set({ idValidUntil: e.target.value })} value={form.idValidUntil} /></FormField>
                    <FormField field="nationality" error={action.fieldError('nationality')} label={t('fo.checkin.nationality')}><Input maxLength={2} onChange={(e) => set({ nationality: e.target.value })} required value={form.nationality} /></FormField>
                    <FormField field="visa_number" error={action.fieldError('visa_number')} label={t('fo.checkin.visa')}><Input autoComplete="off" maxLength={40} onChange={(e) => set({ visa: e.target.value })} value={form.visa} /></FormField>
                    <div className="grid grid-cols-2 gap-4">
                        <FormField field="adults" error={action.fieldError('adults')} label={t('fo.checkin.adults')}><Input min={1} onChange={(e) => set({ adults: e.target.value })} type="number" value={form.adults} /></FormField>
                        <FormField field="children" error={action.fieldError('children')} label={t('fo.checkin.children')}><Input min={0} onChange={(e) => set({ children: e.target.value })} type="number" value={form.children} /></FormField>
                    </div>
                    <div className="sm:col-span-2"><FormField field="address" error={action.fieldError('address')} label={t('fo.checkin.address')}><Textarea maxLength={500} onChange={(e) => set({ address: e.target.value })} required rows={2} value={form.address} /></FormField></div>
                    <div className="sm:col-span-2"><FormField field="preferences" error={action.fieldError('preferences')} hint={t('fo.checkin.preferencesHint')} label={t('fo.checkin.preferences')}><Textarea maxLength={500} onChange={(e) => set({ preferences: e.target.value })} rows={2} value={form.preferences} /></FormField></div>
                    <div className="sm:col-span-2"><Button disabled={form.roomId === ''} loading={action.busy} type="submit">{t('fo.checkin.submit')}</Button></div>
                </form>
            )}
        </FrontOfficeShell>
    );
}
