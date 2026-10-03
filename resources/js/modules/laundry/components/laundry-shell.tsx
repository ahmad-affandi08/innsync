import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/laundry', label: 'ldy.nav.queue' },
    { href: '/laundry/new', label: 'ldy.nav.new' },
    { href: '/laundry/claims', label: 'ldy.nav.claims' },
    { href: '/laundry/prices', label: 'ldy.nav.prices' },
    { href: '/inventory/requests?department=laundry', label: 'ldy.nav.purchasing' },
] as const;

type Props = { title: string; description: string; children: ReactNode; wide?: boolean; };

/** Common frame of the Laundry pages. */
export function LaundryShell({ children, description, title, wide }: Props) {
    return (
        <AppFrame description={description} links={LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
