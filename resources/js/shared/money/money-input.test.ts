import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { caretAfter, displayFromRaw, rawFromPasted, rawFromTyped, significantBefore } from './money-input.ts'

describe('money input', () => {
    it('shows an amount grouped the way the language writes it', () => {
        assert.equal(displayFromRaw('1500000', 'id'), '1.500.000')
        assert.equal(displayFromRaw('1500000', 'en'), '1,500,000')
        assert.equal(displayFromRaw('1500.5', 'id'), '1.500,5')
        assert.equal(displayFromRaw('1500.', 'id'), '1.500,')
        assert.equal(displayFromRaw('999', 'id'), '999')
        assert.equal(displayFromRaw('-2500', 'id'), '-2.500')
        assert.equal(displayFromRaw('', 'id'), '')
    })

    it('keeps plain digits and one dot for what is typed, whatever is shown', () => {
        assert.equal(rawFromTyped('1.500.000', 'id'), '1500000')
        assert.equal(rawFromTyped('1,500,000', 'en'), '1500000')
        assert.equal(rawFromTyped('1.500,5', 'id'), '1500.5')
        assert.equal(rawFromTyped('1.500,567', 'id'), '1500.56')
        assert.equal(rawFromTyped('1.500,5', 'id', { decimals: 0 }), '15005')
        assert.equal(rawFromTyped('Rp 12a3', 'id'), '123')
        assert.equal(rawFromTyped('007', 'id'), '7')
        assert.equal(rawFromTyped('0', 'id'), '0')
        assert.equal(rawFromTyped('-5', 'id'), '5')
        assert.equal(rawFromTyped('-5', 'id', { negative: true }), '-5')
        assert.equal(rawFromTyped('1,2,3', 'id'), '1.23')
    })

    it('reads a pasted amount without mistaking a thousands mark for decimals', () => {
        assert.equal(rawFromPasted('1500000', 'id'), '1500000')
        assert.equal(rawFromPasted('Rp 1.500.000', 'id'), '1500000')
        assert.equal(rawFromPasted('1,500,000', 'id'), '1500000')
        assert.equal(rawFromPasted('1.500', 'id'), '1500')
        assert.equal(rawFromPasted('1.500,50', 'id'), '1500.50')
        assert.equal(rawFromPasted('1,500.50', 'id'), '1500.50')
        assert.equal(rawFromPasted('1500.5', 'id'), '1500.5')
        assert.equal(rawFromPasted('1500,50', 'en'), '1500.50')
        assert.equal(rawFromPasted('1.500', 'id', { decimals: 0 }), '1500')
        assert.equal(rawFromPasted('1500.5', 'id', { decimals: 0 }), '15005')
    })

    it('keeps the caret in front of the same digit when the grouping changes', () => {
        const before = '1.234'
        const typedAt = significantBefore('1.2345', 6, 'id')
        assert.equal(typedAt, 5)
        assert.equal(caretAfter('12.345', typedAt, 'id'), 6)
        assert.equal(caretAfter(displayFromRaw('1234', 'id'), significantBefore(before, 2, 'id'), 'id'), 1)
        assert.equal(caretAfter('', 0, 'id'), 0)
    })
})
