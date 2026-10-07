/** What a person types into a field for a time of day becomes HH:MM as they type: only digits, a colon after the hour. */
export function formatTypedTime(raw: string): string {
    const digits = raw.replace(/\D/g, '').slice(0, 4)

    return digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits
}

/**
 * The time of day a typed value means, as HH:MM; '' for nothing; null when it is not a time. `9` is 09:00, `930` is 09:30 and `0930` is 09:30,
 * which is what a person on a phone expects of a field that shows no clock.
 */
export function completeTime(value: string): string | null {
    const digits = value.replace(/\D/g, '').slice(0, 4)

    if (digits === '') return ''

    const hour = digits.length <= 2 ? digits : digits.length === 3 ? digits.slice(0, 1) : digits.slice(0, 2)
    const minute = digits.length <= 2 ? '00' : digits.slice(digits.length - 2)
    const h = Number(hour)
    const m = Number(minute)

    if (!Number.isInteger(h) || !Number.isInteger(m) || h > 23 || m > 59) return null

    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}
