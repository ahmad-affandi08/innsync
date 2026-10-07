import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { FINANCE_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; wide?: boolean };

/** Common frame of the Finance pages. */
export function FinanceShell({ actions, children, description, title, wide }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={FINANCE_LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
