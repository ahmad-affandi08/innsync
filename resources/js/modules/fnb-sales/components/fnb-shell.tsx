import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/fnb/pos', label: 'fnb.nav.pos' },
    { href: '/fnb/shift', label: 'fnb.nav.shift' },
    { href: '/fnb/outlets', label: 'fnb.nav.outlets' },
    { href: '/fnb/menu', label: 'fnb.nav.menu' },
    { href: '/fnb/damage-reports', label: 'fnb.nav.damage' },
    { href: '/inventory/requests?department=fnb', label: 'fnb.nav.purchasing' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; wide?: boolean };

/** Common frame of the F&B pages. */
export function FnbShell({ actions, children, description, title, wide }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
