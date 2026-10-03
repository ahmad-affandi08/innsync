import type { ReactNode } from 'react';

import { AppFrame } from '@/components/layout/app-frame';

const LINKS = [
    { href: '/finance/payables', label: 'fin.nav.payables' },
    { href: '/finance/payments', label: 'fin.nav.payments' },
    { href: '/finance/schedule', label: 'fin.nav.schedule' },
    { href: '/finance/aging', label: 'fin.nav.aging' },
    { href: '/finance/receivables', label: 'fin.nav.receivables' },
    { href: '/finance/receivables/aging', label: 'fin.nav.receivableAging' },
    { href: '/finance/customers', label: 'fin.nav.customers' },
    { href: '/finance/revenue', label: 'fin.nav.revenue' },
    { href: '/finance/cash', label: 'fin.nav.cash' },
    { href: '/finance/corrections', label: 'fin.nav.corrections' },
    { href: '/finance/exceptions', label: 'fin.nav.exceptions' },
    { href: '/finance/audit', label: 'fin.nav.audit' },
    { href: '/finance/petty', label: 'fin.nav.petty' },
    { href: '/finance/pnl', label: 'fin.nav.pnl' },
    { href: '/finance/cashflow', label: 'fin.nav.cashflow' },
    { href: '/finance/stock-value', label: 'fin.nav.stockValue' },
    { href: '/finance/food-cost', label: 'fin.nav.foodCost' },
    { href: '/finance/recurring', label: 'fin.nav.recurring' },
    { href: '/finance/budget', label: 'fin.nav.budget' },
    { href: '/finance/budget/report', label: 'fin.nav.budgetReport' },
    { href: '/finance/payroll', label: 'fin.nav.payroll' },
    { href: '/finance/export', label: 'fin.nav.export' },
    { href: '/finance/accounts', label: 'fin.nav.accounts' },
] as const;

type Props = { title: string; description: string; children: ReactNode; actions?: ReactNode; wide?: boolean };

/** Common frame of the Finance pages. */
export function FinanceShell({ actions, children, description, title, wide }: Props) {
    return (
        <AppFrame actions={actions} description={description} links={LINKS} title={title} wide={wide}>
            {children}
        </AppFrame>
    );
}
