import { BedDouble, ChartNoAxesCombined, ConciergeBell, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';

import logo from '@/assets/brand/LogoHorizontal.svg';
import favicon from '@/assets/brand/Favicon.svg';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { useTranslation } from '@/shared/i18n/i18n';

type AuthShellProps = {
    title: string;
    description: string;
    children: ReactNode;
};

/** Sign-in frame: the brand beside the form on a large screen, the form alone on a phone. */
export function AuthShell({ children, description, title }: AuthShellProps) {
    const { t } = useTranslation();

    return (
        <main className="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
            <aside className="relative hidden flex-col justify-between overflow-hidden bg-sidebar p-12 text-white lg:flex">
                <div aria-hidden="true" className="pointer-events-none absolute -right-24 -top-24 size-96 rounded-full bg-brand/20 blur-3xl" />
                <div aria-hidden="true" className="pointer-events-none absolute -bottom-32 -left-16 size-96 rounded-full bg-brand/10 blur-3xl" />
                <div className="relative flex items-center gap-3">
                    <img alt="" className="size-11" height={44} src={favicon} width={44} />
                    <span className="text-2xl font-bold tracking-tight">Inn<span className="text-brand">SY</span>nc</span>
                </div>
                <div className="relative max-w-md">
                    <h2 className="text-4xl font-semibold leading-tight tracking-tight">{t('auth.headline')}</h2>
                    <p className="mt-4 text-base leading-7 text-sidebar-muted">{t('auth.tagline')}</p>
                    <ul className="mt-10 grid gap-4 text-sm text-sidebar-foreground">
                        {[
                            { icon: ConciergeBell, text: t('auth.point.frontOffice') },
                            { icon: BedDouble, text: t('auth.point.housekeeping') },
                            { icon: ChartNoAxesCombined, text: t('auth.point.reports') },
                            { icon: ShieldCheck, text: t('auth.point.control') },
                        ].map(({ icon: Icon, text }) => (
                            <li className="flex items-center gap-3" key={text}>
                                <span className="grid size-9 place-items-center rounded-lg bg-white/10"><Icon aria-hidden="true" className="size-[1.1rem] text-brand" /></span>
                                {text}
                            </li>
                        ))}
                    </ul>
                </div>
                <p className="relative text-xs text-sidebar-muted">Smart Hotel Management</p>
            </aside>

            <div className="flex flex-col bg-background px-4 py-8 sm:px-10">
                <div className="flex items-center justify-between gap-3">
                    <img alt="InnSYnc" className="h-9 w-auto lg:hidden" height={36} src={logo} width={131} />
                    <span className="hidden lg:block" />
                    <LanguageSwitcher />
                </div>
                <div className="flex flex-1 items-center justify-center py-10">
                    <section className="w-full max-w-md rounded-2xl border border-border bg-surface p-7 shadow-panel sm:p-9">
                        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                        <p className="mt-2 text-sm leading-6 text-muted-foreground">{description}</p>
                        <div className="mt-7">{children}</div>
                    </section>
                </div>
            </div>
        </main>
    );
}
