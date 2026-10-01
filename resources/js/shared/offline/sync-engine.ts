import { ApiError } from '../lib/api-error.ts'
import { backoffDelayMs } from './backoff.ts'
import { entryAad, type PayloadCipher } from './crypto.ts'
import { findForbiddenKey } from './sensitive.ts'
import type { QueueStore } from './store.ts'
import type { BatchItem, BatchRequest, BatchResponse, Blocked, EntryState, ItemResult, Session, StoredEntry, Transport } from './types.ts'
import { ulid } from './ulid.ts'

export interface EngineConfig {
    batchSize: number
    /** Transient server failures before an entry becomes `stalled` (needs a person). */
    maxAttempts: number
    /** How long an accepted receipt stays on the device. */
    acceptedRetentionMs: number
    heartbeatEveryMs: number
    /** Batches per flush, so one flush cannot run forever. */
    maxBatchesPerFlush: number
}

export const DEFAULT_ENGINE_CONFIG: EngineConfig = {
    batchSize: 25,
    maxAttempts: 10,
    acceptedRetentionMs: 24 * 60 * 60 * 1000,
    heartbeatEveryMs: 5 * 60 * 1000,
    maxBatchesPerFlush: 20,
}

export interface EngineDeps {
    store: QueueStore
    cipher: PayloadCipher
    transport: Transport
    deviceId: string
    /** The signed-in user and active property, or null when signed out. */
    session: () => Session | null
    now?: () => number
    random?: () => number
    config?: Partial<EngineConfig>
}

export interface EnqueueInput {
    type: string
    payload: Record<string, unknown>
    payloadVersion?: number
    /** The server version this change is based on, for conflict detection. */
    baseVersion?: number | null
}

export interface Snapshot {
    pending: number
    sending: number
    accepted: number
    conflict: number
    rejected: number
    stalled: number
    /** conflict + rejected + stalled: the entries that need a person. */
    attention: number
    /** Entries recorded by someone else on this shared device, waiting for their author. */
    otherActors: number
    oldestPendingSeconds: number
    blocked: Blocked
    /** The last request failed because the network was unreachable. */
    networkDown: boolean
    syncing: boolean
}

export class NoSessionError extends Error {
    constructor() {
        super('Sign in before recording an offline operation.')
        this.name = 'NoSessionError'
    }
}

export class ForbiddenPayloadError extends Error {
    readonly field: string

    constructor(field: string) {
        super(`The field "${field}" must never be stored offline.`)
        this.name = 'ForbiddenPayloadError'
        this.field = field
    }
}

const FINAL_REMOVABLE: readonly EntryState[] = ['accepted', 'conflict', 'rejected']

/** States in which the server has answered for the entry. They do not hold up the entries behind them. */
const FINISHED: readonly EntryState[] = ['accepted', 'conflict', 'rejected']

/**
 * The offline queue and its synchronization (docs/ARCHITECTURE/10).
 *
 *  - Every mutation is encrypted into the queue with a client-generated
 *    operation ID, then sent until the server answers for it. Resending is always
 *    safe: the server resolves a duplicate to the same logical result.
 *  - Nothing the server could not apply is discarded. Conflicts, rejections and
 *    entries that keep failing stay visible, with their payload, until a person
 *    removes them. Only an accepted receipt is purged, after a retention period.
 *  - A lost network is not the entry's fault and does not count against it;
 *    server-side transient failures do, up to a bound.
 *  - A device's entries are sent in order and a transient failure stops the run,
 *    so a later change can never overtake an earlier one.
 */
export class SyncEngine {
    private readonly store: QueueStore

    private readonly cipher: PayloadCipher

    private readonly transport: Transport

    private readonly deviceId: string

    private readonly session: () => Session | null

    private readonly now: () => number

    private readonly random: () => number

    private readonly config: EngineConfig

    private blocked: Blocked = null

    private networkDown = false

    private consecutiveNetworkFailures = 0

    private lastHeartbeatAt = 0

    private inFlight: Promise<void> | null = null

    constructor(deps: EngineDeps) {
        this.store = deps.store
        this.cipher = deps.cipher
        this.transport = deps.transport
        this.deviceId = deps.deviceId
        this.session = deps.session
        this.now = deps.now ?? (() => Date.now())
        this.random = deps.random ?? (() => Math.random())
        this.config = { ...DEFAULT_ENGINE_CONFIG, ...deps.config }
    }

