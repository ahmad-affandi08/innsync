import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

export type RoomRequest = { number: string; title: string; detail: string | null; priority: string; due_at: string | null; overdue: boolean };
export type RoomFlag = { id: string; kind: string; note: string | null; started_at: string; lock_version: number };

/** What the guest in the room asked housekeeping for, and by when (FR-HK-013). */
export function RequestsList({ requests }: { requests: RoomRequest[] }) {
    const { t } = useTranslation();
    const format = useFormatters();

    if (requests.length === 0) return null;

    return (
        <ul className="flex flex-col gap-1 text-xs" data-testid="room-requests">
            {requests.map((r) => (
                <li className="flex flex-wrap items-center gap-1" key={r.number}>
                    <span className="font-medium">{r.title}</span>
                    {r.due_at !== null ? <span className="text-muted-foreground">{t('hk.req.due', { time: format.instant(r.due_at) })}</span> : null}
                    {r.priority === 'urgent' ? <StatusBadge label={t('hk.req.urgent')} tone="danger" /> : null}
                    {r.overdue ? <StatusBadge label={t('hk.req.overdue')} tone="danger" /> : null}
                </li>
            ))}
        </ul>
    );
}

/** Service flags on a room with a guest in it (FR-HK-017): shown, ended and added here. They change neither occupancy nor the cleaning status. */
export function FlagsPanel({ busy, flags, kinds, onEnd, onRaise }: { busy: boolean; flags: RoomFlag[]; kinds: string[]; onEnd: (flag: RoomFlag) => void; onRaise?: (kind: string, note: string) => void }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [kind, setKind] = useState('dnd');
    const [note, setNote] = useState('');

    return (
        <div className="flex flex-col gap-1 text-xs" data-testid="room-flags">
            {flags.map((f) => (
                <div className="flex flex-wrap items-center gap-2" key={f.id}>
                    <StatusBadge label={t('hk.flag.since', { kind: t(`hk.flag.kind.${f.kind}` as 'hk.flag.kind.dnd'), time: format.instant(f.started_at) })} tone="warning" />
                    {f.note !== null ? <span className="text-muted-foreground">{f.note}</span> : null}
                    <Button disabled={busy} onClick={() => onEnd(f)} size="sm" type="button" variant="outline">{t('hk.flag.end')}</Button>
                </div>
            ))}
            {onRaise !== undefined ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Select aria-label={t('hk.flag.kind')} className="min-h-9 w-44" onChange={(e) => setKind(e.target.value)} value={kind}>{kinds.map((k) => <option key={k} value={k}>{t(`hk.flag.kind.${k}` as 'hk.flag.kind.dnd')}</option>)}</Select>
                    <Input aria-label={t('hk.flag.note')} className="min-h-9 w-44" maxLength={200} onChange={(e) => setNote(e.target.value)} placeholder={t('hk.flag.note')} value={note} />
                    <Button disabled={busy} onClick={() => { onRaise(kind, note); setNote(''); }} size="sm" type="button" variant="outline">{t('hk.flag.save')}</Button>
                </div>
            ) : null}
        </div>
    );
}
