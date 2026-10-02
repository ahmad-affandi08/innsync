import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { useTranslation } from '@/shared/i18n/i18n';

export type RoomLine = { room_type_id: string; rate_plan_id: string; adults: string; children: string; guest_name: string };
export type GroupLookups = { types: { id: string; code: string; name: string }[]; plans: { id: string; code: string; name: string }[] };

export const blankLine = (lookups: GroupLookups): RoomLine => ({ room_type_id: lookups.types[0]?.id ?? '', rate_plan_id: lookups.plans[0]?.id ?? '', adults: '2', children: '0', guest_name: '' });

/** The rooms to book for a group: a type, a plan, the guests and, if it differs from the booker, the name of the guest. */
export function GroupRoomLines({ error, lines, lookups, onChange }: { error?: string; lines: RoomLine[]; lookups: GroupLookups; onChange: (lines: RoomLine[]) => void }) {
    const { t } = useTranslation();
    const set = (i: number, patch: Partial<RoomLine>) => onChange(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));

    return (
        <fieldset className="flex flex-col gap-2" data-testid="room-lines">
            <legend className="text-sm font-medium">{t('fo.group.rooms')}</legend>
            {error !== undefined ? <p className="text-sm text-danger">{error}</p> : null}
            {lines.map((l, i) => (
                <div className="grid items-end gap-2 sm:grid-cols-6" key={i}>
                    <FormField label={t('fo.res.roomType')}><Select onChange={(e) => set(i, { room_type_id: e.target.value })} value={l.room_type_id}>{lookups.types.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}</Select></FormField>
                    <FormField label={t('fo.res.ratePlan')}><Select onChange={(e) => set(i, { rate_plan_id: e.target.value })} value={l.rate_plan_id}>{lookups.plans.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.name}</option>)}</Select></FormField>
                    <FormField label={t('fo.res.adults')}><Input inputMode="numeric" onChange={(e) => set(i, { adults: e.target.value })} value={l.adults} /></FormField>
                    <FormField label={t('fo.res.children')}><Input inputMode="numeric" onChange={(e) => set(i, { children: e.target.value })} value={l.children} /></FormField>
                    <FormField label={t('fo.group.guestName')}><Input maxLength={150} onChange={(e) => set(i, { guest_name: e.target.value })} value={l.guest_name} /></FormField>
                    <Button aria-label={`${t('fo.group.removeRoom')} ${i + 1}`} disabled={lines.length === 1} onClick={() => onChange(lines.filter((_, j) => j !== i))} size="sm" type="button" variant="outline">{t('fo.group.removeRoom')}</Button>
                </div>
            ))}
            <div><Button disabled={lines.length >= 30} onClick={() => onChange([...lines, blankLine(lookups)])} size="sm" type="button" variant="outline">{t('fo.group.addRoomLine')}</Button></div>
        </fieldset>
    );
}

/** The rooms as the server takes them, or null when a number is not whole. */
export function roomBody(lines: RoomLine[]): { room_type_id: string; rate_plan_id: string; adults: number; children: number; guest_name: string | null }[] | null {
    const out = [];
    for (const l of lines) {
        const adults = Number(l.adults);
        const children = Number(l.children);
        if (!Number.isInteger(adults) || !Number.isInteger(children) || adults < 1 || children < 0) return null;
        out.push({ room_type_id: l.room_type_id, rate_plan_id: l.rate_plan_id, adults, children, guest_name: l.guest_name.trim() === '' ? null : l.guest_name.trim() });
    }
    return out;
}
