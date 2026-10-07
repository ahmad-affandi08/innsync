import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { INVENTORY_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; wide?: boolean };

/** Common frame of the Inventory pages. */
export function InventoryShell({ actions, children, description, title, wide }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={INVENTORY_LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
