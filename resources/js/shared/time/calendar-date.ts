import { isIsoDate } from './time.ts'

/**
 * Bridges the ISO calendar dates the app stores ("2026-10-03", no zone) and
 * the local `Date` objects a calendar widget works with. A calendar date has
 * no time zone, so both directions use the local fields, never UTC.
 */
export function parseCalendarDate(value: string | null | undefined): Date | undefined {
    if (value === null || value === undefined || !isIsoDate(value)) return undefined

    const [y, m, d] = value.split('-').map(Number) as [number, number, number]

    return new Date(y, m - 1, d)
}

export function toCalendarDate(date: Date): string {
    const pad = (n: number, width = 2) => String(n).padStart(width, '0')

    return `${pad(date.getFullYear(), 4)}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
