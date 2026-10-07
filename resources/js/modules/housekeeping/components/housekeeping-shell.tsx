import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { HOUSEKEEPING_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; wide?: boolean; };

/** Common frame of the Housekeeping pages. */
export function HousekeepingShell({ children, description, title, wide }: Props) {
    return (
        <AppFrame description={description} links={HOUSEKEEPING_LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
