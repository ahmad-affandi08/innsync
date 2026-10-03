import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/kitchen', label: 'kitchen.nav.board' },
    { href: '/kitchen/recipes', label: 'kitchen.nav.recipes' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode };

/** Common frame of the kitchen pages. */
export function KitchenShell({ actions, children, description, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide>
            {children}
        </AppFrame>
    );
}
