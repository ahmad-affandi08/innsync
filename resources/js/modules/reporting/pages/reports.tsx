import { Link } from '@inertiajs/react';

import { EmptyState } from '@/components/ui/empty-state';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type Report = { code: string; group: string };

const HREF: Record<string, string> = { movements: '/reports/movements', performance: '/reports/performance', comparison: '/reports/comparison', flash: '/reports/flash', payments: '/reports/payments', housekeeping: '/reports/housekeeping', laundry: '/reports/laundry', obligations: '/reports/obligations', registrations: '/reports/registrations', foreign_guests: '/reports/foreign-guests', audit: '/reports/audit' };
const GROUPS = ['management', 'front_office', 'housekeeping', 'laundry', 'control'] as const;

export default function ReportsPage({ reports }: { reports: Report[] }) {
    const { t } = useTranslation();

    return (
        <ReportingShell description={t('rpt.centre.description')} title={t('rpt.centre.title')}>
            {reports.length === 0 ? <EmptyState title={t('rpt.centre.empty')} /> : GROUPS.map((group) => {
                const items = reports.filter((r) => r.group === group);
                if (items.length === 0) return null;
                return (
                    <section aria-labelledby={`g-${group}`} className="flex flex-col gap-2" key={group}>
                        <h2 className="text-lg font-semibold" id={`g-${group}`}>{t(`rpt.group.${group}` as 'rpt.group.management')}</h2>
                        <ul className="divide-y divide-border border-y border-border">
                            {items.map((r) => (
                                <li className="flex flex-col gap-1 py-3" key={r.code}>
                                    <Link className="font-medium underline-offset-2 hover:underline" href={HREF[r.code] ?? '/reports'}>{t(`rpt.report.${r.code}` as 'rpt.report.flash')}</Link>
                                    <span className="text-xs text-muted-foreground">{t(`rpt.report.${r.code}.about` as 'rpt.report.flash.about')}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                );
            })}
        </ReportingShell>
    );
}