    /** Records a mutation. Resolves once it is encrypted and durably queued. */
    async enqueue(input: EnqueueInput): Promise<StoredEntry> {
        const session = this.session()

        if (session === null) {
            throw new NoSessionError()
        }

        const forbidden = findForbiddenKey(input.payload)

        if (forbidden !== null) {
            throw new ForbiddenPayloadError(forbidden)
        }

        const now = this.now()
        const operationId = ulid(now)
        const identity = { operationId, propertyId: session.propertyId, actorId: session.actorId }

        return this.store.enqueue(
            {
                ...identity,
                type: input.type,
                deviceId: this.deviceId,
                deviceTime: new Date(now).toISOString(),
                baseVersion: input.baseVersion ?? null,
                payloadVersion: input.payloadVersion ?? 1,
                box: await this.cipher.encrypt(entryAad(identity), input.payload),
                createdAt: now,
            },
            now,
        )
    }

    /** Sends what is due. Concurrent calls share one run. */
    flush(): Promise<void> {
        this.inFlight ??= this.run().finally(() => {
            this.inFlight = null
        })

        return this.inFlight
    }

    /** After a crash or reload: an entry that was in flight goes back to waiting. Resending is safe. */
    async recoverInterrupted(): Promise<void> {
        for (const entry of await this.store.list()) {
            if (entry.state === 'sending') {
                await this.store.put({ ...entry, state: 'pending', updatedAt: this.now() })
            }
        }
    }

    /** Removes accepted receipts older than the retention period. Nothing else is ever purged. */
    async purge(): Promise<number> {
        const cutoff = this.now() - this.config.acceptedRetentionMs
        let removed = 0

        for (const entry of await this.store.list()) {
            if (entry.state === 'accepted' && entry.updatedAt < cutoff) {
                await this.store.remove(entry.operationId)
                removed += 1
            }
        }

        return removed
    }

    /** A person asks to try a stalled entry again. */
    async retry(operationId: string): Promise<void> {
        const entry = await this.store.get(operationId)

        if (entry === null || entry.state !== 'stalled') {
            return
        }

        await this.store.put({ ...entry, state: 'pending', attempts: 0, nextAttemptAt: this.now(), updatedAt: this.now() })
    }

    /**
     * A person removes a finished entry from this device. Only accepted, conflict
     * and rejected entries can be removed; anything still waiting or stalled holds
     * unsent data and is refused. The server keeps its own record of every conflict
     * and rejection, so removing the local copy does not hide it from reconciliation.
     */
    async remove(operationId: string): Promise<boolean> {
        const entry = await this.store.get(operationId)

        if (entry === null || !FINAL_REMOVABLE.includes(entry.state)) {
            return false
        }

        await this.store.remove(operationId)

        return true
    }

    /** The payload of an entry that still holds one, for review, or null. */
    async reveal(operationId: string): Promise<unknown | null> {
        const entry = await this.store.get(operationId)

        if (entry?.box == null) {
            return null
        }

        try {
            return await this.cipher.decrypt(entryAad(entry), entry.box)
        } catch {
            return null
        }
    }

    async snapshot(): Promise<Snapshot> {
        const session = this.session()
        const entries = await this.store.list()
        const counts: Record<EntryState, number> = { pending: 0, sending: 0, accepted: 0, conflict: 0, rejected: 0, stalled: 0 }
        let otherActors = 0
        let oldest = Number.POSITIVE_INFINITY

        for (const entry of entries) {
            if (session !== null && (entry.actorId !== session.actorId || entry.propertyId !== session.propertyId)) {
                if (entry.state !== 'accepted') {
                    otherActors += 1
                }

                continue
            }

            counts[entry.state] += 1

            if (entry.state === 'pending' || entry.state === 'sending' || entry.state === 'stalled') {
                oldest = Math.min(oldest, entry.createdAt)
            }
        }

        return {
            ...counts,
            attention: counts.conflict + counts.rejected + counts.stalled,
            otherActors,
            oldestPendingSeconds: Number.isFinite(oldest) ? Math.max(0, Math.floor((this.now() - oldest) / 1000)) : 0,
            blocked: this.blocked,
            networkDown: this.networkDown,
            syncing: this.inFlight !== null,
        }
    }

    /** When the next delayed entry becomes due, or null. A scheduler sleeps until then. */
    async nextWakeAt(): Promise<number | null> {
        const session = this.session()
        let next: number | null = null

        for (const entry of await this.store.list()) {
            if (entry.state === 'pending' && session !== null && entry.actorId === session.actorId && entry.propertyId === session.propertyId) {
                next = next === null ? entry.nextAttemptAt : Math.min(next, entry.nextAttemptAt)
            }
        }

        return next
    }

