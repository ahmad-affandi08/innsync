/**
 * Money display (TASK-FND-018, ADR-0006). The server sends integer minor units
 * plus an ISO 4217 code; the number of decimals comes from the currency
 * (ISO 4217 via Intl: USD 2, JPY 0, KWD 3, IDR 2), never from a guess. The browser never does arithmetic
 * on money, it only formats what the server computed.
 */
export class MoneyFormatError extends Error {}

const CURRENCY_PATTERN = /^[A-Z]{3}$/

/** Currencies whose minor units the server defines and the browser's own data must not change. */
const FIXED_EXPONENTS: Readonly<Record<string, number>> = { IDR: 2 }

export function currencyExponent(currency: string): number {
    if (!CURRENCY_PATTERN.test(currency)) {
        throw new MoneyFormatError(`Invalid currency code: ${currency}`)
    }

    // Rupiah is fixed: the server keeps it in sen (ISO 4217: two digits), whatever the browser's currency data says. Newer currency data (CLDR 48, in the newest browsers and Node) lists IDR with no decimals,
    // which would show every amount a hundred times too big and read every typed amount a hundred times too small.
    const fixed = FIXED_EXPONENTS[currency]

    return fixed ?? new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2
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
        // The digits are named, not left to the browser's data, so a stray sen is shown and never rounded away.
        minimumFractionDigits: trimmed ? 0 : exponent,
        maximumFractionDigits: trimmed ? 0 : exponent,
    })
    const decimal = exponent === 0 ? '' : `.${String(fraction).padStart(exponent, '0')}`

    return formatter.format(`${sign < 0 ? '-' : ''}${whole}${decimal}` as unknown as number)
}

/**
 * Reads an amount typed by a person into integer minor units. Only digits and one decimal separator (`.` or `,`) are
 * accepted: thousands separators are refused because "1.500" means 1500 in Indonesian and 1.5 in English, and a wrong
 * guess about money is worse than asking again. Returns null when the text is not a clear amount.
 */
export function parseMajorToMinor(text: string, currency: string): number | null {
    const exponent = currencyExponent(currency)
    const match = /^(\d{1,13})(?:[.,](\d{1,3}))?$/.exec(text.trim())

    if (match === null) {
        return null
    }

    const fraction = match[2] ?? ''

    // "1.500" with a currency that has fewer than three decimals is a grouped number, not a fraction.
    if (fraction.length > exponent) {
        return null
    }

    const minor = Number(match[1]) * 10 ** exponent + Number((fraction + '0'.repeat(exponent)).slice(0, exponent) || 0)

    return Number.isSafeInteger(minor) ? minor : null
}

/** Minor units as the text of an input: "-1500.5" for -150050 in a currency with two decimals. */
export function minorToMajorText(minor: number, currency: string): string {
    const exponent = currencyExponent(currency);
    const absolute = Math.abs(minor);
    const whole = Math.trunc(absolute / 10 ** exponent);
    const fraction = String(absolute % 10 ** exponent).padStart(exponent, '0').replace(/0+$/, '');

    return `${minor < 0 ? '-' : ''}${whole}${fraction === '' ? '' : `.${fraction}`}`;
}
