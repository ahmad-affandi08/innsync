/**
 * Stock quantities are whole thousandths of a unit (12,500 is 12.5), the same as on the server, so nothing here uses a float for stock.
 * `toBaseMilli` mirrors `StockQuantity::toBase` for the preview only; the server works the number out again and is the one that counts.
 */
export function parseMilli(text: string): number | null {
    const match = /^(\d{1,7})(?:\.(\d{1,3}))?$/.exec(text.trim().replace(',', '.'))

    return match === null ? null : Number(match[1]) * 1000 + Number((match[2] ?? '').padEnd(3, '0'))
}

export function toBaseMilli(unitQtyMilli: number, factorMilli: number): number {
    return Math.floor((Math.abs(unitQtyMilli) * factorMilli + 500) / 1000) * (unitQtyMilli < 0 ? -1 : 1)
}

/** 12,500 -> "12.5" in English and "12,5" in Indonesian; never more than three decimals. */
export function formatMilli(milli: number, locale: string): string {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: 3 }).format(milli / 1000)
}

/** 12,500 -> "12.5": what an input field is filled with, always with a dot, which the server reads. */
export function plainMilli(milli: number): string {
    return String(milli / 1000)
}
