import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

import { loaders } from '@/locales';
import type { MessageKey } from '@/locales/en/index';
import { formatMinorUnits } from '@/shared/money/money';
import { calendarDateIn, formatDate, formatInstant, fromEpochSeconds } from '@/shared/time/time';
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
            /** IANA zone of the active property (NFR-26); null before a property is selected. */
            timeZone: string | null;
            /** Signed-in user and active property (ULIDs, no personal data); null before a property is selected. */
            auth: { userId: string; propertyId: string } | null;
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

/**
 * Locale- and property-zone-aware formatting. Instants are shown on the active
 * property's wall clock with the zone name; with no property selected they are
 * shown in an explicit UTC label. Plain dates (business/calendar dates) are
 * formatted without any zone.
 */
export function useFormatters() {
    const { locale } = useTranslation();
    const timeZone = usePage().props.timeZone ?? null;

    return useMemo(
        () => ({
            timeZone,
            instant: (value: Date | string | number) => formatInstant(value, { locale, timeZone }),
            /** Epoch seconds, as sent by session records. */
            epochSeconds: (seconds: number) => formatInstant(fromEpochSeconds(seconds), { locale, timeZone }),
            date: (isoDate: string, style?: 'short' | 'medium' | 'long') => formatDate(isoDate, locale, style),
            calendarDateOf: (value: Date | string | number) => calendarDateIn(value, timeZone),
            number: (value: number) => new Intl.NumberFormat(locale).format(value),
            /** Integer minor units + ISO currency, as sent by the server (ADR-0006). */
            money: (amountMinor: number, currency: string) => formatMinorUnits(amountMinor, currency, locale),
        }),
        [locale, timeZone],
    );
}
