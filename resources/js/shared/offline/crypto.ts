import type { CipherBox } from './types.ts'

/**
 * Encrypts queued payloads at rest with AES-256-GCM (NFR-04, NFR-07). A fresh
 * random IV is used for every message, and each entry's identity (operation,
 * property, actor) is bound as additional authenticated data, so a ciphertext
 * copied onto another entry, or onto another user's entry, fails to decrypt.
 *
 * Threat model, stated plainly: the key is a non-extractable key held by the
 * browser for this origin. It protects the queue from being read as plain text
 * in storage, in backups, or by browsing the browser's storage tools. It does
 * NOT protect against code running in this origin or against a fully
 * compromised device. A stronger scheme (a key delivered by the server per
 * session, or unlocked by a PIN) is a separate security decision.
 */
export interface PayloadCipher {
    encrypt(aad: string, payload: unknown): Promise<CipherBox>
    decrypt(aad: string, box: CipherBox): Promise<unknown>
}

export async function generateDeviceKey(subtle: SubtleCrypto = globalThis.crypto.subtle): Promise<CryptoKey> {
    return subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
}

export function createAesGcmCipher(
    key: CryptoKey,
    crypto: Crypto = globalThis.crypto,
): PayloadCipher {
    const encoder = new TextEncoder()
    const decoder = new TextDecoder()

    return {
        async encrypt(aad, payload) {
            const iv = crypto.getRandomValues(new Uint8Array(12))
            const plain = encoder.encode(JSON.stringify(payload))
            const sealed = await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: encoder.encode(aad) }, key, plain)

            return { iv, data: new Uint8Array(sealed) }
        },

        async decrypt(aad, box) {
            const plain = await crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: box.iv, additionalData: encoder.encode(aad) },
                key,
                box.data,
            )

            return JSON.parse(decoder.decode(plain)) as unknown
        },
    }
}

/** The additional authenticated data that binds a ciphertext to its entry. */
export function entryAad(entry: { operationId: string; propertyId: string; actorId: string }): string {
    return `${entry.operationId}|${entry.propertyId}|${entry.actorId}`
}
