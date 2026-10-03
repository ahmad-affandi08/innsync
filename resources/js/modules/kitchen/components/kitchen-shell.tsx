import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/kitchen', label: 'kitchen.nav.board' },
    { href: '/kitchen/recipes', label: 'kitchen.nav.recipes' },
    { href: '/kitchen/menu-report', label: 'kitchen.nav.report' },
    { href: '/kitchen/waste', label: 'kitchen.nav.waste' },
    { href: '/kitchen/routines', label: 'kitchen.nav.routines' },
    { href: '/inventory/lots?department=kitchen', label: 'kitchen.nav.lots' },
    { href: '/inventory/requisitions', label: 'inv.nav.requisitions' },
    { href: '/inventory/counts?location_kind=kitchen', label: 'kitchen.nav.counts' },
    { href: '/kitchen/damage-reports', label: 'kitchen.nav.damage' },
    { href: '/inventory/requests?department=kitchen', label: 'kitchen.nav.purchasing' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode };

/** Common frame of the kitchen pages. */
export function KitchenShell({ actions, children, description, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide>
            {children}
        </AppFrame>
    );
}
