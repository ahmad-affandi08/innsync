import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [{ href: '/hr/employees', label: 'hr.nav.employees' }] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode };

/** Common frame of the human resource pages. */
export function HrShell({ actions, children, description, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide>
            {children}
        </AppFrame>
    );
}
