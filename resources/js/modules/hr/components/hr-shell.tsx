import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/hr/me', label: 'hr.nav.me' },
    { href: '/hr/employees', label: 'hr.nav.employees' },
    { href: '/hr/roster', label: 'hr.nav.roster' },
    { href: '/hr/attendance', label: 'hr.nav.attendance' },
    { href: '/hr/leave', label: 'hr.nav.leave' },
    { href: '/hr/swaps', label: 'hr.nav.swaps' },
    { href: '/hr/performance', label: 'hr.nav.performance' },
    { href: '/hr/payroll', label: 'hr.nav.payroll' },
    { href: '/hr/service-charge', label: 'hr.nav.serviceCharge' },
    { href: '/hr/payroll/runs', label: 'hr.nav.payrollRuns' },
    { href: '/hr/payslips', label: 'hr.nav.payslips' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode };

/** Common frame of the human resource pages. */
export function HrShell({ actions, children, description, title }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide>
            {children}
        </AppFrame>
    );
}
