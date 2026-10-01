import { usePage } from '@inertiajs/react';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';

import { apiRequest } from '@/shared/api/http';
import { offlineSupport, openOfflineRuntime, requestPersistentStorage } from '@/shared/offline/idb-store';
import { SyncEngine, type EnqueueInput, type Snapshot } from '@/shared/offline/sync-engine';
import type { BatchRequest, BatchResponse, Session, StoredEntry } from '@/shared/offline/types';

export interface OfflineQueue {
    /** The browser can keep an encrypted queue (IndexedDB, WebCrypto, secure context). */
    supported: boolean;
    /** The queue is open and a user with an active property is signed in. */
    ready: boolean;
    snapshot: Snapshot | null;
    /** Entries of the signed-in user in the active property (accepted receipts included). */
    entries: StoredEntry[];
    persistentStorage: boolean | null;
    enqueue: (input: EnqueueInput) => Promise<StoredEntry>;
    flush: () => Promise<void>;
    retry: (operationId: string) => Promise<void>;
    remove: (operationId: string) => Promise<boolean>;
    reveal: (operationId: string) => Promise<unknown | null>;
}

const OfflineContext = createContext<OfflineQueue | null>(null);

const transport = (request: BatchRequest) =>
    apiRequest<BatchResponse>('/sync/batch', { method: 'POST', body: request });

/**
 * Owns the offline queue for the signed-in user and active property and keeps it
 * moving: sync on start, when the network returns, when the tab becomes visible,
 * after each change, and when a delayed entry falls due. It is mounted once as a
 * persistent Inertia layout, so it survives navigation. No service worker is used
 * (docs/DESIGN/08): writes are replayed only by this code, entry by entry.
 */
export function OfflineProvider({ children }: { children: ReactNode }) {
    const props = usePage().props;
    const auth = props.auth ?? null;
    const sessionRef = useRef<Session | null>(null);
    sessionRef.current = auth === null ? null : { actorId: auth.userId, propertyId: auth.propertyId };

    const support = useMemo(() => offlineSupport(), []);
    const [engine, setEngine] = useState<SyncEngine | null>(null);
    const [snapshot, setSnapshot] = useState<Snapshot | null>(null);
    const [entries, setEntries] = useState<StoredEntry[]>([]);
    const [persistent, setPersistent] = useState<boolean | null>(null);
    const storeRef = useRef<Awaited<ReturnType<typeof openOfflineRuntime>>['store'] | null>(null);
    const signedIn = auth !== null;

    const refresh = useCallback(async (current: SyncEngine) => {
        setSnapshot(await current.snapshot());
        const session = sessionRef.current;
        const all = (await storeRef.current?.list()) ?? [];
        setEntries(session === null ? [] : all.filter((e) => e.actorId === session.actorId && e.propertyId === session.propertyId));
    }, []);

    // Open the queue once a user is signed in on a capable browser.
    useEffect(() => {
        if (!signedIn || !support.supported) {
            return;
        }

        let cancelled = false;

        void (async () => {
            const runtime = await openOfflineRuntime();

            if (cancelled) {
                return;
            }

            storeRef.current = runtime.store;
            const created = new SyncEngine({
                store: runtime.store,
                cipher: runtime.cipher,
                transport,
                deviceId: runtime.deviceId,
                session: () => sessionRef.current,
            });

            await created.recoverInterrupted();
            await created.purge();
            setPersistent(await requestPersistentStorage());
            setEngine(created);
        })();

        return () => {
            cancelled = true;
        };
    }, [signedIn, support.supported]);

    // Keep the view fresh and the queue moving.
    useEffect(() => {
        if (engine === null) {
            return;
        }

        let timer: ReturnType<typeof setTimeout> | undefined;
        let stopped = false;

        const schedule = async () => {
            const wake = await engine.nextWakeAt();

            if (stopped) {
                return;
            }

            clearTimeout(timer);
            // Wake at the next due entry, but at least every minute so a heartbeat and recovery are never missed.
            timer = setTimeout(() => void run(), wake === null ? 60_000 : Math.min(60_000, Math.max(250, wake - Date.now())));
        };

        const run = async () => {
            await engine.flush();
            await refresh(engine);
            await schedule();
        };

        const unsubscribe = storeRef.current?.subscribe(() => void refresh(engine).then(schedule));
        const onOnline = () => void run();
        const onVisible = () => document.visibilityState === 'visible' && void run();

        window.addEventListener('online', onOnline);
        document.addEventListener('visibilitychange', onVisible);
        void run();

        return () => {
            stopped = true;
            clearTimeout(timer);
            unsubscribe?.();
            window.removeEventListener('online', onOnline);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [engine, refresh, auth?.userId, auth?.propertyId]);

    const value = useMemo<OfflineQueue>(
        () => ({
            supported: support.supported,
            ready: engine !== null && signedIn,
            snapshot,
            entries,
            persistentStorage: persistent,
            enqueue: async (input) => {
                if (engine === null) {
                    throw new Error('The offline queue is not ready.');
                }

                const entry = await engine.enqueue(input);
                await refresh(engine);
                void engine.flush().then(() => refresh(engine));

                return entry;
            },
            flush: async () => {
                if (engine !== null) {
                    await engine.flush();
                    await refresh(engine);
                }
            },
            retry: async (operationId) => {
                if (engine !== null) {
                    await engine.retry(operationId);
                    await engine.flush();
                    await refresh(engine);
                }
            },
            remove: async (operationId) => {
                const removed = engine === null ? false : await engine.remove(operationId);

                if (engine !== null) {
                    await refresh(engine);
                }

                return removed;
            },
            reveal: async (operationId) => (engine === null ? null : engine.reveal(operationId)),
        }),
        [engine, entries, persistent, refresh, signedIn, snapshot, support.supported],
    );

    return <OfflineContext.Provider value={value}>{children}</OfflineContext.Provider>;
}

const unavailable: OfflineQueue = {
    supported: false,
    ready: false,
    snapshot: null,
    entries: [],
    persistentStorage: null,
    enqueue: () => Promise.reject(new Error('The offline queue is not available.')),
    flush: () => Promise.resolve(),
    retry: () => Promise.resolve(),
    remove: () => Promise.resolve(false),
    reveal: () => Promise.resolve(null),
};

export function useOfflineQueue(): OfflineQueue {
    return useContext(OfflineContext) ?? unavailable;
}
