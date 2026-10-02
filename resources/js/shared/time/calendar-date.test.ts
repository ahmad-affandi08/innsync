import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { parseCalendarDate, toCalendarDate } from './calendar-date.ts'

describe('calendar date bridge', () => {
    it('round-trips an ISO date without drifting a day', () => {
        for (const iso of ['2026-10-03', '2026-01-01', '2028-02-29', '2026-12-31']) {
            assert.equal(toCalendarDate(parseCalendarDate(iso)!), iso)
        }
    })

    it('rejects text that is not a real calendar date', () => {
        assert.equal(parseCalendarDate(''), undefined)
        assert.equal(parseCalendarDate('2026-02-30'), undefined)
        assert.equal(parseCalendarDate(undefined), undefined)
        assert.equal(parseCalendarDate('03/10/2026'), undefined)
    })
})
