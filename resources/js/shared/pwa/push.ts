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
