import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { PageHeader } from '@/components/ui/page-header';
import { useTranslation } from '@/shared/i18n/i18n';

const LINKS = [
    { href: '/front-office/room-board', label: 'fo.board.nav' },
    { href: '/front-office/availability', label: 'fo.nav.availability' },
    { href: '/front-office/reservations', label: 'fo.nav.reservations' },
    { href: '/front-office/stays', label: 'fo.nav.stays' },
    { href: '/front-office/inventory', label: 'fo.nav.inventory' },
    { href: '/front-office/requests', label: 'fo.req.nav' },
    { href: '/front-office/feedback', label: 'fo.fb.nav' },
    { href: '/front-office/checklists', label: 'fo.sop.nav' },
    { href: '/front-office/logbook', label: 'fo.log.nav' },
    { href: '/front-office/cashier', label: 'fo.cash.nav' },
    { href: '/front-office/groups', label: 'fo.group.nav' },
    { href: '/front-office/companies', label: 'fo.company.nav' },
    { href: '/front-office/foreign-currency', label: 'fo.foreign.nav' },
    { href: '/front-office/night-audit', label: 'fo.nav.audit' },
] as const;

type Props = { title: string; description: string; children: ReactNode; wide?: boolean };

/** Common frame of the Front Office pages. */
export function FrontOfficeShell({ children, description, title, wide = false }: Props) {
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
                    <nav aria-label={t('fo.nav.label')} className="flex flex-wrap gap-2 text-sm">
                        {LINKS.map((link) => (
                            <Link aria-current={path.startsWith(link.href) ? 'page' : undefined} className="rounded-md border border-border px-3 py-1.5 hover:bg-surface-muted aria-[current=page]:bg-surface-muted aria-[current=page]:font-medium" href={link.href} key={link.href}>{t(link.label)}</Link>
                        ))}
                    </nav>
                    {children}
                </section>
            </main>
        </>
    );
}
