import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { currencyExponent, formatMinorUnits, MoneyFormatError, parseMajorToMinor } from './money.ts'

describe('money display', () => {
    it('uses the currency exponent, not a fixed two decimals', () => {
        // ISO 4217 gives IDR two minor digits (sen), so 150000 minor is Rp 1.500, not Rp 150.000.
        assert.equal(currencyExponent('IDR'), 2)
        assert.equal(currencyExponent('USD'), 2)
        assert.equal(currencyExponent('JPY'), 0)
        assert.equal(currencyExponent('KWD'), 3)
    })

    it('formats minor units per locale', () => {
        // Whole rupiah are shown without decimals; a stray sen is never hidden.
        assert.match(formatMinorUnits(150000, 'IDR', 'id'), /^Rp\s?1\.500$/)
        assert.match(formatMinorUnits(150000, 'IDR', 'en'), /^IDR\s?1,500$/)
        assert.match(formatMinorUnits(150050, 'IDR', 'id'), /^Rp\s?1\.500,50$/)
        assert.match(formatMinorUnits(-150000, 'IDR', 'id'), /^-Rp\s?1\.500$/)
        assert.match(formatMinorUnits(12345, 'USD', 'en'), /^\$123\.45$/)
        assert.match(formatMinorUnits(5, 'USD', 'en'), /^\$0\.05$/)
        assert.match(formatMinorUnits(-12345, 'USD', 'en'), /^-\$123\.45$/)
    })

    it('stays exact for amounts a float division would round', () => {
        assert.match(formatMinorUnits(9007199254740991, 'USD', 'en'), /90,071,992,547,409\.91/)
    })

    it('refuses fractions, unsafe integers and bad currency codes', () => {
        assert.throws(() => formatMinorUnits(10.5, 'USD', 'en'), MoneyFormatError)
        assert.throws(() => formatMinorUnits(2 ** 53, 'USD', 'en'), MoneyFormatError)
        assert.throws(() => formatMinorUnits(100, 'usd', 'en'), MoneyFormatError)
        assert.throws(() => formatMinorUnits(100, 'US', 'en'), MoneyFormatError)
    })
})

describe('typed amounts', () => {
    it('reads plain digits and one decimal separator into minor units', () => {
        assert.equal(parseMajorToMinor('1500000', 'IDR'), 150000000)
        assert.equal(parseMajorToMinor('1500000,50', 'IDR'), 150000050)
        assert.equal(parseMajorToMinor('1500000.5', 'IDR'), 150000050)
        assert.equal(parseMajorToMinor(' 12.34 ', 'USD'), 1234)
        assert.equal(parseMajorToMinor('12', 'USD'), 1200)
        assert.equal(parseMajorToMinor('0', 'IDR'), 0)
        assert.equal(parseMajorToMinor('500', 'JPY'), 500)
    })

    it('refuses thousands separators and anything ambiguous instead of guessing', () => {
        for (const bad of ['1.500.000', '1,500,000', '1.500', '1,500', '', 'abc', '-5', '1e3', '12.345', '1 500', '99999999999999']) {
            assert.equal(parseMajorToMinor(bad, 'USD'), null, bad)
        }
        assert.equal(parseMajorToMinor('1.500', 'IDR'), null, 'grouped rupiah is not a fraction')
        assert.equal(parseMajorToMinor('1.000', 'KWD'), 1000, 'three decimals are real for KWD')
    })
})
