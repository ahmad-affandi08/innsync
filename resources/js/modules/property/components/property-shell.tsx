import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { PageHeader } from '@/components/ui/page-header';
import { useTranslation } from '@/shared/i18n/i18n';

const LINKS = [
    { href: '/property/settings', label: 'property.action.settings' },
    { href: '/property/rooms', label: 'property.action.rooms' },
    { href: '/property/rates', label: 'property.action.rates' },
    { href: '/property/tax', label: 'property.action.tax' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode };

/** Common frame of the property configuration pages. */
export function PropertyShell({ actions, children, description, title }: Props) {
    const { t } = useTranslation();
    const path = new URL(usePage().url, 'http://x').pathname;

    return (
        <>
            <Head title={title} />
            <main className="min-h-screen bg-surface-muted px-4 py-10">
                <section className="mx-auto flex max-w-4xl flex-col gap-6 border border-border bg-surface p-6 shadow-panel sm:p-8">
                    <PageHeader
                        actions={
                            <>
                                {actions}
                                <LanguageSwitcher />
                                <Button asChild variant="outline"><Link href="/">{t('common.action.back')}</Link></Button>
                            </>
                        }
                        description={description}
                        title={title}
                    />
                    <nav aria-label={t('property.nav.label')} className="flex flex-wrap gap-2 text-sm">
                        {LINKS.map((link) => (
                            <Link className="rounded-md border border-border px-3 py-1.5 hover:bg-surface-muted aria-[current=page]:bg-surface-muted aria-[current=page]:font-medium" href={link.href} key={link.href} aria-current={path.startsWith(link.href) ? 'page' : undefined}>{t(link.label)}</Link>
                        ))}
                    </nav>
                    {children}
                </section>
            </main>
        </>
    );
}
