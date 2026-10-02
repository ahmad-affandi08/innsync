import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { formatMilli, parseMilli, plainMilli, toBaseMilli } from './quantity.ts'

describe('stock quantities', () => {
    it('reads a number with a dot or a comma and at most three decimals', () => {
        assert.equal(parseMilli('12.5'), 12_500)
        assert.equal(parseMilli('12,5'), 12_500)
        assert.equal(parseMilli('0.001'), 1)
        assert.equal(parseMilli('1.2345'), null)
        assert.equal(parseMilli('abc'), null)
        assert.equal(parseMilli(''), null)
    })

    it('converts to the base unit like the server does', () => {
        assert.equal(toBaseMilli(2_000, 24_000), 48_000)
        assert.equal(toBaseMilli(1_500, 1), 2)
        assert.equal(toBaseMilli(1_499, 1), 1)
    })

    it('fills an input with a plain decimal', () => {
        assert.equal(plainMilli(12_500), '12.5')
        assert.equal(plainMilli(5_000), '5')
    })

    it('shows at most three decimals in the language of the person', () => {
        assert.equal(formatMilli(12_500, 'en'), '12.5')
        assert.equal(formatMilli(12_500, 'id'), '12,5')
        assert.equal(formatMilli(48_000, 'en'), '48')
        assert.equal(formatMilli(1, 'en'), '0.001')
    })
})
