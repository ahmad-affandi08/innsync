import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/inventory/stock', label: 'inv.nav.stock' },
    { href: '/inventory/transfers', label: 'inv.nav.transfers' },
    { href: '/inventory/counts', label: 'inv.nav.counts' },
    { href: '/inventory/items', label: 'inv.nav.items' },
    { href: '/inventory/locations', label: 'inv.nav.locations' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; wide?: boolean };

/** Common frame of the Inventory pages. */
export function InventoryShell({ actions, children, description, title, wide }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
