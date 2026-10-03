import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { useTranslation } from '@/shared/i18n/i18n';

type Props = { hotel: string; title: string; subtitle?: string; children: ReactNode };

/** The frame of the guest pages: light, one column, readable on a phone on a slow network, no menu and nothing to install. */
export function GuestShell({ children, hotel, subtitle, title }: Props) {
    const { t } = useTranslation();

    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="border-b border-border bg-surface px-4 py-3">
                <div className="mx-auto flex max-w-xl items-center justify-between gap-3">
                    <div>
                        <p className="text-xs uppercase tracking-wide text-muted-foreground">{hotel}</p>
                        <h1 className="text-lg font-semibold leading-tight">{title}</h1>
                        {subtitle !== undefined ? <p className="text-sm text-muted-foreground">{subtitle}</p> : null}
                    </div>
                    <LanguageSwitcher />
                </div>
                <nav aria-label={t('guest.nav.label')} className="mx-auto mt-2 flex max-w-xl flex-wrap gap-x-4 gap-y-1 text-sm">
                    {[['/g/menu', 'guest.nav.menu'], ['/g/orders', 'guest.nav.myOrders'], ['/g/help', 'guest.nav.help'], ['/g/bill', 'guest.nav.bill'], ['/g/survey', 'guest.nav.survey']].map(([href, label]) => (
                        <Link className="underline-offset-2 hover:underline" href={href} key={href}>{t(label as 'guest.nav.menu')}</Link>
                    ))}
                </nav>
            </header>
            <main className="mx-auto flex max-w-xl flex-col gap-4 px-4 py-4">{children}</main>
            <footer className="mx-auto max-w-xl px-4 pb-8 text-xs text-muted-foreground">{t('guest.privacy')}</footer>
        </div>
    );
}
