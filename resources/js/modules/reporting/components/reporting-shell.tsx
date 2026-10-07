import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';
import { REPORTING_LINKS } from '@/components/layout/module-links';


type Props = { title: string; description: string; children: ReactNode; wide?: boolean; };

/** Common frame of the dashboard and report pages. */
export function ReportingShell({ children, description, title, wide }: Props) {
    return (
        <AppFrame description={description} links={REPORTING_LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
