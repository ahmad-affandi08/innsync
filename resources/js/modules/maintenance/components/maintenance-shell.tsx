import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/maintenance', label: 'mtc.nav.orders' },
    { href: '/maintenance/assets', label: 'mtc.nav.assets' },
    { href: '/maintenance/vendor-work', label: 'mtc.nav.vendor' },
    { href: '/maintenance/reports', label: 'mtc.nav.reports' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode };

/** Common frame of the maintenance pages. */
export function MaintenanceShell({ actions, children, description, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide>
            {children}
        </AppFrame>
    );
}
