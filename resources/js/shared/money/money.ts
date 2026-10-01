/**
 * Money display (TASK-FND-018, ADR-0006). The server sends integer minor units
 * plus an ISO 4217 code; the number of decimals comes from the currency
 * (ISO 4217 via Intl: USD 2, JPY 0, KWD 3, IDR 2), never from a guess. The browser never does arithmetic
 * on money, it only formats what the server computed.
 */
export class MoneyFormatError extends Error {}

const CURRENCY_PATTERN = /^[A-Z]{3}$/

export function currencyExponent(currency: string): number {
    if (!CURRENCY_PATTERN.test(currency)) {
        throw new MoneyFormatError(`Invalid currency code: ${currency}`)
    }

    return new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2
}

/** Rupiah has no coins in practice: amounts are whole rupiah and shown without decimals (docs/OPERATIONS/INDONESIA-COMPLIANCE-BASELINE.md). */
const WHOLE_UNIT_CURRENCIES: readonly string[] = ['IDR']

export function formatMinorUnits(amountMinor: number, currency: string, locale: string): string {
    if (!Number.isSafeInteger(amountMinor)) {
        throw new MoneyFormatError('An amount must be an integer number of minor units.')
    }

    const exponent = currencyExponent(currency)
    const sign = amountMinor < 0 ? -1 : 1
    const absolute = Math.abs(amountMinor)
    const whole = Math.trunc(absolute / 10 ** exponent)
    const fraction = absolute % 10 ** exponent

    // Whole and fractional parts are formatted separately so large amounts never pass through a float division.
    const trimmed = fraction === 0 && WHOLE_UNIT_CURRENCIES.includes(currency)
    const formatter = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        ...(trimmed ? { minimumFractionDigits: 0, maximumFractionDigits: 0 } : {}),
    })
    const decimal = exponent === 0 ? '' : `.${String(fraction).padStart(exponent, '0')}`

    return formatter.format(`${sign < 0 ? '-' : ''}${whole}${decimal}` as unknown as number)
}
