/**
 * Translation runtime (TASK-FND-013, NFR-12). Dependency-free and framework
 * neutral so it can be unit-tested without a DOM.
 *
 * UI labels are localized through keys; internal enum/database codes stay
 * language-neutral (docs/DESIGN/12-COPY-TERMINOLOGY.md). Messages are plain
 * text, never HTML: React escapes interpolated values, so a parameter can not
 * inject markup.
 */
export const SUPPORTED_LOCALES = ['id', 'en'] as const

export type Locale = (typeof SUPPORTED_LOCALES)[number]

/** Source language of the dictionaries and last-resort fallback. */
export const FALLBACK_LOCALE: Locale = 'en'

export type MessageParams = Readonly<Record<string, string | number>>

export type Dictionary = Readonly<Record<string, string>>

export interface Translator<K extends string = string> {
    readonly locale: Locale
    /** Looks up a message; a missing key falls back to English, then to the key itself. */
    t: (key: K, params?: MessageParams) => string
    /**
     * Picks `${key}.${category}` using the locale's CLDR plural rules and falls back
     * to `${key}.other`. `count` is always available as `{count}`.
     */
    plural: (key: string, count: number, params?: MessageParams) => string
}

export function isLocale(value: unknown): value is Locale {
    return typeof value === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

/** Replaces `{name}` placeholders; an unknown placeholder is left visible so the gap is noticed. */
export function interpolate(template: string, params?: MessageParams): string {
    if (params === undefined) {
        return template
    }

    return template.replace(/\{(\w+)\}/g, (match, name: string) =>
        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match,
    )
}

/** Placeholder names used by a message, for dictionary parity checks. */
export function placeholders(template: string): string[] {
    return [...new Set([...template.matchAll(/\{(\w+)\}/g)].map((match) => match[1] as string))].sort()
}

export function createTranslator<K extends string = string>(
    locale: Locale,
    primary: Dictionary,
    fallback: Dictionary = primary,
): Translator<K> {
    const rules = new Intl.PluralRules(locale)

    const lookup = (key: string): string | undefined => primary[key] ?? fallback[key]

    return {
        locale,
        t: (key, params) => interpolate(lookup(key) ?? key, params),
        plural: (key, count, params) => {
            const template = lookup(`${key}.${rules.select(count)}`) ?? lookup(`${key}.other`) ?? key

            return interpolate(template, { ...params, count })
        },
    }
}

/**
 * Picks the best supported locale from an `Accept-Language` header or a
 * browser language list. Matches on the primary subtag (`id-ID` -> `id`).
 */
export function negotiateLocale(
    requested: readonly string[],
    supported: readonly Locale[] = SUPPORTED_LOCALES,
    fallback: Locale = FALLBACK_LOCALE,
): Locale {
    for (const tag of requested) {
        const primary = tag.trim().toLowerCase().split(/[-_]/)[0]

        const match = supported.find((locale) => locale === primary)

        if (match !== undefined) {
            return match
        }
    }

    return fallback
}
