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
