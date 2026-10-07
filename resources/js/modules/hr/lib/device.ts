const KEY = 'innsync.device'

/**
 * A random identifier this phone keeps, sent with a clock-in so a supervisor can see when one phone clocks in for several people. It identifies a browser, not a person, and the
 * server keeps only a fingerprint of it. When the browser will not keep it (private window, blocked storage), nothing is sent.
 */
export function deviceId(): string | null {
    try {
        let id = window.localStorage.getItem(KEY)

        if (id === null || !/^[A-Za-z0-9-]{16,64}$/.test(id)) {
            id = globalThis.crypto.randomUUID()
            window.localStorage.setItem(KEY, id)
        }

        return id
    } catch {
        return null
    }
}
