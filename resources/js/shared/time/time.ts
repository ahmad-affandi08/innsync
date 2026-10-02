/**
 * Time conventions for the browser (TASK-FND-014, NFR-26, BR-001). Pure and
 * framework neutral.
 *
 * - An instant is a moment in time. The server sends it as an ISO 8601 string
 *   with `Z`/offset; it is displayed on the active property's wall clock with
 *   the zone name visible, never in whatever zone the browser happens to use.
 * - A business date (or calendar date) is a plain `YYYY-MM-DD` with no time and
 *   no zone. It must never be parsed with `new Date('2026-10-01')`, which is UTC
 *   midnight and displays as the previous day west of UTC.
 * - The business date can not be derived from the clock here: it advances only
 *   through night audit on the server (PRD Q-11 is still open).
 */
export const FALLBACK_TIME_ZONE = 'UTC'

export type DateStyle = 'short' | 'medium' | 'long'

const DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/

/** True for a real calendar date in `YYYY-MM-DD` form (rejects 2026-02-30). */
export function isIsoDate(value: string): boolean {
    const match = DATE_PATTERN.exec(value)

    if (match === null) {
        return false
    }

    const [year, month, day] = [Number(match[1]), Number(match[2]), Number(match[3])]
    const probe = new Date(Date.UTC(year, month - 1, day))

    return probe.getUTCFullYear() === year && probe.getUTCMonth() === month - 1 && probe.getUTCDate() === day
}

function toUtcDate(value: string): Date {
    if (!isIsoDate(value)) {
        throw new RangeError(`"${value}" is not a valid YYYY-MM-DD date.`)
    }

    const [year, month, day] = value.split('-').map(Number) as [number, number, number]

    return new Date(Date.UTC(year, month - 1, day))
}

/** Adds calendar days to a plain date; time-zone and daylight-saving independent. */
export function addDays(value: string, days: number): string {
    const date = toUtcDate(value)
    date.setUTCDate(date.getUTCDate() + days)

    return date.toISOString().slice(0, 10)
}

/** The number of nights from one plain date to a later one (negative when the second is earlier). */
export function nightsBetween(from: string, to: string): number {
    return Math.round((toUtcDate(to).getTime() - toUtcDate(from).getTime()) / 86_400_000)
}

/** The short weekday name of a plain date, pinned to UTC so no zone can move it to another day. */
export function formatWeekday(value: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' }).format(toUtcDate(value))
}

export function compareDates(a: string, b: string): -1 | 0 | 1 {
    toUtcDate(a)
    toUtcDate(b)

    return a < b ? -1 : a > b ? 1 : 0
}

/** Formats a plain date. It is pinned to UTC so no zone can move it to another day. */
export function formatDate(value: string, locale: string, style: DateStyle = 'medium'): string {
    return new Intl.DateTimeFormat(locale, { dateStyle: style, timeZone: 'UTC' }).format(toUtcDate(value))
}

/** A valid IANA zone, or the explicit fallback; an invalid value never reaches Intl as a crash. */
export function resolveTimeZone(timeZone: string | null | undefined): string {
    if (timeZone === null || timeZone === undefined || timeZone === '') {
        return FALLBACK_TIME_ZONE
    }

    try {
        new Intl.DateTimeFormat('en', { timeZone })

        return timeZone
    } catch {
        return FALLBACK_TIME_ZONE
    }
}

/**
 * Parses an instant. Strings must carry `Z` or a numeric offset: a bare local
 * time is ambiguous and is rejected instead of being read in the browser zone.
 */
export function parseInstant(value: Date | string | number): Date {
    if (value instanceof Date) {
        return new Date(value.getTime())
    }

    if (typeof value === 'number') {
        return new Date(value)
    }

    if (!/(Z|[+-]\d{2}:?\d{2})$/i.test(value)) {
        throw new RangeError('An instant must include a UTC offset or Z.')
    }

    const parsed = new Date(value)

    if (Number.isNaN(parsed.getTime())) {
        throw new RangeError('The value is not a valid instant.')
    }

    return parsed
}

export function fromEpochSeconds(seconds: number): Date {
    return new Date(seconds * 1000)
}

export type InstantFormatOptions = {
    locale: string
    timeZone: string | null | undefined
    /** Show the zone name (default). Hide only where the zone is already stated next to the value. */
    showZone?: boolean
    seconds?: boolean
}

/** Displays an instant on the property's wall clock, with the zone name. */
export function formatInstant(value: Date | string | number, options: InstantFormatOptions): string {
    // Component options (not dateStyle/timeStyle): the style shorthands can not be combined with a zone name.
    return new Intl.DateTimeFormat(options.locale, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        ...(options.seconds ? { second: '2-digit' as const } : {}),
        ...(options.showZone === false ? {} : { timeZoneName: 'short' as const }),
        timeZone: resolveTimeZone(options.timeZone),
    }).format(parseInstant(value))
}

/** The clock date (`YYYY-MM-DD`) of an instant in a zone, e.g. for grouping by local day. */
export function calendarDateIn(value: Date | string | number, timeZone: string | null | undefined): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        timeZone: resolveTimeZone(timeZone),
    }).formatToParts(parseInstant(value))

    const get = (type: string) => parts.find((part) => part.type === type)?.value ?? ''

    return `${get('year')}-${get('month')}-${get('day')}`
}
