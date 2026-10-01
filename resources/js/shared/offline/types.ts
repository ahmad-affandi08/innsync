/**
 * Offline queue types (TASK-FND-017, NFR-04, NFR-18, NFR-19, BR-005, BR-010).
 *
 * An entry is a mutation recorded while the network was unavailable. It is kept
 * until the server has answered for it, and an entry the server could not apply
 * is never removed automatically: it stays visible until a person deals with it.
 */
export type EntryState =
    /** Waiting to be sent (possibly after a delay). */
    | 'pending'
    /** In flight. Reset to `pending` after a crash; resending is safe (server idempotency). */
    | 'sending'
    /** The server applied it (or had already applied it). Payload is wiped. */
    | 'accepted'
    /** The server's state differs; a person must decide. Payload kept. */
    | 'conflict'
    /** Not valid under current rules, or refused. Payload kept. */
    | 'rejected'
    /** Too many transient failures. Payload kept; a person can retry. */
    | 'stalled'

export interface EntryMeta {
    /** Client-generated ULID: the idempotency key on the server. */
    operationId: string
    type: string
    propertyId: string
    actorId: string
    deviceId: string
    /** Monotonic per device; the server applies a device's items in this order. */
    clientSequence: number
    /** Device clock at creation, ISO 8601 UTC. Untrusted by the server; kept for forensics. */
    deviceTime: string
    baseVersion: number | null
    payloadVersion: number
    state: EntryState
    /** Transient server failures so far (network loss does not count). */
    attempts: number
    /** Earliest time (epoch ms) to send again. */
    nextAttemptAt: number
    createdAt: number
    updatedAt: number
    lastCode: string | null
    lastAction: string | null
    serverVersion: number | null
}

export interface CipherBox {
    iv: Uint8Array<ArrayBuffer>
    data: Uint8Array<ArrayBuffer>
}

/** What is persisted: non-sensitive metadata in the clear (needed to query) and the payload encrypted. */
export interface StoredEntry extends EntryMeta {
    box: CipherBox | null
}

export interface EnqueueDraft {
    operationId: string
    type: string
    propertyId: string
    actorId: string
    deviceId: string
    deviceTime: string
    baseVersion: number | null
    payloadVersion: number
    box: CipherBox
    createdAt: number
}

export interface BatchItem {
    operation_id: string
    type: string
    property_id: string
    device_id: string
    actor_id: string
    client_sequence: number
    device_time: string
    base_version: number | null
    payload_version: number
    payload: unknown
}

export interface BatchRequest {
    device_id: string
    items: BatchItem[]
    client_status: { pending: number; oldest_pending_seconds: number }
}

export type ItemStatus = 'accepted' | 'conflict' | 'rejected' | 'retry_later' | 'deferred'

export interface ItemResult {
    operation_id: string
    status: ItemStatus
    replayed: boolean
    server_version: number | null
    result: Record<string, unknown>
    code: string | null
    action: string | null
}

export interface BatchResponse {
    server_time: string
    results: ItemResult[]
}

/** Sends a batch. Rejects with an `ApiError` for every failure to obtain a response (offline, 401, 5xx...). */
export type Transport = (request: BatchRequest) => Promise<BatchResponse>

export interface Session {
    actorId: string
    propertyId: string
}

/** Why syncing is paused although the device may be online. */
export type Blocked = 'sign-in' | 'property' | null
