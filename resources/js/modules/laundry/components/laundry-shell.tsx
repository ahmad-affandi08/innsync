import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { LAUNDRY_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; wide?: boolean; };

/** Common frame of the Laundry pages. */
export function LaundryShell({ children, description, title, wide }: Props) {
    return (
        <AppFrame description={description} links={LAUNDRY_LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
