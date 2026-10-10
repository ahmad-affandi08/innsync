import type { PayloadCipher } from './crypto.ts'
import type { CipherBox } from './types.ts'

/**
 * The screens that can be opened from nothing when the network is down: the restaurant register and the housekeeper's rooms. Each time one is opened with the network up, the page the server sent
 * (its menu, tables or rooms) is kept on the device, encrypted with the device key that also protects the offline queue. Public/sw.js carries the same list; a test keeps them equal.
 */
export const OFFLINE_PAGES = ['/fnb/register', '/housekeeping/my-rooms'] as const

/** An older picture of a page is not used: prices and room states move. After this the person must open the page online again. */
export const SNAPSHOT_MAX_AGE_MS = 48 * 60 * 60 * 1000

export interface SnapshotRecord {
    path: string
    userId: string
    propertyId: string
    savedAt: number
    box: CipherBox
}

export interface SnapshotStore {
    put(record: SnapshotRecord): Promise<void>
    get(path: string): Promise<SnapshotRecord | null>
    clear(): Promise<void>
}

/** The page the server sends, as far as the snapshot needs it. */
export interface InertiaPageLike {
    component: string
    url: string
    props: Record<string, unknown> & { auth?: { userId: string; propertyId: string } | null }
    [key: string]: unknown
}

export function pathOf(url: string): string {
    const end = url.search(/[?#]/)

    return end === -1 ? url : url.slice(0, end)
}

export function isOfflinePage(url: string): boolean {
    return (OFFLINE_PAGES as readonly string[]).includes(pathOf(url))
}

/** Binds a ciphertext to the page, the person and the property it was made for, so it cannot be moved onto another. */
export function snapshotAad(path: string, userId: string, propertyId: string, savedAt: number): string {
    return `page|${path}|${userId}|${propertyId}|${savedAt}`
}

/** Keeps the page if it is one of the offline pages and someone is signed in. Returns whether it was kept. */
export async function saveSnapshot(deps: { store: SnapshotStore; cipher: PayloadCipher }, page: InertiaPageLike, now: number): Promise<boolean> {
    const auth = page.props.auth

    if (!isOfflinePage(page.url) || auth === null || auth === undefined) {
        return false
    }

    const path = pathOf(page.url)
    // Flash messages and old form errors of that visit are not part of what is shown from the device.
    const { errors: _errors, flash: _flash, ...props } = page.props
    const box = await deps.cipher.encrypt(snapshotAad(path, auth.userId, auth.propertyId, now), { ...page, props })

    await deps.store.put({ path, userId: auth.userId, propertyId: auth.propertyId, savedAt: now, box })

    return true
}

/** The page kept for this path, or null when there is none, it is too old, or it cannot be read back (another key, altered). */
export async function openSnapshot(deps: { store: SnapshotStore; cipher: PayloadCipher }, path: string, now: number): Promise<{ page: InertiaPageLike; savedAt: number } | null> {
    const record = await deps.store.get(path)

    if (record === null || now - record.savedAt > SNAPSHOT_MAX_AGE_MS || record.savedAt > now + 60_000) {
        return null
    }

    try {
        const page = (await deps.cipher.decrypt(snapshotAad(record.path, record.userId, record.propertyId, record.savedAt), record.box)) as InertiaPageLike

        return page.props.auth?.userId === record.userId && page.props.auth?.propertyId === record.propertyId ? { page, savedAt: record.savedAt } : null
    } catch {
        return null
    }
}
