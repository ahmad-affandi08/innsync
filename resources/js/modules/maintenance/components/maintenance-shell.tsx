import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { MAINTENANCE_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode };

/** Common frame of the maintenance pages. */
export function MaintenanceShell({ actions, children, description, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={MAINTENANCE_LINKS} title={title} wide>
            {children}
        </AppFrame>
    );
}
