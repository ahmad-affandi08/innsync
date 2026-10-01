import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { ApiError, failureFromResponse, offlineFailure } from '../lib/api-error.ts'
import { shouldRetryQuery } from './query-client.ts'

describe('shouldRetryQuery', () => {
    it('retries transient failures a bounded number of times', () => {
        const offline = new ApiError(offlineFailure())

        assert.equal(shouldRetryQuery(0, offline), true)
        assert.equal(shouldRetryQuery(1, offline), true)
        assert.equal(shouldRetryQuery(2, offline), false)
        assert.equal(shouldRetryQuery(0, new ApiError(failureFromResponse(503, null))), true)
    })

    it('never retries conflicts, validation, permission or session errors', () => {
        for (const status of [401, 403, 404, 409, 419, 422]) {
            assert.equal(shouldRetryQuery(0, new ApiError(failureFromResponse(status, null))), false, String(status))
        }
    })

    it('does not retry unknown thrown values', () => {
        assert.equal(shouldRetryQuery(0, new Error('x')), false)
    })
})
