import { createAesGcmCipher, generateDeviceKey, type PayloadCipher } from './crypto.ts'
import type { SnapshotRecord, SnapshotStore } from './page-snapshot.ts'
import type { QueueStore } from './store.ts'
import type { EnqueueDraft, StoredEntry } from './types.ts'
import { ulid } from './ulid.ts'

const DB_NAME = 'innsync-offline'
const ENTRIES = 'entries'
const META = 'meta'
const SNAPSHOTS = 'snapshots'
const CHANNEL = 'innsync-offline-queue'

function request<T>(req: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result)
        req.onerror = () => reject(req.error)
    })
}

function done(tx: IDBTransaction): Promise<void> {
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve()
        tx.onerror = () => reject(tx.error)
        tx.onabort = () => reject(tx.error)
    })
}

/**
 * The queue in IndexedDB. Payloads are encrypted before they get here; only
 * non-sensitive metadata is stored in the clear so entries can be queried.
 * `enqueue` assigns the next sequence and writes the entry in one transaction,
 * so two tabs can never hand out the same sequence number. Changes made in one
 * tab are announced to the others over a BroadcastChannel.
 */
export class IdbQueueStore implements QueueStore {
    private listeners = new Set<() => void>()

    private channel: BroadcastChannel | null

    constructor(private readonly db: IDBDatabase) {
        this.channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(CHANNEL)
        this.channel?.addEventListener('message', () => this.notify())
    }

    async enqueue(draft: EnqueueDraft, now: number): Promise<StoredEntry> {
        const tx = this.db.transaction([ENTRIES, META], 'readwrite')
        const meta = tx.objectStore(META)
        const current = (await request(meta.get('sequence'))) as { key: string; value: number } | undefined
        const sequence = (current?.value ?? 0) + 1

        const entry: StoredEntry = {
            operationId: draft.operationId,
            type: draft.type,
            propertyId: draft.propertyId,
            actorId: draft.actorId,
            deviceId: draft.deviceId,
            clientSequence: sequence,
            deviceTime: draft.deviceTime,
            baseVersion: draft.baseVersion,
            payloadVersion: draft.payloadVersion,
            state: 'pending',
            attempts: 0,
            nextAttemptAt: now,
            createdAt: draft.createdAt,
            updatedAt: now,
            lastCode: null,
            lastAction: null,
            serverVersion: null,
            box: draft.box,
        }

        meta.put({ key: 'sequence', value: sequence })
        tx.objectStore(ENTRIES).put(entry)
        await done(tx)
        this.announce()

        return entry
    }

    async get(operationId: string): Promise<StoredEntry | null> {
        const found = await request(this.db.transaction(ENTRIES).objectStore(ENTRIES).get(operationId))

        return (found as StoredEntry | undefined) ?? null
    }

    async list(): Promise<StoredEntry[]> {
        const all = (await request(this.db.transaction(ENTRIES).objectStore(ENTRIES).getAll())) as StoredEntry[]

        return all.sort((a, b) => a.clientSequence - b.clientSequence)
    }

    async put(entry: StoredEntry): Promise<void> {
        const tx = this.db.transaction(ENTRIES, 'readwrite')
        tx.objectStore(ENTRIES).put(entry)
        await done(tx)
        this.announce()
    }

    async remove(operationId: string): Promise<void> {
        const tx = this.db.transaction(ENTRIES, 'readwrite')
        tx.objectStore(ENTRIES).delete(operationId)
        await done(tx)
        this.announce()
    }

    subscribe(listener: () => void): () => void {
        this.listeners.add(listener)

        return () => {
            this.listeners.delete(listener)
        }
    }

    private announce(): void {
        this.notify()
        this.channel?.postMessage('changed')
    }

    private notify(): void {
        for (const listener of this.listeners) {
            listener()
        }
    }
}

/** The pages kept for opening from nothing (page-snapshot.ts). Ciphertext only; the path and the ids are in the clear so a record can be found and bound. */
export class IdbSnapshotStore implements SnapshotStore {
    constructor(private readonly db: IDBDatabase) {}

    async put(record: SnapshotRecord): Promise<void> {
        const tx = this.db.transaction(SNAPSHOTS, 'readwrite')
        tx.objectStore(SNAPSHOTS).put(record)
        await done(tx)
    }

    async get(path: string): Promise<SnapshotRecord | null> {
        return ((await request(this.db.transaction(SNAPSHOTS).objectStore(SNAPSHOTS).get(path))) as SnapshotRecord | undefined) ?? null
    }

    async clear(): Promise<void> {
        const tx = this.db.transaction(SNAPSHOTS, 'readwrite')
        tx.objectStore(SNAPSHOTS).clear()
        await done(tx)
    }
}

export interface OfflineRuntime {
    store: QueueStore
    snapshots: SnapshotStore
    cipher: PayloadCipher
    deviceId: string
}

/** Everything the offline queue needs from the browser, or why it cannot work here. */
export interface OfflineSupport {
    supported: boolean
    indexedDb: boolean
    webCrypto: boolean
    secureContext: boolean
}

export function offlineSupport(): OfflineSupport {
    const indexedDb = typeof indexedDB !== 'undefined'
    const webCrypto = typeof globalThis.crypto?.subtle !== 'undefined'
    const secureContext = typeof isSecureContext === 'boolean' ? isSecureContext : false

    return { supported: indexedDb && webCrypto && secureContext, indexedDb, webCrypto, secureContext }
}

/**
 * Asks the browser not to evict this origin's storage under pressure. Without
 * it, an unsynced queue could be deleted by the browser. Best effort: the answer
 * is shown on the diagnostic page so devices that refuse can be identified.
 */
export async function requestPersistentStorage(): Promise<boolean | null> {
    if (typeof navigator === 'undefined' || navigator.storage?.persist === undefined) {
        return null
    }

    try {
        return (await navigator.storage.persisted()) || (await navigator.storage.persist())
    } catch {
        return null
    }
}

function openDatabase(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open(DB_NAME, 2)

        open.onupgradeneeded = () => {
            const names = open.result.objectStoreNames

            if (!names.contains(ENTRIES)) open.result.createObjectStore(ENTRIES, { keyPath: 'operationId' })
            if (!names.contains(META)) open.result.createObjectStore(META, { keyPath: 'key' })
            if (!names.contains(SNAPSHOTS)) open.result.createObjectStore(SNAPSHOTS, { keyPath: 'path' })
        }
        open.onsuccess = () => resolve(open.result)
        open.onerror = () => reject(open.error)
    })
}

/** Opens the queue, creating this device's identifier and encryption key on first use. */
export async function openOfflineRuntime(): Promise<OfflineRuntime> {
    const db = await openDatabase()
    const meta = (key: string) => request(db.transaction(META).objectStore(META).get(key)) as Promise<{ key: string; value: unknown } | undefined>
    const save = async (key: string, value: unknown) => {
        const tx = db.transaction(META, 'readwrite')
        tx.objectStore(META).put({ key, value })
        await done(tx)
    }

    let deviceId = (await meta('deviceId'))?.value as string | undefined

    if (deviceId === undefined) {
        // A random identifier, not a hardware fingerprint: it only separates this browser's sequence from others'.
        deviceId = ulid()
        await save('deviceId', deviceId)
    }

    let key = (await meta('deviceKey'))?.value as CryptoKey | undefined

    if (key === undefined) {
        key = await generateDeviceKey()
        await save('deviceKey', key)
    }

    return { store: new IdbQueueStore(db), snapshots: new IdbSnapshotStore(db), cipher: createAesGcmCipher(key), deviceId }
}
