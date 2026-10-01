import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { ApiError } from '../lib/api-error.ts'
import { apiRequest, buildUrl, newIdempotencyKey, readXsrfToken } from './http.ts'

function respond(status: number, body: unknown, calls: { url: string; init: RequestInit }[] = []): typeof fetch {
    return (async (url: string, init: RequestInit) => {
        calls.push({ url, init })

        return new Response(body === null ? '' : JSON.stringify(body), { status })
    }) as unknown as typeof fetch
}

describe('apiRequest', () => {
    it('returns parsed JSON and sends JSON headers without a CSRF header on GET', async () => {
        const calls: { url: string; init: RequestInit }[] = []
        const result = await apiRequest<{ ok: boolean }>('/api/x', {
            query: { page: 2, q: '', skip: undefined },
            fetchImpl: respond(200, { ok: true }, calls),
            cookies: 'XSRF-TOKEN=abc',
        })

        assert.deepEqual(result, { ok: true })
        assert.equal(calls[0]?.url, '/api/x?page=2')
        const headers = calls[0]?.init.headers as Record<string, string>

        assert.equal(headers.Accept, 'application/json')
        assert.equal(headers['X-XSRF-TOKEN'], undefined)
    })

    it('sends XSRF token and idempotency key on mutations', async () => {
        const calls: { url: string; init: RequestInit }[] = []
        const key = newIdempotencyKey()

        await apiRequest('/api/x', {
            method: 'POST',
            body: { a: 1 },
            idempotencyKey: key,
            fetchImpl: respond(200, {}, calls),
            cookies: 'a=b; XSRF-TOKEN=tok%3D',
        })
        const headers = calls[0]?.init.headers as Record<string, string>

        assert.equal(headers['X-XSRF-TOKEN'], 'tok=')
        assert.equal(headers['Idempotency-Key'], key)
        assert.equal(headers['Content-Type'], 'application/json')
        assert.equal(calls[0]?.init.body, '{"a":1}')
        assert.ok(key.length >= 16 && key.length <= 128)
    })

    it('throws an ApiError carrying the envelope on failure', async () => {
        const body = {
            error: { code: 'conflict', message: 'm', status: 409, retryable: false, correlation_id: 'c1', conflict: { reason: 'r', action: 'refresh' } },
        }

        await assert.rejects(
            () => apiRequest('/api/x', { method: 'PUT', fetchImpl: respond(409, body), cookies: '' }),
            (error: unknown) => error instanceof ApiError && error.failure.kind === 'conflict' && error.failure.correlationId === 'c1',
        )
    })

    it('maps a network failure to an offline ApiError', async () => {
        const failing = (async () => {
            throw new TypeError('network down')
        }) as unknown as typeof fetch

        await assert.rejects(
            () => apiRequest('/api/x', { fetchImpl: failing }),
            (error: unknown) => error instanceof ApiError && error.failure.kind === 'offline' && error.failure.retryable,
        )
    })

    it('does not expose a non-JSON error body', async () => {
        const html = (async () => new Response('<html>boom</html>', { status: 500 })) as unknown as typeof fetch

        await assert.rejects(
            () => apiRequest('/api/x', { fetchImpl: html }),
            (error: unknown) => error instanceof ApiError && error.failure.kind === 'server-error' && !error.message.includes('boom'),
        )
    })
})

describe('helpers', () => {
    it('reads the XSRF cookie defensively', () => {
        assert.equal(readXsrfToken('x=1'), null)
        assert.equal(readXsrfToken('XSRF-TOKEN=%E0%A4%A'), null)
        assert.equal(readXsrfToken('XSRF-TOKEN=a%20b'), 'a b')
    })

    it('appends to an existing query string', () => {
        assert.equal(buildUrl('/a?x=1', { y: 2 }), '/a?x=1&y=2')
        assert.equal(buildUrl('/a'), '/a')
    })

    it('sends a FormData body as multipart without a JSON content type', async () => {
        const calls: { url: string; init: RequestInit }[] = []
        const form = new FormData()
        form.set('photo', new Blob(['x'], { type: 'image/png' }), 'x.png')

        await apiRequest('/api/upload', { method: 'POST', body: form, fetchImpl: respond(200, { ok: true }, calls), cookies: '' })

        assert.equal(calls[0]?.init.body, form)
        assert.equal((calls[0]?.init.headers as Record<string, string>)['Content-Type'], undefined)
    })
})
