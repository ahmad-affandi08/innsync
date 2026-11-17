/** The key of the last request that did not succeed, with what was asked. */
export type PendingIntent = { fingerprint: string; key: string } | null

/**
 * One key per user intent (NFR-18): the same request sent again after a failure or a lost answer carries the key of the first try, so the
 * server can tell it is one intent; another request, or the same one after it succeeded, gets the new key it was given.
 */
export function intentKey(pending: PendingIntent, fingerprint: string, fresh: string): { key: string; pending: PendingIntent } {
    if (pending !== null && pending.fingerprint === fingerprint) {
        return { key: pending.key, pending }
    }

    return { key: fresh, pending: { fingerprint, key: fresh } }
}
