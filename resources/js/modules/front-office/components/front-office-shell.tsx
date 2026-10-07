import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { FRONT_OFFICE_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; wide?: boolean; };

/** Common frame of the Front Office pages. */
export function FrontOfficeShell({ children, description, title, wide }: Props) {
    return (
        <AppFrame description={description} links={FRONT_OFFICE_LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