    // ------------------------------------------------------------------ internals

    private async run(): Promise<void> {
        const session = this.session()

        if (session === null) {
            return
        }

        for (let batch = 0; batch < this.config.maxBatchesPerFlush; batch++) {
            const due = await this.due(session)

            if (due.length === 0) {
                await this.maybeHeartbeat(session)

                return
            }

            const proceed = await this.sendBatch(session, due.slice(0, this.config.batchSize))

            if (!proceed) {
                return
            }
        }
    }

    /**
     * The entries that may be sent now, strictly in the device's own order.
     *
     * Entries are taken from the front of the queue while they are waiting and
     * due. The first entry that is still unfinished but not due (delayed after a
     * failure, in flight, or stalled) stops the line: nothing behind it may be
     * sent, because the server could apply a later change before an earlier one
     * (for example paying a bill before it was opened). Finished entries
     * (accepted, conflict, rejected) never block; handlers decide whether a
     * dependent item is still valid.
     */
    private async due(session: Session): Promise<StoredEntry[]> {
        const now = this.now()
        const ready: StoredEntry[] = []

        for (const entry of await this.store.list()) {
            if (entry.actorId !== session.actorId || entry.propertyId !== session.propertyId) {
                continue
            }

            if (FINISHED.includes(entry.state)) {
                continue
            }

            if (entry.state === 'pending' && entry.nextAttemptAt <= now) {
                ready.push(entry)

                continue
            }

            break
        }

        return ready
    }

    /** @returns whether the run may continue with the next batch */
    private async sendBatch(session: Session, entries: StoredEntry[]): Promise<boolean> {
        const items: BatchItem[] = []
        const sending: StoredEntry[] = []

        for (const entry of entries) {
            const payload = await this.decryptPayload(entry)

            if (payload === undefined) {
                // The ciphertext can no longer be read (key lost, storage tampered with). Never drop it silently.
                await this.store.put({ ...entry, state: 'rejected', lastCode: 'local_decrypt_failed', updatedAt: this.now() })

                continue
            }

            items.push(this.toItem(entry, payload))
            sending.push(entry)
            await this.store.put({ ...entry, state: 'sending', updatedAt: this.now() })
        }

        if (items.length === 0) {
            return true
        }

        let response: BatchResponse

        try {
            response = await this.transport(await this.request(session, items))
        } catch (error) {
            return this.handleFailure(session, sending, error)
        }

        this.networkDown = false
        this.consecutiveNetworkFailures = 0
        this.blocked = null
        this.lastHeartbeatAt = this.now()

        return this.applyResults(sending, response)
    }

    private async handleFailure(session: Session, sending: StoredEntry[], error: unknown): Promise<boolean> {
        const kind = error instanceof ApiError ? error.failure.kind : 'server-error'
        const status = error instanceof ApiError ? error.failure.status : null

        if (kind === 'offline') {
            this.networkDown = true
            this.consecutiveNetworkFailures += 1
            // The network, not the entry, is at fault: no attempt is counted.
            await this.requeue(sending, backoffDelayMs(this.consecutiveNetworkFailures, this.random), false)

            return false
        }

        if (kind === 'unauthenticated' || kind === 'session-expired') {
            this.blocked = 'sign-in'
            await this.requeue(sending, 0, false)

            return false
        }

        if (kind === 'forbidden') {
            this.blocked = 'property'
            await this.requeue(sending, 0, false)

            return false
        }

        // A malformed or oversized batch: find the offending item by sending them one at a time.
        if ((kind === 'validation' || status === 413) && sending.length > 1) {
            await this.requeue(sending, 0, false)

            return this.isolate(session, sending)
        }

        if (kind === 'validation' || status === 413) {
            const [only] = sending

            if (only !== undefined) {
                await this.store.put({ ...only, state: 'rejected', lastCode: 'invalid_batch', updatedAt: this.now() })
            }

            return true
        }

        // Rate limit, 5xx, anything else: a transient server failure that counts against the entries.
        await this.requeueCounting(sending)

        return false
    }

    private async isolate(session: Session, entries: StoredEntry[]): Promise<boolean> {
        for (const entry of entries) {
            const current = await this.store.get(entry.operationId)

            if (current === null || current.state !== 'pending') {
                continue
            }

            if (!(await this.sendBatch(session, [current]))) {
                return false
            }
        }

        return true
    }

