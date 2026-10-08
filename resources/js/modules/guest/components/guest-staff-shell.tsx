import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { GUEST_LINKS } from '@/components/layout/module-links';

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; wide?: boolean };

/** Common frame of the staff pages of the guest self-service. */
export function GuestStaffShell({ actions, children, description, title, wide }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={GUEST_LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
