import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/property/settings', label: 'property.action.settings' },
    { href: '/property/rooms', label: 'property.action.rooms' },
    { href: '/property/rates', label: 'property.action.rates' },
    { href: '/property/tax', label: 'property.action.tax' },
    { href: '/property/policies', label: 'policy.nav' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; };

/** Common frame of the property configuration pages. */
export function PropertyShell({ actions, children, description, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title}>
            {children}
        </AppFrame>
    );
}
