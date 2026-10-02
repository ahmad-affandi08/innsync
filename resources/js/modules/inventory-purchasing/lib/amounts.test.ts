import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { bpToInput, minorToInput, parsePercentToBp } from './amounts.ts'

describe('purchasing amounts', () => {
    it('fills a money input from minor units without a float', () => {
        assert.equal(minorToInput(150_000, 'IDR'), '1500')
        assert.equal(minorToInput(150_050, 'IDR'), '1500.5')
        assert.equal(minorToInput(1, 'USD'), '0.01')
        assert.equal(minorToInput(0, 'IDR'), '0')
    })

    it('shows and reads percentages as basis points', () => {
        assert.equal(bpToInput(1100), '11')
        assert.equal(bpToInput(25), '0.25')
        assert.equal(bpToInput(0), '0')
        assert.equal(parsePercentToBp('11'), 1100)
        assert.equal(parsePercentToBp('0,25'), 25)
        assert.equal(parsePercentToBp('2.5'), 250)
        assert.equal(parsePercentToBp('2.555'), null)
        assert.equal(parsePercentToBp('abc'), null)
        assert.equal(parsePercentToBp(''), null)
    })
})
