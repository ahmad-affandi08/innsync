import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { PROPERTY_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; };

/** Common frame of the property configuration pages. */
export function PropertyShell({ actions, children, description, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={PROPERTY_LINKS} title={title}>
            {children}
        </AppFrame>
    );
}
