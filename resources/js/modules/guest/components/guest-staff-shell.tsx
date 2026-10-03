import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/guest/orders', label: 'guest.nav.orders' },
    { href: '/guest/qr', label: 'guest.nav.qr' },
    { href: '/fnb/pos', label: 'fnb.nav.pos' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; wide?: boolean };

/** Common frame of the staff pages of the guest self-service. */
export function GuestStaffShell({ actions, children, description, title, wide }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
