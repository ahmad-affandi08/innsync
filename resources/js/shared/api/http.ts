import { ApiError, failureFromResponse, offlineFailure } from '../lib/api-error.ts'

/**
 * Minimal JSON client for TanStack Query functions and mutations
 * (TASK-FND-012, ADR-0004). It owns transport concerns only: JSON headers,
 * Laravel's XSRF cookie, an optional idempotency key, and mapping every
 * failure to an `ApiError` carrying the TASK-FND-009 error envelope. It holds
 * no business rule and no authorization decision.
 */
export interface ApiRequestOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
    query?: Readonly<Record<string, string | number | boolean | null | undefined>>
    body?: unknown
    /**
     * Required for retryable critical mutations (NFR-18). Generate once per user
     * intent and reuse it on every retry of that intent; a new key per attempt
     * defeats duplicate protection.
     */
    idempotencyKey?: string
    signal?: AbortSignal
    /** Injection point for tests; defaults to the global fetch. */
    fetchImpl?: typeof fetch
    /** Injection point for tests; defaults to document.cookie. */
    cookies?: string
}

/** 16-128 safe characters, matching the server-side `Idempotency-Key` validation. */
export function newIdempotencyKey(): string {
    return globalThis.crypto.randomUUID()
}

export function readXsrfToken(cookies: string): string | null {
    for (const part of cookies.split(';')) {
        const [name, ...rest] = part.trim().split('=')

        if (name === 'XSRF-TOKEN' && rest.length > 0) {
            try {
                return decodeURIComponent(rest.join('='))
            } catch {
                return null
            }
        }
    }

    return null
}

export function buildUrl(path: string, query?: ApiRequestOptions['query']): string {
    if (query === undefined) {
        return path
    }

    const params = new URLSearchParams()

    for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== null && value !== '') {
            params.set(key, String(value))
        }
    }

    const search = params.toString()

    return search === '' ? path : `${path}${path.includes('?') ? '&' : '?'}${search}`
}

async function readBody(response: Response): Promise<unknown> {
    const text = await response.text()

    if (text === '') {
        return null
    }

    try {
        return JSON.parse(text) as unknown
    } catch {
        return null
    }
}

export async function apiRequest<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
    const method = options.method ?? 'GET'
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    }

    // A file upload is sent as multipart; the browser sets the boundary, so no Content-Type header is added for it.
    const isForm = typeof FormData !== 'undefined' && options.body instanceof FormData

    if (options.body !== undefined && !isForm) {
        headers['Content-Type'] = 'application/json'
    }

    if (options.idempotencyKey !== undefined) {
        headers['Idempotency-Key'] = options.idempotencyKey
    }

    if (method !== 'GET') {
        const cookies = options.cookies ?? (typeof document === 'undefined' ? '' : document.cookie)
        const xsrf = readXsrfToken(cookies)

        if (xsrf !== null) {
            headers['X-XSRF-TOKEN'] = xsrf
        }
    }

    let response: Response

    try {
        response = await (options.fetchImpl ?? fetch)(buildUrl(path, options.query), {
            method,
            headers,
            body: options.body === undefined ? undefined : isForm ? (options.body as FormData) : JSON.stringify(options.body),
            credentials: 'same-origin',
            signal: options.signal,
        })
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error
        }

        throw new ApiError(offlineFailure())
    }

    const body = await readBody(response)

    if (!response.ok) {
        throw new ApiError(failureFromResponse(response.status, body))
    }

    return body as T
}
