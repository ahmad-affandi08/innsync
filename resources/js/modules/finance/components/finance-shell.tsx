import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/finance/payables', label: 'fin.nav.payables' },
    { href: '/finance/payments', label: 'fin.nav.payments' },
    { href: '/finance/schedule', label: 'fin.nav.schedule' },
    { href: '/finance/aging', label: 'fin.nav.aging' },
    { href: '/finance/revenue', label: 'fin.nav.revenue' },
    { href: '/finance/cash', label: 'fin.nav.cash' },
    { href: '/finance/accounts', label: 'fin.nav.accounts' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; wide?: boolean };

/** Common frame of the Finance pages. */
export function FinanceShell({ actions, children, description, title, wide }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