    private async applyResults(sent: StoredEntry[], response: BatchResponse): Promise<boolean> {
        // The server normalizes ULIDs to lower case; match without regard to case.
        const byId = new Map<string, ItemResult>(response.results.map((result) => [result.operation_id.toLowerCase(), result]))
        let proceed = true

        for (const entry of sent) {
            const fresh = (await this.store.get(entry.operationId)) ?? entry
            const result = byId.get(entry.operationId.toLowerCase())
            const now = this.now()

            switch (result?.status) {
                case 'accepted':
                    await this.store.put({ ...fresh, state: 'accepted', box: null, serverVersion: result.server_version, lastCode: null, lastAction: null, updatedAt: now })
                    break
                case 'conflict':
                case 'rejected':
                    await this.store.put({ ...fresh, state: result.status, serverVersion: result.server_version, lastCode: result.code, lastAction: result.action, updatedAt: now })
                    break
                case 'deferred':
                    // Not attempted, because an earlier item of this device is pending. Not the entry's fault.
                    await this.store.put({ ...fresh, state: 'pending', nextAttemptAt: now, updatedAt: now })
                    proceed = false
                    break
                default:
                    // retry_later, a missing result, or a status this client does not know: transient, never a drop.
                    await this.requeueCounting([fresh], result?.code ?? 'no_result')
                    proceed = false
            }
        }

        return proceed
    }

    private async requeue(entries: StoredEntry[], delayMs: number, countAttempt: boolean): Promise<void> {
        for (const entry of entries) {
            const current = (await this.store.get(entry.operationId)) ?? entry

            await this.store.put({
                ...current,
                state: 'pending',
                attempts: current.attempts + (countAttempt ? 1 : 0),
                nextAttemptAt: this.now() + delayMs,
                updatedAt: this.now(),
            })
        }
    }

    private async requeueCounting(entries: StoredEntry[], code: string | null = null): Promise<void> {
        for (const entry of entries) {
            const current = (await this.store.get(entry.operationId)) ?? entry
            const attempts = current.attempts + 1
            const stalled = attempts >= this.config.maxAttempts

            await this.store.put({
                ...current,
                state: stalled ? 'stalled' : 'pending',
                attempts,
                nextAttemptAt: this.now() + backoffDelayMs(attempts, this.random),
                lastCode: code ?? current.lastCode,
                updatedAt: this.now(),
            })
        }
    }

    private async decryptPayload(entry: StoredEntry): Promise<unknown | undefined> {
        if (entry.box === null) {
            return undefined
        }

        try {
            return await this.cipher.decrypt(entryAad(entry), entry.box)
        } catch {
            return undefined
        }
    }

    private toItem(entry: StoredEntry, payload: unknown): BatchItem {
        return {
            operation_id: entry.operationId,
            type: entry.type,
            property_id: entry.propertyId,
            device_id: entry.deviceId,
            actor_id: entry.actorId,
            client_sequence: entry.clientSequence,
            device_time: entry.deviceTime,
            base_version: entry.baseVersion,
            payload_version: entry.payloadVersion,
            payload,
        }
    }

    private async request(session: Session, items: BatchItem[]): Promise<BatchRequest> {
        const snapshot = await this.snapshotFor(session)

        return {
            device_id: this.deviceId,
            items,
            client_status: { pending: snapshot.pending, oldest_pending_seconds: snapshot.oldestPendingSeconds },
        }
    }

    private async snapshotFor(session: Session): Promise<{ pending: number; oldestPendingSeconds: number }> {
        let pending = 0
        let oldest = Number.POSITIVE_INFINITY

        for (const entry of await this.store.list()) {
            if (entry.actorId === session.actorId && entry.propertyId === session.propertyId && ['pending', 'sending', 'stalled'].includes(entry.state)) {
                pending += 1
                oldest = Math.min(oldest, entry.createdAt)
            }
        }

        return { pending, oldestPendingSeconds: Number.isFinite(oldest) ? Math.max(0, Math.floor((this.now() - oldest) / 1000)) : 0 }
    }

    /** An empty batch tells the server this device's queue depth, so a stuck device is noticed. */
    private async maybeHeartbeat(session: Session): Promise<void> {
        const status = await this.snapshotFor(session)

        if (status.pending === 0 || this.now() - this.lastHeartbeatAt < this.config.heartbeatEveryMs) {
            return
        }

        this.lastHeartbeatAt = this.now()

        try {
            await this.transport(await this.request(session, []))
            this.networkDown = false
        } catch (error) {
            if (error instanceof ApiError && error.failure.kind === 'offline') {
                this.networkDown = true
            }
        }
    }
}
