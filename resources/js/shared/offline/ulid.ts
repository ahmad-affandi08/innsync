const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

/**
 * A ULID (26 chars, Crockford base32): 48-bit millisecond time then 80 random
 * bits. Generated on the device, so it works offline, and sortable by time.
 */
export function ulid(now: number = Date.now(), random: (bytes: Uint8Array<ArrayBuffer>) => Uint8Array<ArrayBuffer> = (b) => globalThis.crypto.getRandomValues(b)): string {
    if (!Number.isSafeInteger(now) || now < 0 || now > 0x7fffffffffff) {
        throw new RangeError('The time must be a non-negative millisecond timestamp.')
    }

    let time = ''
    let rest = now

    for (let i = 0; i < 10; i++) {
        time = ALPHABET[rest % 32] + time
        rest = Math.floor(rest / 32)
    }

    const bytes = random(new Uint8Array(16))
    let entropy = ''

    for (const byte of bytes) {
        entropy += ALPHABET[byte & 31]
    }

    return time + entropy
}

export const ULID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/
