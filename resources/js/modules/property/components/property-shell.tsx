import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { PageHeader } from '@/components/ui/page-header';
import { useTranslation } from '@/shared/i18n/i18n';

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode };

/** Common frame of the property configuration pages. */
export function PropertyShell({ actions, children, description, title }: Props) {
    const { t } = useTranslation();

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
                    {children}
                </section>
            </main>
        </>
    );
}
