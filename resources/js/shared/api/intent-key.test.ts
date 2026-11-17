import assert from 'node:assert/strict'
import { it as test } from 'node:test'

import { intentKey } from './intent-key.ts'

test('the same request sent again keeps the key of the first try', () => {
    const first = intentKey(null, 'POST /a {"n":1}', 'key-one-0000000000')
    const again = intentKey(first.pending, 'POST /a {"n":1}', 'key-two-0000000000')

    assert.equal(again.key, 'key-one-0000000000')
})

test('a changed request, or one after a success, takes the new key', () => {
    const first = intentKey(null, 'POST /a {"n":1}', 'key-one-0000000000')

    assert.equal(intentKey(first.pending, 'POST /a {"n":2}', 'key-two-0000000000').key, 'key-two-0000000000')
    assert.equal(intentKey(null, 'POST /a {"n":1}', 'key-three-000000000').key, 'key-three-000000000')
})
