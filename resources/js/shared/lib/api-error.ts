export type ConflictAction = 'refresh' | 'retry' | 'review'

export interface ApiErrorBody {
    code: string
    message: string
    status: number
    retryable: boolean
    correlation_id: string
    fields?: Record<string, string[]>
    conflict?: { reason: string; action: ConflictAction }
}

export interface ApiErrorEnvelope {
    error: ApiErrorBody
}

export function isApiErrorEnvelope(value: unknown): value is ApiErrorEnvelope {
    if (typeof value !== 'object' || value === null || !('error' in value)) {
        return false
    }

    const error = (value as { error: unknown }).error

    return (
        typeof error === 'object' &&
        error !== null &&
        typeof (error as ApiErrorBody).code === 'string' &&
        typeof (error as ApiErrorBody).status === 'number' &&
        typeof (error as ApiErrorBody).correlation_id === 'string'
    )
}

/** Branch on `code`/`conflict`, never on `message`; show correlation_id in support affordances. */
export function isConflict(error: ApiErrorBody): boolean {
    return error.status === 409 && error.code === 'conflict'
}

/**
 * Screen-level outcome a failed request maps to. Each kind has a dedicated UI
 * state (docs/DESIGN/07-STATES-FEEDBACK.md); none may fall back to a generic
 * "something went wrong" when a more specific state applies.
 */
export type FailureKind =
    | 'validation'
    | 'conflict'
    | 'forbidden'
    | 'unauthenticated'
    | 'session-expired'
    | 'not-found'
    | 'rate-limited'
    | 'offline'
    | 'server-error'

export interface Failure {
    kind: FailureKind
    /** True when repeating the same request can succeed without the user changing anything. */
    retryable: boolean
    status: number | null
    code: string | null
    correlationId: string | null
    fields: Record<string, string[]>
    conflict: { reason: string; action: ConflictAction } | null
}

/** Thrown by the HTTP client for every non-success outcome, including a lost network. */
export class ApiError extends Error {
    readonly failure: Failure

    constructor(failure: Failure, message?: string) {
        super(message ?? `Request failed: ${failure.kind}`)
        this.name = 'ApiError'
        this.failure = failure
    }
}

function kindForStatus(status: number): FailureKind {
    if (status === 401) return 'unauthenticated'
    if (status === 403) return 'forbidden'
    if (status === 404) return 'not-found'
    if (status === 409) return 'conflict'
    if (status === 419) return 'session-expired'
    if (status === 422) return 'validation'
    if (status === 429) return 'rate-limited'

    return 'server-error'
}

/** Builds a Failure from an HTTP response body. Unrecognised bodies never leak into the UI. */
export function failureFromResponse(status: number, body: unknown): Failure {
    if (isApiErrorEnvelope(body)) {
        const { error } = body
        const kind = isConflict(error) ? 'conflict' : kindForStatus(error.status)

        return {
            kind,
            retryable: error.retryable === true,
            status: error.status,
            code: error.code,
            correlationId: error.correlation_id,
            fields: error.fields ?? {},
            conflict: error.conflict ?? null,
        }
    }

    return {
        kind: kindForStatus(status),
        retryable: status === 429 || status >= 500,
        status,
        code: null,
        correlationId: null,
        fields: {},
        conflict: null,
    }
}

/** A request that never produced an HTTP response (network down, aborted, DNS). */
export function offlineFailure(): Failure {
    return {
        kind: 'offline',
        retryable: true,
        status: null,
        code: null,
        correlationId: null,
        fields: {},
        conflict: null,
    }
}

/** Normalises anything a query/mutation can throw into a Failure. */
export function toFailure(error: unknown): Failure {
    if (error instanceof ApiError) {
        return error.failure
    }

    return {
        kind: 'server-error',
        retryable: false,
        status: null,
        code: null,
        correlationId: null,
        fields: {},
        conflict: null,
    }
}

/** First message for a field, for rendering next to the input. */
export function fieldError(failure: Failure, field: string): string | undefined {
    return failure.fields[field]?.[0]
}
