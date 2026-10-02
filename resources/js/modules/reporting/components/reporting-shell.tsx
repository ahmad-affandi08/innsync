import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/dashboard', label: 'rpt.nav.dashboard' },
    { href: '/reports', label: 'rpt.nav.reports' },
    { href: '/reports/builder', label: 'rpt.nav.builder' },
    { href: '/reports/exports', label: 'rpt.nav.exports' },
    { href: '/reports/outlets', label: 'rpt.nav.outlets' },
] as const;

type Props = { title: string; description: string; children: ReactNode; wide?: boolean; };

/** Common frame of the dashboard and report pages. */
export function ReportingShell({ children, description, title, wide }: Props) {
    return (
        <AppFrame description={description} links={LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
