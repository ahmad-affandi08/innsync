import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { PageHeader } from '@/components/ui/page-header';
import { useTranslation } from '@/shared/i18n/i18n';

const LINKS = [
    { href: '/housekeeping', label: 'hk.nav.board' },
    { href: '/housekeeping/my-rooms', label: 'hk.nav.mine' },
    { href: '/front-office/room-board', label: 'hk.nav.frontdesk' },
] as const;

type Props = { title: string; description: string; children: ReactNode; wide?: boolean };

/** Common frame of the Housekeeping pages. */
export function HousekeepingShell({ children, description, title, wide = false }: Props) {
    const { t } = useTranslation();
    const path = new URL(usePage().url, 'http://x').pathname;

    return (
        <>
            <Head title={title} />
            <main className="min-h-screen bg-surface-muted px-4 py-10">
                <section className={`mx-auto flex ${wide ? 'max-w-6xl' : 'max-w-4xl'} flex-col gap-6 border border-border bg-surface p-6 shadow-panel sm:p-8`}>
                    <PageHeader
                        actions={<><LanguageSwitcher /><Button asChild variant="outline"><Link href="/">{t('common.action.back')}</Link></Button></>}
                        description={description}
                        title={title}
                    />
                    <nav aria-label={t('hk.nav.label')} className="flex flex-wrap gap-2 text-sm">
                        {LINKS.map((link) => (
                            <Link aria-current={path === link.href ? 'page' : undefined} className="rounded-md border border-border px-3 py-1.5 hover:bg-surface-muted aria-[current=page]:bg-surface-muted aria-[current=page]:font-medium" href={link.href} key={link.href}>{t(link.label)}</Link>
                        ))}
                    </nav>
                    {children}
                </section>
            </main>
        </>
    );
}
