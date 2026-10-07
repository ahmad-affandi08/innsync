import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { KITCHEN_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; printClass?: string; printHead?: boolean };

/** Common frame of the kitchen pages. */
export function KitchenShell({ actions, children, description, printClass, printHead, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={KITCHEN_LINKS} printClass={printClass} printHead={printHead} title={title} wide>
            {children}
        </AppFrame>
    );
}
