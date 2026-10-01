import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { FlagsPanel, RequestsList, type RoomFlag, type RoomRequest } from '@/modules/housekeeping/components/room-annotations';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Finding = { id: string; description: string; mandatory: boolean };
type Task = { id: string; room_id: string; requests: RoomRequest[]; flags: RoomFlag[]; room_number: string | null; floor: string | null; kind: string; status: string; lock_version: number; findings: Finding[] };

/** The attendant's phone screen: large targets, one action per room. Start and Finish are two taps from the list. */
export default function MyRoomsPage({ tasks }: { tasks: Task[] }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [finished, setFinished] = useState<{ room: string; minutes: number } | null>(null);

    async function start(task: Task) {
        setFinished(null);
        await action.run(`/housekeeping/tasks/${task.id}/start`, { body: { lock_version: task.lock_version }, reload: ['tasks'] });
    }

    async function finish(task: Task) {
        const done = await action.run<{ task: { duration_seconds: number } }>(`/housekeeping/tasks/${task.id}/finish`, { body: { lock_version: task.lock_version }, reload: ['tasks'] });
        if (done !== null) setFinished({ room: task.room_number ?? '', minutes: Math.max(1, Math.round(done.task.duration_seconds / 60)) });
    }

    async function endFlag(flag: RoomFlag) {
        await action.run(`/housekeeping/flags/${flag.id}/end`, { body: { lock_version: flag.lock_version }, reload: ['tasks'] });
    }

    async function raiseFlag(task: Task, kind: string, note: string) {
        await action.run('/housekeeping/flags', { body: { room_id: task.room_id, kind, note: note.trim() || null }, reload: ['tasks'] });
    }

    async function fixed(finding: Finding) {
        await action.run(`/housekeeping/findings/${finding.id}/resolve`, { reload: ['tasks'] });
    }

    return (
        <HousekeepingShell description={t('hk.mine.description')} title={t('hk.mine.title')}>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {finished !== null ? <Alert title={`${finished.room}: ${t('hk.mine.finished', { minutes: finished.minutes })}`} tone="success" /> : null}
            {tasks.length === 0 ? <EmptyState title={t('hk.mine.empty')} /> : (
                <ul className="flex flex-col gap-4">
                    {tasks.map((task) => (
                        <li className="flex flex-col gap-3 border border-border p-4" key={task.id}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-2xl font-semibold" data-testid="room-number">{task.room_number}</p>
                                <div className="flex items-center gap-2">
                                    {task.floor !== null ? <span className="text-xs text-muted-foreground">{t('hk.mine.floor', { floor: task.floor })}</span> : null}
                                    <StatusBadge label={t(`hk.kind.${task.kind}` as 'hk.kind.departure')} tone={task.kind === 'rework' ? 'danger' : 'neutral'} />
                                    <StatusBadge label={t(`hk.task.${task.status}` as 'hk.task.open')} tone={task.status === 'in_progress' ? 'info' : 'neutral'} />
                                </div>
                            </div>
                            <RequestsList requests={task.requests} />
                            <FlagsPanel busy={action.busy} flags={task.flags} kinds={['dnd', 'refused_service', 'make_up_room', 'privacy']} onEnd={(f) => void endFlag(f)} onRaise={(k, n) => void raiseFlag(task, k, n)} />
                            {task.findings.length > 0 && (
                                <div className="flex flex-col gap-2">
                                    <p className="text-sm font-medium">{t('hk.mine.findings')}</p>
                                    <ul className="flex flex-col gap-2 text-sm">{task.findings.map((f) => (
                                        <li className="flex flex-wrap items-center justify-between gap-2" key={f.id}>
                                            <span>{f.description}{f.mandatory ? ` (${t('hk.inspect.mandatoryTag')})` : ''}</span>
                                            <Button disabled={action.busy} onClick={() => void fixed(f)} size="sm" type="button" variant="outline">{t('hk.mine.fixed')}</Button>
                                        </li>
                                    ))}</ul>
                                </div>
                            )}
                            {task.status === 'in_progress'
                                ? <Button className="min-h-14 text-lg" disabled={action.busy} onClick={() => void finish(task)} type="button">{t('hk.mine.finish')}</Button>
                                : <Button className="min-h-14 text-lg" disabled={action.busy} onClick={() => void start(task)} type="button">{t('hk.mine.start')}</Button>}
                        </li>
                    ))}
                </ul>
            )}
        </HousekeepingShell>
    );
}
