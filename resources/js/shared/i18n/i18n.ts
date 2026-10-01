import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

import { loaders } from '@/locales';
import type { MessageKey } from '@/locales/en/index';
import {
    createTranslator,
    FALLBACK_LOCALE,
    isLocale,
    type Dictionary,
    type Locale,
    type Translator,
} from '@/shared/i18n/translate';

/**
 * Server-resolved locale, shared with every Inertia page by
 * `HandleInertiaRequests`. The server is the single source of truth: it
 * negotiates stored preference / Accept-Language / default, so the first
 * paint is already in the right language.
 */
declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: {
            locale: Locale;
        };
    }
}

const loaded = new Map<Locale, Dictionary>();

/**
 * Loads the requested dictionary plus the English source (used for fallback)
 * before a page renders, so no component ever flashes raw keys.
 */
export async function ensureMessages(locale: Locale): Promise<void> {
    const wanted = Array.from(new Set<Locale>([locale, FALLBACK_LOCALE]));

    await Promise.all(
        wanted
            .filter((candidate) => !loaded.has(candidate))
            .map(async (candidate) => {
                loaded.set(candidate, await loaders[candidate]());
            }),
    );
}

export function localeFromProps(value: unknown): Locale {
    return isLocale(value) ? value : FALLBACK_LOCALE;
}

export function useTranslation(): Translator<MessageKey> {
    const locale = localeFromProps(usePage().props.locale);

    return useMemo(
        () =>
            createTranslator<MessageKey>(
                locale,
                loaded.get(locale) ?? {},
                loaded.get(FALLBACK_LOCALE) ?? {},
            ),
        [locale],
    );
}

/** Locale-aware formatting. Time-zone and business-date rules belong to TASK-FND-014. */
export function useFormatters() {
    const { locale } = useTranslation();

    return useMemo(
        () => ({
            dateTime: (epochSeconds: number) =>
                new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(
                    new Date(epochSeconds * 1000),
                ),
            number: (value: number) => new Intl.NumberFormat(locale).format(value),
        }),
        [locale],
    );
}
