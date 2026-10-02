import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { blankLine, GroupRoomLines, roomBody, type GroupLookups, type RoomLine } from '@/modules/front-office/components/group-room-lines';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { newIdempotencyKey } from '@/shared/api/http';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Row = { id: string; number: string; name: string; booker_name: string; arrival: string; departure: string; billing_mode: string; rooms: number; master_balance_minor: number | null };
type Props = { lookups: (GroupLookups & { business_date: string }) | null; overview: { groups: Row[]; may: { manage: boolean } }; query: string };

const SOURCES = ['direct', 'phone', 'ota', 'corporate'] as const;

/** Group bookings: one booker with several rooms (FR-FO-006). */
export default function GroupsPage({ lookups, overview, query }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [search, setSearch] = useState(query);
    const [open, setOpen] = useState(false);
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const [invalid, setInvalid] = useState(false);
    const [form, setForm] = useState({ name: '', booker_name: '', booker_phone: '', booker_email: '', source: 'direct', arrival: lookups?.business_date ?? '', departure: '', billing_mode: 'master', route_extras: false, status: 'confirmed', notes: '' });
    const [lines, setLines] = useState<RoomLine[]>(() => (lookups === null ? [] : [blankLine(lookups)]));

    async function create() {
        const rooms = roomBody(lines);
        setInvalid(rooms === null);
        if (rooms === null) return;
        const done = await action.run<{ group: { group: { id: string } } }>('/front-office/groups', {
            idempotencyKey: intent,
            body: { ...form, booker_phone: form.booker_phone || null, booker_email: form.booker_email || null, notes: form.notes || null, route_extras: form.billing_mode === 'master' && form.route_extras, rooms },
        });
        if (done !== null) {
            setIntent(newIdempotencyKey());
            router.visit(`/front-office/groups/${done.group.group.id}`);
        }
    }

    return (
        <FrontOfficeShell description={t('fo.group.description')} title={t('fo.group.title')} wide>
            {action.error !== null && !open ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); router.get('/front-office/groups', search === '' ? {} : { query: search }); }}>
                <FormField label={t('fo.group.search')}><Input onChange={(e) => setSearch(e.target.value)} value={search} /></FormField>
                <Button type="submit" variant="outline">{t('fo.group.searchButton')}</Button>
                {overview.may.manage && lookups !== null ? <Button onClick={() => { action.clear(); setOpen(true); }} type="button">{t('fo.group.new')}</Button> : null}
            </form>

            {overview.groups.length === 0 ? <EmptyState title={t('fo.group.empty')} /> : (
                <table className="w-full text-left text-sm" data-testid="groups">
                    <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('fo.group.number')}</th><th scope="col">{t('fo.group.name')}</th><th scope="col">{t('fo.group.booker')}</th><th scope="col">{t('fo.res.arrival')}</th><th scope="col">{t('fo.res.departure')}</th><th scope="col">{t('fo.group.roomCount')}</th><th scope="col">{t('fo.group.billing')}</th><th scope="col">{t('fo.group.masterOwed')}</th></tr></thead>
                    <tbody>{overview.groups.map((g) => (
                        <tr className="border-t border-border" key={g.id}>
                            <th className="py-2 font-medium" scope="row"><Link className="underline-offset-2 hover:underline" href={`/front-office/groups/${g.id}`}>{g.number}</Link></th>
                            <td>{g.name}</td><td>{g.booker_name}</td><td>{format.date(g.arrival)}</td><td>{format.date(g.departure)}</td><td>{g.rooms}</td>
                            <td>{t(`fo.group.mode.${g.billing_mode}` as 'fo.group.mode.master')}</td><td>{g.master_balance_minor === null ? '—' : format.money(g.master_balance_minor, 'IDR')}</td>
                        </tr>
                    ))}</tbody>
                </table>
            )}

            {open && lookups !== null && (
                <section aria-labelledby="grp-new-h" className="flex max-w-4xl flex-col gap-3 border border-border p-4" data-testid="group-form">
                    <h2 className="text-lg font-semibold" id="grp-new-h">{t('fo.group.new')}</h2>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <div className="grid gap-3 sm:grid-cols-3">
                        <FormField error={action.fieldError('name')} label={t('fo.group.name')}><Input maxLength={120} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                        <FormField error={action.fieldError('booker_name')} label={t('fo.group.booker')}><Input maxLength={150} onChange={(e) => setForm({ ...form, booker_name: e.target.value })} value={form.booker_name} /></FormField>
                        <FormField label={t('fo.res.source')}><Select onChange={(e) => setForm({ ...form, source: e.target.value })} value={form.source}>{SOURCES.map((s) => <option key={s} value={s}>{t(`fo.source.${s}` as 'fo.source.direct')}</option>)}</Select></FormField>
                        <FormField label={t('fo.res.phone')}><Input maxLength={30} onChange={(e) => setForm({ ...form, booker_phone: e.target.value })} value={form.booker_phone} /></FormField>
                        <FormField error={action.fieldError('booker_email')} label={t('fo.res.email')}><Input maxLength={190} onChange={(e) => setForm({ ...form, booker_email: e.target.value })} value={form.booker_email} /></FormField>
                        <FormField label={t('fo.res.status')}><Select onChange={(e) => setForm({ ...form, status: e.target.value })} value={form.status}><option value="confirmed">{t('fo.status.confirmed')}</option><option value="tentative">{t('fo.status.tentative')}</option></Select></FormField>
                        <FormField error={action.fieldError('arrival')} label={t('fo.res.arrival')}><Input onChange={(e) => setForm({ ...form, arrival: e.target.value })} type="date" value={form.arrival} /></FormField>
                        <FormField error={action.fieldError('departure')} label={t('fo.res.departure')}><Input onChange={(e) => setForm({ ...form, departure: e.target.value })} type="date" value={form.departure} /></FormField>
                    </div>
                    <fieldset className="flex flex-col gap-1 text-sm">
                        <legend className="font-medium">{t('fo.group.billing')}</legend>
                        <label className="flex items-center gap-2"><input checked={form.billing_mode === 'master'} name="billing" onChange={() => setForm({ ...form, billing_mode: 'master' })} type="radio" />{t('fo.group.mode.master')}</label>
                        <label className="flex items-center gap-2"><input checked={form.billing_mode === 'per_room'} name="billing" onChange={() => setForm({ ...form, billing_mode: 'per_room' })} type="radio" />{t('fo.group.mode.per_room')}</label>
                        {form.billing_mode === 'master' ? <label className="flex items-center gap-2"><input checked={form.route_extras} onChange={(e) => setForm({ ...form, route_extras: e.target.checked })} type="checkbox" />{t('fo.group.routeExtras')}</label> : null}
                        <p className="text-muted-foreground">{t('fo.group.billingNote')}</p>
                    </fieldset>
                    <GroupRoomLines error={invalid ? t('fo.group.invalidRooms') : action.fieldError('rooms')} lines={lines} lookups={lookups} onChange={setLines} />
                    <FormField label={t('fo.res.notes')}><Textarea maxLength={500} onChange={(e) => setForm({ ...form, notes: e.target.value })} rows={2} value={form.notes} /></FormField>
                    <div className="flex gap-2"><Button loading={action.busy} onClick={() => void create()} type="button">{t('fo.group.create')}</Button><Button disabled={action.busy} onClick={() => setOpen(false)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button></div>
                </section>
            )}
        </FrontOfficeShell>
    );
}
