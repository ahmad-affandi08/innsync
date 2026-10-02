import { router } from '@inertiajs/react';

import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { useTranslation } from '@/shared/i18n/i18n';
import { SUPPORTED_LOCALES, isLocale } from '@/shared/i18n/translate';

/**
 * Language choice for staff and guests. The choice is stored server-side
 * (session) and applied to the next response, so the whole page, including
 * server-generated validation messages, switches together.
 */
function LanguageSwitcher({ className }: { className?: string }) {
    const { locale, t } = useTranslation();

    return (
        <div className={className}>
            <Label className="sr-only" htmlFor="language-switcher">
                {t('common.language.label')}
            </Label>
            <Select
                className="min-h-10 w-auto"
                searchable={false}
                id="language-switcher"
                onChange={(event) => {
                    const next = event.target.value;

                    if (isLocale(next) && next !== locale) {
                        router.post('/locale', { locale: next }, { preserveScroll: true, preserveState: true });
                    }
                }}
                value={locale}
            >
                {SUPPORTED_LOCALES.map((option) => (
                    // Endonyms stay in their own language, and `lang` lets screen readers pronounce them.
                    <option key={option} lang={option} value={option}>
                        {t(`common.language.${option}`)}
                    </option>
                ))}
            </Select>
        </div>
    );
}

export { LanguageSwitcher };
