import type { EnqueueDraft, StoredEntry } from './types.ts'

/** Persistence for the queue. Implementations must make `enqueue` atomic (sequence + entry together). */
export interface QueueStore {
    /** Assigns the next per-device sequence and stores the entry as `pending`, in one atomic step. */
    enqueue(draft: EnqueueDraft, now: number): Promise<StoredEntry>
    get(operationId: string): Promise<StoredEntry | null>
    /** All entries, ordered by client sequence. */
    list(): Promise<StoredEntry[]>
    put(entry: StoredEntry): Promise<void>
    remove(operationId: string): Promise<void>
    /** Calls back after any change, including one made by another tab. Returns an unsubscribe. */
    subscribe(listener: () => void): () => void
}

export class MemoryQueueStore implements QueueStore {
    private entries = new Map<string, StoredEntry>()

    private sequence = 0

    private listeners = new Set<() => void>()

    async enqueue(draft: EnqueueDraft, now: number): Promise<StoredEntry> {
        this.sequence += 1

        const entry: StoredEntry = {
            operationId: draft.operationId,
            type: draft.type,
            propertyId: draft.propertyId,
            actorId: draft.actorId,
            deviceId: draft.deviceId,
            clientSequence: this.sequence,
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

        this.entries.set(entry.operationId, entry)
        this.emit()

        return structuredClone(entry)
    }

    async get(operationId: string): Promise<StoredEntry | null> {
        const entry = this.entries.get(operationId)

        return entry === undefined ? null : structuredClone(entry)
    }

    async list(): Promise<StoredEntry[]> {
        return [...this.entries.values()].sort((a, b) => a.clientSequence - b.clientSequence).map((entry) => structuredClone(entry))
    }

    async put(entry: StoredEntry): Promise<void> {
        this.entries.set(entry.operationId, structuredClone(entry))
        this.emit()
    }

    async remove(operationId: string): Promise<void> {
        this.entries.delete(operationId)
        this.emit()
    }

    subscribe(listener: () => void): () => void {
        this.listeners.add(listener)

        return () => {
            this.listeners.delete(listener)
        }
    }

    private emit(): void {
        for (const listener of this.listeners) {
            listener()
        }
    }
}
