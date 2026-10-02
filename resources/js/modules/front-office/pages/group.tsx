import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { blankLine, GroupRoomLines, roomBody, type GroupLookups, type RoomLine } from '@/modules/front-office/components/group-room-lines';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { statusTone } from '@/modules/front-office/pages/reservations';
import { useServerAction } from '@/shared/api/use-server-action';
import { newIdempotencyKey } from '@/shared/api/http';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Member = { line: number; reservation_id: string; number: string; status: string; guest_name: string; adults: number; children: number; arrival: string; departure: string; total_minor: number; currency: string; room_type: string; room: string | null; own_balance_minor: number };
type Group = { id: string; number: string; name: string; booker_name: string; booker_phone: string | null; booker_email: string | null; source: string; arrival: string; departure: string; billing_mode: string; route_extras: boolean; notes: string | null };
type Props = {
    group: { group: Group; members: Member[]; master: { folio_id: string; number: string; balance_minor: number; currency: string; is_closed: boolean } | null; totals: { rooms: number; stay_minor: number }; may: { manage: boolean } };
    lookups: GroupLookups | null;
};

/** One group booking: its rooms, the master folio and adding rooms (FR-FO-006). */
export default function GroupPage({ group: view, lookups }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const g = view.group;
    const [adding, setAdding] = useState(false);
    const [lines, setLines] = useState<RoomLine[]>(() => (lookups === null ? [] : [blankLine(lookups)]));
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const [invalid, setInvalid] = useState(false);
    const currency = view.members[0]?.currency ?? 'IDR';

    async function add() {
        const rooms = roomBody(lines);
        setInvalid(rooms === null);
        if (rooms === null) return;
        const done = await action.run(`/front-office/groups/${g.id}/rooms`, { idempotencyKey: intent, body: { rooms }, reload: ['group'] });
        if (done !== null) {
            setIntent(newIdempotencyKey());
            setAdding(false);
            if (lookups !== null) setLines([blankLine(lookups)]);
        }
    }

    return (
        <FrontOfficeShell description={`${g.name} · ${g.booker_name}`} title={g.number} wide>
            {action.error !== null && !adding ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <dl className="grid gap-3 text-sm sm:grid-cols-4" data-testid="group-facts">
                <div><dt className="text-xs text-muted-foreground">{t('fo.res.arrival')}</dt><dd>{format.date(g.arrival)}</dd></div>
                <div><dt className="text-xs text-muted-foreground">{t('fo.res.departure')}</dt><dd>{format.date(g.departure)}</dd></div>
                <div><dt className="text-xs text-muted-foreground">{t('fo.group.roomCount')}</dt><dd>{view.totals.rooms}</dd></div>
                <div><dt className="text-xs text-muted-foreground">{t('fo.group.stayTotal')}</dt><dd>{format.money(view.totals.stay_minor, currency)}</dd></div>
                <div><dt className="text-xs text-muted-foreground">{t('fo.group.billing')}</dt><dd>{t(`fo.group.mode.${g.billing_mode}` as 'fo.group.mode.master')}{g.route_extras ? ` · ${t('fo.group.extrasToo')}` : ''}</dd></div>
                {g.booker_phone !== null ? <div><dt className="text-xs text-muted-foreground">{t('fo.res.phone')}</dt><dd>{g.booker_phone}</dd></div> : null}
                {g.booker_email !== null ? <div><dt className="text-xs text-muted-foreground">{t('fo.res.email')}</dt><dd>{g.booker_email}</dd></div> : null}
            </dl>

            {view.master !== null ? (
                <section aria-labelledby="grp-master-h" className="flex flex-col gap-1" data-testid="master">
                    <h2 className="text-lg font-semibold" id="grp-master-h">{t('fo.group.master')}</h2>
                    <p className="text-sm">{view.master.number} · {format.money(view.master.balance_minor, view.master.currency)} {view.master.is_closed ? <StatusBadge label={t('fo.group.masterClosed')} tone="neutral" /> : null}</p>
                    <Link className="text-sm underline-offset-2 hover:underline" href={`/front-office/folios/${view.master.folio_id}`}>{t('fo.group.openMaster')}</Link>
                </section>
            ) : null}

            <section aria-labelledby="grp-rooms-h" className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="grp-rooms-h">{t('fo.group.rooms')}</h2>
                    {view.may.manage && lookups !== null ? <Button onClick={() => { action.clear(); setAdding(true); }} size="sm" type="button" variant="outline">{t('fo.group.addRooms')}</Button> : null}
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm" data-testid="members">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">#</th><th scope="col">{t('fo.res.number')}</th><th scope="col">{t('fo.res.guest')}</th><th scope="col">{t('fo.res.roomType')}</th><th scope="col">{t('fo.group.room')}</th><th scope="col">{t('fo.res.status')}</th><th scope="col">{t('fo.group.stayTotal')}</th><th scope="col">{t('fo.group.ownBalance')}</th></tr></thead>
                        <tbody>{view.members.map((m) => (
                            <tr className="border-t border-border" key={m.reservation_id}>
                                <td className="py-2">{m.line}</td>
                                <th scope="row"><Link className="font-medium underline-offset-2 hover:underline" href={`/front-office/reservations/${m.reservation_id}`}>{m.number}</Link></th>
                                <td>{m.guest_name}</td><td>{m.room_type}</td><td>{m.room ?? '—'}</td>
                                <td><StatusBadge label={t(`fo.status.${m.status}` as 'fo.status.confirmed')} tone={statusTone[m.status] ?? 'neutral'} /></td>
                                <td>{format.money(m.total_minor, m.currency)}</td><td>{format.money(m.own_balance_minor, m.currency)}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            </section>

            {adding && lookups !== null && (
                <section aria-labelledby="grp-add-h" className="flex max-w-4xl flex-col gap-3 border border-border p-4" data-testid="add-rooms">
                    <h2 className="text-lg font-semibold" id="grp-add-h">{t('fo.group.addRooms')}</h2>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <GroupRoomLines error={invalid ? t('fo.group.invalidRooms') : action.fieldError('rooms')} lines={lines} lookups={lookups} onChange={setLines} />
                    <div className="flex gap-2"><Button loading={action.busy} onClick={() => void add()} type="button">{t('fo.group.addRooms')}</Button><Button disabled={action.busy} onClick={() => setAdding(false)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button></div>
                </section>
            )}
        </FrontOfficeShell>
    );
}
