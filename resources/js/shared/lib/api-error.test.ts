import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { ApiError, failureFromResponse, fieldError, offlineFailure, toFailure } from './api-error.ts'

const envelope = (over: Record<string, unknown>) => ({
    error: {
        code: 'conflict',
        message: 'x',
        status: 409,
        retryable: false,
        correlation_id: '01HZ',
        ...over,
    },
})

describe('failureFromResponse', () => {
    it('maps a conflict envelope with reason and action', () => {
        const failure = failureFromResponse(
            409,
            envelope({ conflict: { reason: 'stale', action: 'refresh' } }),
        )

        assert.equal(failure.kind, 'conflict')
        assert.deepEqual(failure.conflict, { reason: 'stale', action: 'refresh' })
        assert.equal(failure.correlationId, '01HZ')
        assert.equal(failure.retryable, false)
    })

    it('keeps the retryable flag of a retry-action conflict', () => {
        const failure = failureFromResponse(
            409,
            envelope({ retryable: true, conflict: { reason: 'in_flight', action: 'retry' } }),
        )

        assert.equal(failure.kind, 'conflict')
        assert.equal(failure.retryable, true)
    })

    it('maps validation fields', () => {
        const failure = failureFromResponse(
            422,
            envelope({ code: 'validation_failed', status: 422, fields: { name: ['Required', 'Too short'] } }),
        )

        assert.equal(failure.kind, 'validation')
        assert.equal(fieldError(failure, 'name'), 'Required')
        assert.equal(fieldError(failure, 'other'), undefined)
    })

    it('maps each documented status to a dedicated kind', () => {
        const kinds = [
            [401, 'unauthenticated'],
            [403, 'forbidden'],
            [404, 'not-found'],
            [419, 'session-expired'],
            [429, 'rate-limited'],
            [500, 'server-error'],
            [503, 'server-error'],
        ] as const

        for (const [status, kind] of kinds) {
            assert.equal(failureFromResponse(status, null).kind, kind)
        }
    })

    it('treats unknown bodies as a safe failure without leaking content', () => {
        const failure = failureFromResponse(500, '<html>stack trace</html>')

        assert.equal(failure.kind, 'server-error')
        assert.equal(failure.code, null)
        assert.equal(failure.retryable, true)
        assert.equal(failure.correlationId, null)
    })

    it('does not retry a 4xx without an envelope', () => {
        assert.equal(failureFromResponse(403, null).retryable, false)
        assert.equal(failureFromResponse(429, null).retryable, true)
    })
})

describe('toFailure', () => {
    it('unwraps ApiError and hides everything else', () => {
        assert.equal(toFailure(new ApiError(offlineFailure())).kind, 'offline')
        const failure = toFailure(new Error('SQLSTATE secret'))

        assert.equal(failure.kind, 'server-error')
        assert.equal(failure.retryable, false)
    })
})
