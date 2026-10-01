import type { Dictionary, Locale } from '../shared/i18n/translate.ts'

/** Each locale is a separate chunk so a guest page loads only the language it needs. */
export const loaders: Record<Locale, () => Promise<Dictionary>> = {
    en: async () => (await import('./en/index.ts')).en,
    id: async () => (await import('./id/index.ts')).id,
}
