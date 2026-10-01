import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
    addDays,
    calendarDateIn,
    compareDates,
    formatDate,
    formatInstant,
    fromEpochSeconds,
    isIsoDate,
    parseInstant,
    resolveTimeZone,
} from './time.ts'

describe('plain dates', () => {
    it('accepts only real YYYY-MM-DD dates', () => {
        assert.equal(isIsoDate('2026-10-01'), true)
        assert.equal(isIsoDate('2028-02-29'), true)
        for (const bad of ['2026-02-30', '2026-02-29', '2026-13-01', '2026-1-5', '2026-10-01T00:00:00Z', '2026-10-01\n', '', '01/10/2026']) {
            assert.equal(isIsoDate(bad), false, bad)
        }
    })

    it('does arithmetic on calendar days across month, year and leap boundaries', () => {
        assert.equal(addDays('2026-12-31', 1), '2027-01-01')
        assert.equal(addDays('2028-02-28', 1), '2028-02-29')
        assert.equal(addDays('2026-03-01', -1), '2026-02-28')
        assert.equal(addDays('2026-10-01', 30), '2026-10-31')
        assert.throws(() => addDays('2026-02-30', 1), RangeError)
    })

    it('compares dates and refuses invalid ones', () => {
        assert.equal(compareDates('2026-10-01', '2026-10-02'), -1)
        assert.equal(compareDates('2026-10-02', '2026-10-02'), 0)
        assert.equal(compareDates('2026-10-03', '2026-10-02'), 1)
        assert.throws(() => compareDates('x', '2026-10-02'), RangeError)
    })

    it('displays the same day no matter the machine zone', () => {
        const original = process.env.TZ
        // Node resolves the default zone lazily, so the formatter must not depend on it.
        for (const zone of ['Pacific/Honolulu', 'Asia/Jakarta', 'UTC']) {
            ;process.env.TZ = zone
            assert.equal(formatDate('2026-10-01', 'en', 'long'), 'October 1, 2026', zone)
            assert.equal(formatDate('2026-10-01', 'id', 'long'), '1 Oktober 2026', zone)
        }
        ;process.env.TZ = original
    })
})

describe('instants', () => {
    it('shows the property wall clock, with the zone name, not the browser zone', () => {
        const jakarta = formatInstant('2026-10-01T23:30:00Z', { locale: 'en', timeZone: 'Asia/Jakarta' })
        const honolulu = formatInstant('2026-10-01T23:30:00Z', { locale: 'en', timeZone: 'Pacific/Honolulu' })

        assert.match(jakarta, /Oct 2, 2026/)
        assert.match(jakarta, /6:30/)
        assert.match(jakarta, /GMT\+7|WIB/)
        assert.match(honolulu, /Oct 1, 2026/)
        assert.match(honolulu, /1:30/)
    })

    it('falls back to an explicit, labelled UTC for a missing or invalid zone', () => {
        for (const zone of [null, undefined, '', 'Not/AZone', 'WIB-ish']) {
            assert.equal(resolveTimeZone(zone), 'UTC')
        }

        assert.match(formatInstant('2026-10-01T23:30:00Z', { locale: 'en', timeZone: null }), /UTC/)
    })

    it('rejects ambiguous or invalid instants instead of reading them in the browser zone', () => {
        for (const bad of ['2026-10-01 23:30:00', '2026-10-01', 'tomorrow', '2026-10-01T23:30:00', '']) {
            assert.throws(() => parseInstant(bad), RangeError, bad)
        }

        assert.equal(parseInstant('2026-10-02T06:30:00+07:00').toISOString(), '2026-10-01T23:30:00.000Z')
        assert.equal(fromEpochSeconds(0).toISOString(), '1970-01-01T00:00:00.000Z')
    })

    it('gives the clock date of an instant in a zone', () => {
        assert.equal(calendarDateIn('2026-10-01T23:30:00Z', 'Asia/Jakarta'), '2026-10-02')
        assert.equal(calendarDateIn('2026-10-01T23:30:00Z', 'Pacific/Honolulu'), '2026-10-01')
        assert.equal(calendarDateIn('2026-10-01T23:30:00Z', null), '2026-10-01')
        // Daylight-saving day boundaries use the zone's own rules.
        assert.equal(calendarDateIn('2026-11-01T04:30:00Z', 'America/New_York'), '2026-11-01')
        assert.equal(calendarDateIn('2026-11-01T03:59:59Z', 'America/New_York'), '2026-10-31')
    })
})
