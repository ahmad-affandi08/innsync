import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { FNB_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; wide?: boolean; printClass?: string };

/** Common frame of the F&B pages. */
export function FnbShell({ actions, children, description, printClass, title, wide }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={FNB_LINKS} printClass={printClass} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
