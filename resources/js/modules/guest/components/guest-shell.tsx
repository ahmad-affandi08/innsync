import { Link, usePage } from '@inertiajs/react';
import { BellRing, ClipboardList, Receipt, Star, UtensilsCrossed } from 'lucide-react';
import type { ReactNode } from 'react';

import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { useTranslation } from '@/shared/i18n/i18n';
import { PoweredBy } from '@/components/layout/powered-by';
import { useBrand } from '@/shared/lib/brand';
import { cn } from '@/shared/lib/utils';

type Props = { hotel: string; title: string; subtitle?: string; children: ReactNode; /** No menu and no session note: for the pages a link opens, which are not part of a session. */ plain?: boolean };

const TABS = [
    ['/g/menu', 'guest.tab.menu', UtensilsCrossed],
    ['/g/orders', 'guest.tab.orders', ClipboardList],
    ['/g/help', 'guest.tab.help', BellRing],
    ['/g/bill', 'guest.tab.bill', Receipt],
    ['/g/survey', 'guest.tab.survey', Star],
] as const;

/** The frame of the guest pages: the hotel's dark band on top, the page in one readable column, and on a session the pages as tabs at the bottom like a phone app. Light, nothing to install. */
export function GuestShell({ children, hotel, plain = false, subtitle, title }: Props) {
    const { t } = useTranslation();
    const own = useBrand().logoUrl;
    const url = usePage().url;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="bg-primary text-primary-foreground">
                <div className="mx-auto max-w-xl px-4 pb-5 pt-3">
                    <div className="flex items-center justify-between gap-3">
                        {own !== null ? <span className="bg-white px-2 py-1"><img alt={hotel} className="max-h-9 max-w-[9rem] w-auto object-contain" src={own} /></span> : <p className="min-w-0 truncate text-xs font-semibold uppercase tracking-[0.18em] text-brand">{hotel}</p>}
                        <LanguageSwitcher />
                    </div>
                    <h1 className="mt-4 text-2xl font-semibold leading-tight">{title}</h1>
                    {subtitle !== undefined ? <p className="mt-1 text-sm text-primary-foreground/75">{subtitle}</p> : null}
                </div>
            </header>
            <main className={cn('mx-auto flex max-w-xl flex-col gap-4 px-4 py-4', !plain && 'pb-28')}>{children}</main>
            {plain ? null : <footer className="mx-auto max-w-xl px-4 pb-4 text-xs text-muted-foreground">{t('guest.privacy')}</footer>}
            <p className={cn('mx-auto max-w-xl px-4 text-center', plain ? 'pb-6' : 'pb-24')}><PoweredBy /></p>
            {plain ? null : (
                <nav aria-label={t('guest.nav.label')} className="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-surface pb-[env(safe-area-inset-bottom)]">
                    <ul className="mx-auto grid max-w-xl grid-cols-5">
                        {TABS.map(([href, label, Icon]) => {
                            const here = url === href || url.startsWith(`${href}?`) || url.startsWith(`${href}/`);

                            return (
                                <li key={href}>
                                    <Link aria-current={here ? 'page' : undefined} className={cn('flex min-h-14 flex-col items-center justify-center gap-0.5 border-t-2 text-[11px] transition-colors', here ? 'border-brand font-semibold text-accent' : 'border-transparent text-muted-foreground hover:text-foreground')} href={href}>
                                        <Icon aria-hidden="true" className="size-5" />
                                        {t(label)}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
            )}
        </div>
    );
}
