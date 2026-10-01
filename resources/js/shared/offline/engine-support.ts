import { ApiError, offlineFailure, failureFromResponse } from '../lib/api-error.ts'
import { createAesGcmCipher, generateDeviceKey } from './crypto.ts'
import { MemoryQueueStore } from './store.ts'
import { SyncEngine, type EngineConfig } from './sync-engine.ts'
import type { BatchRequest, BatchResponse, ItemResult, Session } from './types.ts'

/**
 * Test harness: a fake server that behaves like the real one where it matters
 * (idempotent per operation ID, per-item statuses), plus scripted network faults.
 */
export interface ServerFaults {
    /** The server applies the batch, but the response is lost (the dangerous case for duplicates). */
    loseResponses: number
    /** The network is unreachable before the server sees anything. */
    offline: boolean
    /** HTTP failure for the whole request, e.g. 401, 419, 422, 500. */
    httpStatus: number | null
}

export class FakeServer {
    readonly applied = new Map<string, ItemResult>()

    /** Business effects performed: must equal the number of distinct accepted operations. */
    effects = 0

    readonly batches: BatchRequest[] = []

    faults: ServerFaults = { loseResponses: 0, offline: false, httpStatus: null }

    /** Operation IDs whose payload.mode is 'later' keep returning retry_later while this is true. */
    transient = true

    private process(item: BatchRequest['items'][number], haltedDevices: Set<string>): ItemResult {
        const known = this.applied.get(item.operation_id.toLowerCase())

        if (known !== undefined) {
            return { ...known, replayed: true }
        }

        if (haltedDevices.has(item.device_id)) {
            return result(item.operation_id.toLowerCase(), 'deferred', 'earlier_item_pending')
        }

        const mode = (item.payload as { mode?: string }).mode ?? 'ok'

        if (mode === 'later' && this.transient) {
            haltedDevices.add(item.device_id)

            return result(item.operation_id.toLowerCase(), 'retry_later', 'server_error')
        }

        const id = item.operation_id.toLowerCase()
        const outcome =
            mode === 'conflict'
                ? result(id, 'conflict', 'stale_state', { action: 'review', version: 7 })
                : mode === 'reject'
                  ? result(id, 'rejected', 'closed_shift')
                  : result(id, 'accepted', null, { version: 1 })

        if (outcome.status === 'accepted') {
            this.effects += 1
        }

        this.applied.set(id, outcome)

        return outcome
    }

    transport = async (request: BatchRequest): Promise<BatchResponse> => {
        this.batches.push(structuredClone(request))

        if (this.faults.offline) {
            throw new ApiError(offlineFailure())
        }

        if (this.faults.httpStatus !== null) {
            throw new ApiError(failureFromResponse(this.faults.httpStatus, null))
        }

        // A batch with any malformed item is refused as a whole, like the real endpoint.
        if (request.items.some((item) => (item.payload as { mode?: string }).mode === 'malformed')) {
            throw new ApiError(failureFromResponse(422, { error: { code: 'validation_failed', message: 'x', status: 422, retryable: false, correlation_id: 'c' } }))
        }

        const halted = new Set<string>()
        const results = [...request.items]
            .sort((a, b) => a.client_sequence - b.client_sequence)
            .map((item) => this.process(item, halted))
        const ordered = request.items.map((item) => results.find((r) => r.operation_id === item.operation_id.toLowerCase()) as ItemResult)

        if (this.faults.loseResponses > 0) {
            this.faults.loseResponses -= 1

            throw new ApiError(offlineFailure())
        }

        return { server_time: new Date().toISOString(), results: ordered }
    }
}

function result(operationId: string, status: ItemResult['status'], code: string | null, extra: { action?: string; version?: number } = {}): ItemResult {
    return {
        operation_id: operationId,
        status,
        replayed: false,
        server_version: extra.version ?? null,
        result: {},
        code,
        action: extra.action ?? null,
    }
}

export interface Harness {
    engine: SyncEngine
    store: MemoryQueueStore
    server: FakeServer
    clock: { now: number }
    session: { current: Session | null }
}

export const ACTOR_A = '01ARZ3NDEKTSV4RRFFQ69G5FC1'
export const ACTOR_B = '01ARZ3NDEKTSV4RRFFQ69G5FC2'
export const PROPERTY = '01ARZ3NDEKTSV4RRFFQ69G5FAV'
export const DEVICE = '01ARZ3NDEKTSV4RRFFQ69G5FD1'

export async function harness(config: Partial<EngineConfig> = {}): Promise<Harness> {
    const store = new MemoryQueueStore()
    const server = new FakeServer()
    const clock = { now: 1_790_000_000_000 }
    const session: { current: Session | null } = { current: { actorId: ACTOR_A, propertyId: PROPERTY } }
    const cipher = createAesGcmCipher(await generateDeviceKey())

    const engine = new SyncEngine({
        store,
        cipher,
        transport: server.transport,
        deviceId: DEVICE,
        session: () => session.current,
        now: () => clock.now,
        random: () => 0.5,
        config,
    })

    return { engine, store, server, clock, session }
}
