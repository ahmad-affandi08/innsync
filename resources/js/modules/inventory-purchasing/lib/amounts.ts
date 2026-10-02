import { currencyExponent } from '../../../shared/money/money.ts'

/**
 * 150000 minor units of a two-decimal currency -> "1500": what a money input is filled with when an existing amount is edited.
 * It is the inverse of `parseMajorToMinor` and works on whole numbers only, so no float touches money.
 */
export function minorToInput(minor: number, currency: string): string {
    const exponent = currencyExponent(currency)
    const sign = minor < 0 ? '-' : ''
    const absolute = Math.abs(minor)
    const whole = Math.trunc(absolute / 10 ** exponent)
    const fraction = String(absolute % 10 ** exponent).padStart(exponent, '0').replace(/0+$/, '')

    return `${sign}${whole}${fraction === '' ? '' : `.${fraction}`}`
}

/** 1100 basis points -> "11", 25 -> "0.25": the percent a person reads and types. */
export function bpToInput(bp: number): string {
    const whole = Math.trunc(bp / 100)
    const fraction = String(bp % 100).padStart(2, '0').replace(/0+$/, '')

    return `${whole}${fraction === '' ? '' : `.${fraction}`}`
}

/** "11" or "0,25" -> basis points (1100 or 25). At most two decimals; anything else is not a clear percentage and gives null. */
export function parsePercentToBp(text: string): number | null {
    const match = /^(\d{1,3})(?:[.,](\d{1,2}))?$/.exec(text.trim())

    return match === null ? null : Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0') || 0)
}
