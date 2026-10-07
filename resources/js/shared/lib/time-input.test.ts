import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { completeTime, formatTypedTime } from './time-input.ts'

describe('time input', () => {
    it('puts the colon after the hour while a person types', () => {
        assert.equal(formatTypedTime('1'), '1')
        assert.equal(formatTypedTime('14'), '14')
        assert.equal(formatTypedTime('143'), '14:3')
        assert.equal(formatTypedTime('1430'), '14:30')
        assert.equal(formatTypedTime('14:30'), '14:30')
        assert.equal(formatTypedTime('a1b4:3x'), '14:3')
        assert.equal(formatTypedTime('12345'), '12:34')
        assert.equal(formatTypedTime(''), '')
    })

    it('completes what was typed to a time of day, or says it is none', () => {
        assert.equal(completeTime(''), '')
        assert.equal(completeTime('9'), '09:00')
        assert.equal(completeTime('14'), '14:00')
        assert.equal(completeTime('930'), '09:30')
        assert.equal(completeTime('0930'), '09:30')
        assert.equal(completeTime('14:30'), '14:30')
        assert.equal(completeTime('23:59'), '23:59')
        assert.equal(completeTime('24'), null)
        assert.equal(completeTime('2460'), null)
        assert.equal(completeTime('1260'), null)
    })
})
