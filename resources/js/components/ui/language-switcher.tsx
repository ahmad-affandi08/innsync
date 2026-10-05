import { router } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';

import idFlag from '@/assets/flags/id.png';
import ukFlag from '@/assets/flags/uk.png';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/shared/i18n/i18n';
import { isLocale } from '@/shared/i18n/translate';
import { cn } from '@/shared/lib/utils';

type LanguageOption = {
    code: 'id' | 'en';
    label: string;
    fullName: string;
    flag: string;
};

const LANGUAGES: Record<'id' | 'en', LanguageOption> = {
    id: {
        code: 'id',
        label: 'ID',
        fullName: 'Bahasa Indonesia',
        flag: idFlag,
    },
    en: {
        code: 'en',
        label: 'ENG',
        fullName: 'English',
        flag: ukFlag,
    },
};

/**
 * Language choice for staff and guests. Displays flag icon with 'ID' or 'ENG'.
 * The choice is stored server-side (session) and applied to the next response,
 * so the whole page, including server-generated validation messages, switches together.
 */
function LanguageSwitcher({ className }: { className?: string }) {
    const { locale, t } = useTranslation();
    const current = LANGUAGES[locale as 'id' | 'en'] ?? LANGUAGES.id;

    function switchLanguage(next: string) {
        if (isLocale(next) && next !== locale) {
            router.post(
                '/locale',
                { locale: next },
                { preserveScroll: true, preserveState: true },
            );
        }
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={`${t('common.language.label')}: ${current.fullName} (${current.label})`}
                className={cn(
                    'inline-flex h-10 cursor-pointer select-none items-center gap-2 border border-input bg-surface px-3 text-xs font-semibold text-foreground transition hover:border-brand/50 hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    className,
                )}
                id="language-switcher"
                type="button"
            >
                <img
                    alt=""
                    aria-hidden="true"
                    className="size-4.5 shrink-0 rounded-full object-cover"
                    src={current.flag}
                />
                <span className="font-semibold tracking-wide">{current.label}</span>
                <ChevronDown
                    aria-hidden="true"
                    className="ml-0.5 size-3.5 text-muted-foreground"
                />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-[10.5rem] p-1">
                {(['id', 'en'] as const).map((code) => {
                    const lang = LANGUAGES[code];
                    const isActive = locale === code;

                    return (
                        <DropdownMenuItem
                            className={cn(
                                'flex cursor-pointer items-center justify-between gap-3 px-2.5 py-2 text-xs',
                                isActive && 'bg-surface-muted font-semibold',
                            )}
                            key={code}
                            lang={code}
                            onClick={() => switchLanguage(code)}
                        >
                            <span className="flex items-center gap-2.5">
                                <img
                                    alt=""
                                    aria-hidden="true"
                                    className="size-4.5 shrink-0 rounded-full object-cover"
                                    src={lang.flag}
                                />
                                <span className="font-semibold tracking-wide">
                                    {lang.label}
                                </span>
                                <span className="text-[11px] font-normal text-muted-foreground">
                                    ({lang.fullName})
                                </span>
                            </span>
                            {isActive ? (
                                <Check
                                    aria-hidden="true"
                                    className="size-3.5 shrink-0 text-brand"
                                />
                            ) : null}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export { LanguageSwitcher };
