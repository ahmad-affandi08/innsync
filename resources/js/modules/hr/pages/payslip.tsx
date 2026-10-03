import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { PayrollLine } from '@/modules/hr/lib/hr';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Slip = { property: string; currency: string; run: { id: string; number: string; period: string; status: string }; employee: PayrollLine['employee']; line: PayrollLine };

/** The electronic payslip of one person for one month, laid out so that it prints on a page. */
export default function PayslipPage({ slip, mine }: { slip: Slip; mine: boolean }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const money = (minor: number) => format.money(minor, slip.currency);
    const kinds = ['earning', 'deduction', 'employer'] as const;

    return (
        <HrShell
            actions={<div className="flex gap-2 print:hidden"><Button onClick={() => window.print()} type="button">{t('hr.slip.print')}</Button><Button asChild variant="outline"><Link href={mine ? '/hr/payslips' : `/hr/payroll/runs?run=${slip.run.id}`}>{t('hr.slip.back')}</Link></Button></div>}
            description={t('hr.slip.description', { period: slip.run.period })}
            title={t('hr.slip.title')}
        >
            <article className="mx-auto flex max-w-2xl flex-col gap-4 border border-border bg-surface p-6" data-testid="hr-payslip">
                <header className="flex flex-col gap-1">
                    <h2 className="text-lg font-semibold">{slip.property}</h2>
                    <p className="text-sm text-muted-foreground">{t('hr.slip.period', { period: slip.run.period, number: slip.run.number })}</p>
                </header>
                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <dt className="text-muted-foreground">{t('hr.col.name')}</dt><dd>{slip.employee.name} · {slip.employee.number}</dd>
                    <dt className="text-muted-foreground">{t('hr.col.department')}</dt><dd>{t(`hr.department.${slip.employee.department}` as MessageKey)} · {slip.employee.position}</dd>
                    <dt className="text-muted-foreground">{t('hr.pay.taxStatus')}</dt><dd>{slip.line.ptkp_status}</dd>
                    <dt className="text-muted-foreground">{t('hr.run.days')}</dt><dd>{t('hr.perf.presentOf', { present: slip.line.present_days, scheduled: slip.line.scheduled_days })}</dd>
                </dl>
                {kinds.map((kind) => {
                    const items = slip.line.items.filter((i) => i.kind === kind);

                    return items.length === 0 ? null : (
                        <section className="text-sm" key={kind}>
                            <h3 className="mb-1 font-semibold">{t(`hr.run.itemKind.${kind}` as MessageKey)}</h3>
                            <ul>{items.map((i, n) => <li className="flex justify-between gap-4" key={`${i.code}-${n}`}><span>{i.label}</span><span>{money(i.amount_minor)}</span></li>)}</ul>
                        </section>
                    );
                })}
                <footer className="flex justify-between border-t border-border pt-3 text-base font-semibold"><span>{t('hr.run.net')}</span><span>{money(slip.line.net_minor)}</span></footer>
            </article>
        </HrShell>
    );
}
