import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { createResourceKeys, normalizeParams } from './query-keys.ts'

const keys = createResourceKeys('front-office', 'reservations')

describe('query keys', () => {
    it('scope every key to the property so a property switch cannot reuse cache', () => {
        assert.notEqual(
            JSON.stringify(keys.list('prop-a', { page: 1 })),
            JSON.stringify(keys.list('prop-b', { page: 1 })),
        )
        assert.deepEqual(keys.detail('prop-a', 'x'), ['front-office', 'reservations', 'prop-a', 'detail', 'x'])
    })

    it('refuses a missing property id', () => {
        assert.throws(() => keys.list('', {}))
        assert.throws(() => keys.detail('  ', 'x'))
    })

    it('makes prefix invalidation possible', () => {
        const list = keys.list('prop-a', { page: 2 })
        const prefix = keys.lists('prop-a')

        assert.deepEqual(list.slice(0, prefix.length), [...prefix])
        assert.deepEqual(prefix.slice(0, 3), [...keys.all('prop-a')])
    })

    it('normalizes parameter order and drops empty values', () => {
        assert.equal(
            JSON.stringify(normalizeParams({ b: 1, a: 'x', c: '', d: undefined, e: null })),
            JSON.stringify({ a: 'x', b: 1 }),
        )
    })
})
