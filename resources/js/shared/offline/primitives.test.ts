import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'

import { backoffDelayMs, DEFAULT_BACKOFF } from './backoff.ts'
import { createAesGcmCipher, entryAad, generateDeviceKey } from './crypto.ts'
import { FORBIDDEN_KEYS, findForbiddenKey } from './sensitive.ts'
import { ULID_PATTERN, ulid } from './ulid.ts'

describe('ulid', () => {
    it('is 26 valid Crockford characters, sortable by time, and random within a millisecond', () => {
        const a = ulid(1_790_000_000_000)
        const b = ulid(1_790_000_000_001)

        assert.match(a, ULID_PATTERN)
        assert.ok(a.slice(0, 10) < b.slice(0, 10), 'later time sorts later')
        assert.notEqual(ulid(1_790_000_000_000), ulid(1_790_000_000_000))
        assert.equal(a.length, 26)
    })

    it('encodes a known time deterministically and rejects invalid time', () => {
        assert.equal(ulid(0, (bytes) => bytes).slice(0, 10), '0000000000')
        assert.equal(ulid(1, (bytes) => bytes).slice(0, 10), '0000000001')
        assert.equal(ulid(32, (bytes) => bytes).slice(0, 10), '0000000010')
        assert.throws(() => ulid(-1), RangeError)
        assert.throws(() => ulid(Number.NaN), RangeError)
    })
})

describe('backoff', () => {
    it('grows exponentially, is capped, never negative, and jitter stays inside the band', () => {
        const noJitter = (attempt: number) => backoffDelayMs(attempt, () => 0.5)

        assert.equal(noJitter(1), 2000)
        assert.equal(noJitter(2), 4000)
        assert.equal(noJitter(3), 8000)
        assert.equal(noJitter(50), DEFAULT_BACKOFF.capMs)
        assert.equal(noJitter(0), 2000)
        assert.ok(backoffDelayMs(1, () => 0) >= 1600 && backoffDelayMs(1, () => 0) <= 2400)
        assert.ok(backoffDelayMs(1, () => 1) <= 2400)
        assert.ok(backoffDelayMs(10_000, () => 1) <= DEFAULT_BACKOFF.capMs, 'bounded for any attempt count')
    })
})

describe('encrypted payloads', () => {
    const identity = { operationId: 'OP1', propertyId: 'P1', actorId: 'U1' }

    it('round-trips, uses a fresh IV every time, and hides the plaintext', async () => {
        const cipher = createAesGcmCipher(await generateDeviceKey())
        const payload = { items: [{ sku: 'nasi-goreng', qty: 2 }], note: 'tanpa pedas' }

        const a = await cipher.encrypt(entryAad(identity), payload)
        const b = await cipher.encrypt(entryAad(identity), payload)

        assert.deepEqual(await cipher.decrypt(entryAad(identity), a), payload)
        assert.notDeepEqual(a.iv, b.iv)
        assert.notDeepEqual(a.data, b.data)
        assert.doesNotMatch(new TextDecoder().decode(a.data), /nasi|pedas|sku/)
    })

    it('rejects a tampered ciphertext, a tampered IV, and a ciphertext moved to another entry or user', async () => {
        const cipher = createAesGcmCipher(await generateDeviceKey())
        const box = await cipher.encrypt(entryAad(identity), { amount: 1 })

        const flipped = { iv: box.iv, data: new Uint8Array(box.data) }
        flipped.data[0] = (flipped.data[0]! ^ 1) & 0xff
        await assert.rejects(() => cipher.decrypt(entryAad(identity), flipped))

        const badIv = { iv: new Uint8Array(box.iv), data: box.data }
        badIv.iv[0] = (badIv.iv[0]! ^ 1) & 0xff
        await assert.rejects(() => cipher.decrypt(entryAad(identity), badIv))

        await assert.rejects(() => cipher.decrypt(entryAad({ ...identity, operationId: 'OP2' }), box))
        await assert.rejects(() => cipher.decrypt(entryAad({ ...identity, actorId: 'U2' }), box))
        await assert.rejects(() => cipher.decrypt(entryAad({ ...identity, propertyId: 'P2' }), box))
    })

    it('cannot be decrypted with another device key, and the key is not extractable', async () => {
        const key = await generateDeviceKey()
        const box = await createAesGcmCipher(key).encrypt(entryAad(identity), { amount: 1 })

        await assert.rejects(async () => createAesGcmCipher(await generateDeviceKey()).decrypt(entryAad(identity), box))
        assert.equal(key.extractable, false)
        await assert.rejects(() => globalThis.crypto.subtle.exportKey('raw', key))
    })
})

describe('forbidden payload fields', () => {
    it('finds them at any depth, case-insensitively, including inside lists', () => {
        assert.equal(findForbiddenKey({ a: 1, b: { c: { card_number: 'x' } } }), 'card_number')
        assert.equal(findForbiddenKey([{ ok: 1 }, { PIN: '1234' }]), 'PIN')
        assert.equal(findForbiddenKey({ amount: 5, note: 'a token of thanks' }), null, 'values are not inspected, only field names')
        assert.equal(findForbiddenKey(null), null)
        assert.equal(findForbiddenKey('text'), null)
    })

    it('matches the server list exactly, so the two cannot drift', () => {
        const php = readFileSync(new URL('../../../../app/Shared/Application/Observability/SensitiveDataGuard.php', import.meta.url), 'utf8')
        const block = /FORBIDDEN_KEYS = \[([\s\S]*?)\];/.exec(php)?.[1] ?? ''
        const serverKeys = [...block.matchAll(/'([a-z_]+)'/g)].map((m) => m[1] as string)

        assert.ok(serverKeys.length > 10)
        assert.deepEqual([...FORBIDDEN_KEYS].sort(), [...serverKeys].sort())
    })
})
