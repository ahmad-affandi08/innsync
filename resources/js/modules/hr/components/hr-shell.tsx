import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { HR_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode };

/** Common frame of the human resource pages. */
export function HrShell({ actions, children, description, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={HR_LINKS} title={title} wide>
            {children}
        </AppFrame>
    );
}
