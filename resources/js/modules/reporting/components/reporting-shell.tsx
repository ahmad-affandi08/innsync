import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { PageHeader } from '@/components/ui/page-header';
import { useTranslation } from '@/shared/i18n/i18n';

const LINKS = [
    { href: '/dashboard', label: 'rpt.nav.dashboard' },
    { href: '/reports', label: 'rpt.nav.reports' },
    { href: '/reports/exports', label: 'rpt.nav.exports' },
    { href: '/reports/outlets', label: 'rpt.nav.outlets' },
] as const;

type Props = { title: string; description: string; children: ReactNode; wide?: boolean };

/** Common frame of the dashboard and report pages. */
export function ReportingShell({ children, description, title, wide = false }: Props) {
    const { t } = useTranslation();
    const path = new URL(usePage().url, 'http://x').pathname;

    return (
        <>
            <Head title={title} />
            <main className="min-h-screen bg-surface-muted px-4 py-10 print:bg-white print:p-0">
                <section className={`mx-auto flex ${wide ? 'max-w-6xl' : 'max-w-4xl'} flex-col gap-6 border border-border bg-surface p-6 shadow-panel sm:p-8 print:border-0 print:shadow-none`}>
                    <div className="print:hidden">
                        <PageHeader
                            actions={<><LanguageSwitcher /><Button asChild variant="outline"><Link href="/">{t('common.action.back')}</Link></Button></>}
                            description={description}
                            title={title}
                        />
                    </div>
                    <div className="hidden print:block"><h1 className="text-xl font-semibold">{title}</h1></div>
                    <nav aria-label={t('rpt.nav.label')} className="flex flex-wrap gap-2 text-sm print:hidden">
                        {LINKS.map((link) => (
                            <Link aria-current={path === link.href || (link.href === '/reports' && path.startsWith('/reports')) ? 'page' : undefined} className="rounded-md border border-border px-3 py-1.5 hover:bg-surface-muted aria-[current=page]:bg-surface-muted aria-[current=page]:font-medium" href={link.href} key={link.href}>{t(link.label)}</Link>
                        ))}
                    </nav>
                    {children}
                </section>
            </main>
        </>
    );
}
