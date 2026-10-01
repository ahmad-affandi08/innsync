/**
 * Field names that must never enter the offline queue or be stored on the
 * device: card data, identity documents, secrets (NFR-07, NFR-09; "do not cache
 * guest identity documents or unnecessary PII for offline use"). Mirrors
 * `SensitiveDataGuard` on the server, which enforces it again; a test keeps the
 * two lists identical.
 */
export const FORBIDDEN_KEYS = [
    'access_token',
    'api_key',
    'authorization',
    'card_number',
    'client_secret',
    'cookie',
    'credentials',
    'current_password',
    'cvc',
    'cvv',
    'document_number',
    'identity_number',
    'pan',
    'passport_number',
    'password',
    'password_confirmation',
    'pin',
    'private_key',
    'recovery_code',
    'recovery_codes',
    'refresh_token',
    'secret',
    'token',
    'two_factor_recovery_codes',
    'two_factor_secret',
] as const

/** The first forbidden key found anywhere in the payload, or null. */
export function findForbiddenKey(value: unknown): string | null {
    if (Array.isArray(value)) {
        for (const item of value) {
            const found = findForbiddenKey(item)

            if (found !== null) {
                return found
            }
        }

        return null
    }

    if (typeof value === 'object' && value !== null) {
        for (const [key, child] of Object.entries(value)) {
            if ((FORBIDDEN_KEYS as readonly string[]).includes(key.toLowerCase())) {
                return key
            }

            const found = findForbiddenKey(child)

            if (found !== null) {
                return found
            }
        }
    }

    return null
}
