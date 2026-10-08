/**
 * A money field shows an amount the way the language writes it (1.500.000 in Indonesian, 1,500,000 in English) while the value kept and sent stays plain: digits, one `.` for
 * the decimals and a leading `-` when negative is allowed. That is the text `parseMajorToMinor` already reads, so no page changes how it turns a field into money.
 */

export type MoneyOptions = { decimals?: number; negative?: boolean };

function separators(locale: string): { group: string; decimal: string } {
    const parts = new Intl.NumberFormat(locale).formatToParts(1234567.8);

    return { group: parts.find((p) => p.type === 'group')?.value ?? ',', decimal: parts.find((p) => p.type === 'decimal')?.value ?? '.' };
}

/** What is kept for text the person typed: only digits, the language's decimal mark (kept as `.`) and a leading minus, with the decimals cut to what the currency has. */
export function rawFromTyped(text: string, locale: string, { decimals = 2, negative = false }: MoneyOptions = {}): string {
    const { decimal } = separators(locale);
    const minus = negative && text.trim().startsWith('-') ? '-' : '';
    let whole = '';
    let fraction: string | null = null;

    for (const ch of text) {
        if (ch >= '0' && ch <= '9') {
            if (fraction === null) whole += ch;
            else if (fraction.length < decimals) fraction += ch;
        } else if (ch === decimal && fraction === null && decimals > 0) {
            fraction = '';
        }
    }

    whole = whole.replace(/^0+(?=\d)/, '');

    return minus + whole + (fraction === null ? '' : `.${fraction}`);
}

/**
 * What is kept for text pasted in. A pasted amount may come from anywhere, so it is read more carefully than typing: when both marks are present the last one is the decimal mark;
 * a single mark followed by one or two digits is a decimal mark when the currency has decimals, otherwise (`1.500`, `1,500,000`) it only groups the thousands.
 */
export function rawFromPasted(text: string, locale: string, options: MoneyOptions = {}): string {
    const decimals = options.decimals ?? 2;
    const cleaned = text.replace(/[^\d.,-]/g, '');
    const lastDot = cleaned.lastIndexOf('.');
    const lastComma = cleaned.lastIndexOf(',');
    let mark: '.' | ',' | null = null;

    if (lastDot >= 0 && lastComma >= 0) {
        mark = lastDot > lastComma ? '.' : ',';
    } else if (decimals > 0) {
        const only = lastDot >= 0 ? '.' : lastComma >= 0 ? ',' : null;

        if (only !== null && cleaned.split(only).length === 2 && new RegExp(`\\${only}\\d{1,${Math.min(decimals, 2)}}$`).test(cleaned)) mark = only;
    }

    const normalized = mark === null ? cleaned.replace(/[.,]/g, '') : cleaned.slice(0, cleaned.lastIndexOf(mark)).replace(/[.,]/g, '') + '.' + cleaned.slice(cleaned.lastIndexOf(mark) + 1).replace(/[.,]/g, '');
    const { decimal } = separators(locale);

    return rawFromTyped(normalized.replace('.', decimal), locale, options);
}

/** The kept text, written for the eye: thousands grouped, the decimal mark of the language. */
export function displayFromRaw(raw: string, locale: string): string {
    const { group, decimal } = separators(locale);
    const minus = raw.startsWith('-') ? '-' : '';
    const body = raw.replace('-', '');
    const [whole = '', fraction] = body.split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, group);

    return minus + grouped + (fraction === undefined ? '' : decimal + fraction);
}

/** The decimal mark of a language, so a field can tell it from the mark that groups thousands. */
export function decimalMark(locale: string): string {
    return separators(locale).decimal;
}

/** How many digits and decimal marks are in front of a position: what must still be in front of the caret once the text is rewritten. */
export function significantBefore(text: string, position: number, locale: string): number {
    const mark = decimalMark(locale);
    let n = 0;

    for (const ch of text.slice(0, position)) if ((ch >= '0' && ch <= '9') || ch === mark) n += 1;

    return n;
}

/** Where the caret belongs after the text was rewritten: after as many digits and decimal marks as were in front of it before. */
export function caretAfter(shown: string, significant: number, locale: string): number {
    const mark = decimalMark(locale);

    if (significant <= 0) return shown.startsWith('-') ? 1 : 0;

    let seen = 0;

    for (let i = 0; i < shown.length; i += 1) {
        const ch = shown[i] ?? '';

        if ((ch >= '0' && ch <= '9') || ch === mark) seen += 1;

        if (seen === significant) return i + 1;
    }

    return shown.length;
}
