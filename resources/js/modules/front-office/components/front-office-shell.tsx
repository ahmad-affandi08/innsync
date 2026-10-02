import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/front-office/room-board', label: 'fo.board.nav' },
    { href: '/front-office/availability', label: 'fo.nav.availability' },
    { href: '/front-office/reservations', label: 'fo.nav.reservations' },
    { href: '/front-office/stays', label: 'fo.nav.stays' },
    { href: '/front-office/inventory', label: 'fo.nav.inventory' },
    { href: '/front-office/requests', label: 'fo.req.nav' },
    { href: '/front-office/feedback', label: 'fo.fb.nav' },
    { href: '/front-office/checklists', label: 'fo.sop.nav' },
    { href: '/front-office/logbook', label: 'fo.log.nav' },
    { href: '/front-office/cashier', label: 'fo.cash.nav' },
    { href: '/front-office/groups', label: 'fo.group.nav' },
    { href: '/front-office/companies', label: 'fo.company.nav' },
    { href: '/front-office/foreign-currency', label: 'fo.foreign.nav' },
    { href: '/front-office/night-audit', label: 'fo.nav.audit' },
] as const;

type Props = { title: string; description: string; children: ReactNode; wide?: boolean; };

/** Common frame of the Front Office pages. */
export function FrontOfficeShell({ children, description, title, wide }: Props) {
    return (
        <AppFrame description={description} links={LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
