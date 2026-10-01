import { QueryClient } from '@tanstack/react-query'

import { ApiError } from '../lib/api-error.ts'

const MAX_QUERY_RETRIES = 2

/**
 * Only transient failures are retried automatically: a lost network, a 429, or
 * a 5xx the server marked retryable. Conflicts, validation, permission and
 * session errors need a person to act, so retrying them would only hide the
 * state the UI must show (NFR-19).
 */
export function shouldRetryQuery(failureCount: number, error: unknown): boolean {
    if (failureCount >= MAX_QUERY_RETRIES) {
        return false
    }

    return error instanceof ApiError && error.failure.retryable
}

export function createAppQueryClient(): QueryClient {
    return new QueryClient({
        defaultOptions: {
            queries: {
                refetchOnWindowFocus: false,
                retry: shouldRetryQuery,
                staleTime: 30_000,
            },
            // A mutation is never replayed implicitly. A deliberate retry reuses the
            // caller's idempotency key (see `apiRequest`).
            mutations: {
                retry: false,
            },
        },
    })
}
