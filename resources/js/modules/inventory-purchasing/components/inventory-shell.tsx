import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/inventory/stock', label: 'inv.nav.stock' },
    { href: '/inventory/lots', label: 'inv.nav.lots' },
    { href: '/inventory/transfers', label: 'inv.nav.transfers' },
    { href: '/inventory/counts', label: 'inv.nav.counts' },
    { href: '/inventory/suppliers', label: 'inv.nav.suppliers' },
    { href: '/inventory/requests', label: 'inv.nav.requests' },
    { href: '/inventory/orders', label: 'inv.nav.orders' },
    { href: '/inventory/receipts', label: 'inv.nav.receipts' },
    { href: '/inventory/invoices', label: 'inv.nav.invoices' },
    { href: '/inventory/returns', label: 'inv.nav.returns' },
    { href: '/inventory/quotes', label: 'inv.nav.quotes' },
    { href: '/inventory/reports/purchases', label: 'inv.nav.purchaseReport' },
    { href: '/inventory/reports/deliveries', label: 'inv.nav.deliveryReport' },
    { href: '/inventory/purchasing-settings', label: 'inv.nav.purchasingSettings' },
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
