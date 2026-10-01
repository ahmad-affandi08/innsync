import type { ReactNode } from 'react';

import { LanguageSwitcher } from '@/components/ui/language-switcher';

type AuthShellProps = {
    title: string;
    description: string;
    children: ReactNode;
};

export function AuthShell({ children, description, title }: AuthShellProps) {
    return (
        <main className="flex min-h-screen items-center justify-center bg-surface-muted px-4 py-10">
            <section className="w-full max-w-md border border-border bg-surface p-6 shadow-panel sm:p-8">
                <div className="flex items-center justify-between gap-3">
                    <p className="text-sm font-semibold tracking-wide text-primary">InnSYnc</p>
                    <LanguageSwitcher />
                </div>
                <h1 className="mt-2 text-2xl font-semibold tracking-tight">{title}</h1>
                <p className="mt-2 text-sm leading-6 text-muted-foreground">{description}</p>
                <div className="mt-6">{children}</div>
            </section>
        </main>
    );
}
