/** The browser wants the server's public key as bytes; the server gives it as base64url. */
export function keyToBytes(base64url: string): Uint8Array {
    const padded = base64url.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (base64url.length % 4)) % 4)
    const raw = atob(padded)
    const bytes = new Uint8Array(raw.length)

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i)
    }

    return bytes
}

/** A short name for this device, so a person can tell their devices apart in the list the server keeps (no more than the browser and the system). */
export function deviceLabel(userAgent: string): string {
    const browser = /Edg\//.test(userAgent) ? 'Edge' : /Firefox\//.test(userAgent) ? 'Firefox' : /(Chrome|CriOS)\//.test(userAgent) ? 'Chrome' : /Safari\//.test(userAgent) ? 'Safari' : 'Browser'
    const system = /Android/.test(userAgent) ? 'Android' : /iPhone|iPad|iPod/.test(userAgent) ? 'iOS' : /Windows/.test(userAgent) ? 'Windows' : /Mac OS X/.test(userAgent) ? 'macOS' : /Linux/.test(userAgent) ? 'Linux' : ''

    return system === '' ? browser : `${browser} · ${system}`
}

/** An iPhone or iPad (an iPad says it is a Mac, but a Mac has no touch screen): there notices work only for the app added to the home screen. */
export function isAppleMobile(userAgent: string, maxTouchPoints: number): boolean {
    return /iPhone|iPad|iPod/.test(userAgent) || (/Macintosh/.test(userAgent) && maxTouchPoints > 1)
}

/** How long "Not now" keeps the invitation to turn notices on away. */
export const NUDGE_PAUSE_MS = 7 * 24 * 60 * 60 * 1000

/** Whether to invite again: never asked, or "Not now" was said long enough ago (a stored value that is not a time counts as never). */
export function nudgeDue(dismissedAt: string | null, now: number): boolean {
    const at = dismissedAt === null ? Number.NaN : Number(dismissedAt)

    return !Number.isFinite(at) || now - at >= NUDGE_PAUSE_MS
}

/** Asks the browser for permission and registers this device with the server. 'blocked' when the person says no. */
export async function subscribeDevice(publicKey: string, register: (endpoint: string, label: string) => Promise<unknown>): Promise<'on' | 'blocked'> {
    if ((await Notification.requestPermission()) !== 'granted') return 'blocked'
    const registration = await navigator.serviceWorker.ready
    const subscription = (await registration.pushManager.getSubscription()) ?? (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(publicKey) as BufferSource }))
    await register(subscription.endpoint, deviceLabel(navigator.userAgent))

    return 'on'
}
