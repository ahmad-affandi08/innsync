import { CircleAlert, CircleCheck, Clock, LoaderCircle, TriangleAlert, WifiOff, type LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useOfflineQueue } from '@/shared/offline/offline-provider';
import type { EntryState, StoredEntry } from '@/shared/offline/types';
import { cn } from '@/shared/lib/utils';

type View = { icon: LucideIcon; text: string; tone: 'ok' | 'info' | 'warn' | 'danger'; spin?: boolean };

const toneClass: Record<View['tone'], string> = {
    ok: 'border-success/40 bg-success/10 text-foreground',
    info: 'border-info/40 bg-info/10 text-foreground',
    warn: 'border-warning/40 bg-warning/10 text-foreground',
    danger: 'border-danger/40 bg-danger/10 text-foreground',
};

function useOnline(): boolean {
    const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine));

    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);

        window.addEventListener('online', up);
        window.addEventListener('offline', down);

        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, []);

    return online;
}

/**
 * The always-visible answer to "is my work safe?" for POS and Housekeeping
 * (docs/DESIGN/07-STATES-FEEDBACK.md): offline, syncing, waiting, needs attention,
 * session expired. Saved but unsent work is always shown as waiting, never as
 * done, and the "all synced" state appears only when nothing is waiting and the
 * network is reachable. Status is conveyed by text and icon, not color alone.
 */
export function SyncStatus({ className }: { className?: string }) {
    const { t, plural } = useTranslation();
    const queue = useOfflineQueue();
    const online = useOnline();
    const snapshot = queue.snapshot;

    let view: View;

    if (!queue.supported) {
        view = { icon: CircleAlert, text: t('offline.status.unsupported'), tone: 'warn' };
    } else if (snapshot === null) {
        return null;
    } else if (snapshot.blocked === 'sign-in') {
        view = { icon: TriangleAlert, text: t('offline.status.signIn'), tone: 'warn' };
    } else if (snapshot.blocked === 'property') {
        view = { icon: TriangleAlert, text: t('offline.status.property'), tone: 'warn' };
    } else if (snapshot.attention > 0) {
        view = { icon: TriangleAlert, text: plural('offline.status.attention', snapshot.attention), tone: 'danger' };
    } else if (!online || snapshot.networkDown) {
        const waiting = snapshot.pending + snapshot.sending;
        view = { icon: WifiOff, text: waiting > 0 ? `${t('offline.status.offline')} · ${plural('offline.status.pending', waiting)}` : t('offline.status.offline'), tone: 'warn' };
    } else if (snapshot.syncing || snapshot.sending > 0) {
        view = { icon: LoaderCircle, text: t('offline.status.syncing'), tone: 'info', spin: true };
    } else if (snapshot.pending > 0) {
        view = { icon: Clock, text: plural('offline.status.pending', snapshot.pending), tone: 'info' };
    } else if (snapshot.otherActors > 0) {
        view = { icon: Clock, text: plural('offline.status.otherUser', snapshot.otherActors), tone: 'info' };
    } else {
        view = { icon: CircleCheck, text: t('offline.status.synced'), tone: 'ok' };
    }

    const Icon = view.icon;

    return (
        <div
            aria-label={t('offline.status.label')}
            className={cn('inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm', toneClass[view.tone], className)}
            role="status"
        >
            <Icon aria-hidden="true" className={cn('size-4 shrink-0', view.spin && 'animate-spin motion-reduce:animate-none')} />
            <span>{view.text}</span>
        </div>
    );
}

const stateTone: Record<EntryState, StatusTone> = {
    pending: 'pending',
    sending: 'pending',
    accepted: 'success',
    conflict: 'warning',
    rejected: 'danger',
    stalled: 'warning',
};

/**
 * Entries held on this device with their state. Conflicts, rejections and
 * stalled entries stay until a person acts: retry (stalled) or remove from this
 * device after confirming, which never hides the server's own record.
 */
export function SyncPanel({ className }: { className?: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const queue = useOfflineQueue();
    const [removing, setRemoving] = useState<StoredEntry | null>(null);

    const entries = [...queue.entries].sort((a, b) => b.clientSequence - a.clientSequence);

    return (
        <section aria-label={t('offline.panel.title')} className={cn('flex flex-col gap-3', className)}>
            <h2 className="text-base font-semibold">{t('offline.panel.title')}</h2>

            {entries.length === 0 ? (
                <EmptyState title={t('offline.panel.empty')} />
            ) : (
                <ul className="divide-y divide-border rounded-lg border border-border bg-surface">
                    {entries.map((entry) => (
                        <li className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5" key={entry.operationId}>
                            <div className="min-w-0">
                                <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                    <span className="font-mono text-xs">{entry.type}</span>
                                    <StatusBadge label={t(`offline.entry.state.${entry.state}`)} tone={stateTone[entry.state]} />
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {t('offline.entry.recorded', { time: format.instant(entry.deviceTime) })}
                                </p>
                                {entry.lastCode && (entry.state === 'conflict' || entry.state === 'rejected' || entry.state === 'stalled') ? (
                                    <p className="mt-0.5 text-xs text-muted-foreground">{t('offline.entry.reason', { code: entry.lastCode })}</p>
                                ) : null}
                            </div>
                            <div className="flex gap-2">
                                {entry.state === 'stalled' ? (
                                    <Button onClick={() => void queue.retry(entry.operationId)} size="sm" type="button" variant="outline">
                                        {t('offline.entry.retry')}
                                    </Button>
                                ) : null}
                                {entry.state === 'conflict' || entry.state === 'rejected' ? (
                                    <Button onClick={() => setRemoving(entry)} size="sm" type="button" variant="outline">
                                        {t('offline.entry.remove')}
                                    </Button>
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={t('offline.remove.confirm')}
                consequence={t('offline.remove.consequence')}
                destructive
                onCancel={() => setRemoving(null)}
                onConfirm={() => {
                    if (removing !== null) {
                        void queue.remove(removing.operationId);
                    }

                    setRemoving(null);
                }}
                open={removing !== null}
                title={t('offline.remove.title')}
            />
        </section>
    );
}
