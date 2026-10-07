import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/housekeeping', label: 'hk.nav.board' },
    { href: '/housekeeping/my-rooms', label: 'hk.nav.mine' },
    { href: '/housekeeping/checklists', label: 'hk.nav.checklists' },
    { href: '/housekeeping/linen', label: 'hk.nav.linen' },
    { href: '/housekeeping/par-levels', label: 'hk.nav.par' },
    { href: '/housekeeping/supplies', label: 'hk.nav.supplies' },
    { href: '/housekeeping/lost-found', label: 'hk.nav.lostfound' },
    { href: '/housekeeping/damage-reports', label: 'hk.nav.damage' },
    { href: '/inventory/requests?department=housekeeping', label: 'hk.nav.purchasing' },
    { href: '/front-office/room-board', label: 'hk.nav.frontdesk' },
    { href: '/laundry/new', label: 'hk.nav.laundry' },
] as const;

type Props = { title: string; description: string; children: ReactNode; wide?: boolean; };

/** Common frame of the Housekeeping pages. */
export function HousekeepingShell({ children, description, title, wide }: Props) {
    return (
        <AppFrame description={description} links={LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
