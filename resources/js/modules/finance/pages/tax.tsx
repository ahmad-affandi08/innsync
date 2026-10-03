import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Totals = { base_minor: number; service_charge_minor: number; tax_minor: number; total_minor: number };
type Filing = Totals & { reported_on: string; report_reference: string | null; deposited_on: string | null; deposited_minor: number | null; deposit_reference: string | null; difference_minor: number | null; lock_version: number };
type Month = {
    period: string; outlets: (Totals & { outlet: string })[]; totals: Totals; set_aside: { non_taxed_minor: number; complimentary_minor: number; discounts_minor: number; voided_minor: number; cancelled_minor: number; reversed_minor: number };
    status: 'collecting' | 'to_report' | 'reported' | 'deposited'; due_date: string; overdue: boolean; filing: Filing | null; may: { report: boolean; deposit: boolean };
};
type Scheme = { effective_from: string; service_charge_bp: number; tax_bp: number; tax_on_service_charge: boolean };
type Overview = {
    currency: string; today: string; settings: { report_day: number; is_baseline: boolean; lock_version: number | null }; may: { manage: boolean }; months: Month[];
    schemes: { scope: string; current: Scheme | null; history: Scheme[] }[]; rounding: { increment_minor: number; mode: string };
};

const TONE: Record<Month['status'], StatusTone> = { collecting: 'neutral', to_report: 'warning', reported: 'info', deposited: 'success' };
const percent = (bp: number) => `${(bp / 100).toFixed(2).replace(/\.00$/, '')}%`;

/** The regional tax and the service charge by month and outlet: what was collected, when it is due, what was reported and deposited, the recap file, and the rates in force. */
export default function TaxPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [report, setReport] = useState<{ period: string; reference: string } | null>(null);
    const [deposit, setDeposit] = useState<{ month: Month; amount: string; on: string; reference: string } | null>(null);
    const [day, setDay] = useState<string | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const money = (minor: number) => format.money(minor, overview.currency);
    const reload = ['overview'];

    async function doReport() {
        if (report === null) return;
        const result = await action.run(`/finance/tax/${report.period}/report`, { body: { reference: report.reference.trim() === '' ? null : report.reference.trim() }, reload });

        if (result !== null) setReport(null);
    }

    async function doDeposit() {
        if (deposit === null) return;
        const result = await action.run(`/finance/tax/${deposit.month.period}/deposit`, { body: { amount_minor: parseMajorToMinor(deposit.amount, overview.currency) ?? 0, deposited_on: deposit.on, reference: deposit.reference.trim(), lock_version: deposit.month.filing?.lock_version ?? 0 }, reload });

        if (result !== null) setDeposit(null);
    }

    async function saveDay() {
        if (day === null) return;
        const result = await action.run('/finance/tax/settings', { body: { report_day: Number(day), lock_version: overview.settings.lock_version }, reload });

        if (result !== null) setDay(null);
    }

    return (
        <FinanceShell actions={overview.may.manage ? <Button onClick={() => { action.clear(); setDay(String(overview.settings.report_day)); }} type="button" variant="outline">{t('fin.tax.setDay')}</Button> : undefined} description={t('fin.tax.description')} title={t('fin.tax.title')} wide>
            {action.error !== null && report === null && deposit === null && day === null ? failure : null}
            {overview.settings.is_baseline ? <Alert title={t('fin.tax.baselineDay', { day: overview.settings.report_day })} tone="warning" /> : null}
            <section className="flex flex-col gap-2" data-testid="tax-schemes">
                <h2 className="text-base font-semibold">{t('fin.tax.rates')}</h2>
                {overview.schemes.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.tax.noRates')}</p> : (
                    <ul className="flex flex-col gap-1 text-sm">
                        {overview.schemes.map((s) => (
                            <li key={s.scope}>
                                <strong>{s.scope}</strong>: {s.current === null ? t('fin.tax.notInForce') : t('fin.tax.inForce', { service: percent(s.current.service_charge_bp), tax: percent(s.current.tax_bp), date: format.date(s.current.effective_from) })}
                                {s.history.length > 1 ? <span className="block text-xs text-muted-foreground">{s.history.map((h) => `${format.date(h.effective_from)}: ${percent(h.service_charge_bp)} + ${percent(h.tax_bp)}`).join(' · ')}</span> : null}
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-xs text-muted-foreground">{t('fin.tax.rounding', { increment: money(overview.rounding.increment_minor), mode: overview.rounding.mode })}</p>
            </section>
            <section className="flex flex-col gap-3" data-testid="tax-months">
                {overview.months.length === 0 ? <EmptyState illustration="checklist" title={t('fin.tax.none')} /> : overview.months.map((m) => (
                    <article className="flex flex-col gap-2 border border-border bg-surface p-4" data-testid={`tax-month-${m.period}`} key={m.period}>
                        <header className="flex flex-wrap items-center gap-3">
                            <h3 className="text-base font-semibold">{m.period}</h3>
                            <StatusBadge label={t(`fin.tax.status.${m.status}` as MessageKey)} tone={m.overdue ? 'danger' : TONE[m.status]} />
                            <span className="text-xs text-muted-foreground">{t('fin.tax.due', { date: format.date(m.due_date) })}{m.overdue ? ` · ${t('fin.tax.overdue')}` : ''}</span>
                        </header>
                        <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                            <div><dt className="text-xs text-muted-foreground">{t('fin.tax.base')}</dt><dd className="tabular-nums">{money(m.totals.base_minor)}</dd></div>
                            <div><dt className="text-xs text-muted-foreground">{t('fin.tax.service')}</dt><dd className="tabular-nums">{money(m.totals.service_charge_minor)}</dd></div>
                            <div><dt className="text-xs text-muted-foreground">{t('fin.tax.tax')}</dt><dd className="font-semibold tabular-nums">{money(m.totals.tax_minor)}</dd></div>
                            <div><dt className="text-xs text-muted-foreground">{t('fin.tax.total')}</dt><dd className="tabular-nums">{money(m.totals.total_minor)}</dd></div>
                        </dl>
                        {m.filing !== null ? <p className="text-xs text-muted-foreground">{t('fin.tax.reportedOn', { date: format.date(m.filing.reported_on), tax: money(m.filing.tax_minor) })}{m.filing.deposited_on !== null ? ` · ${t('fin.tax.depositedOn', { date: format.date(m.filing.deposited_on), amount: money(m.filing.deposited_minor ?? 0), reference: m.filing.deposit_reference ?? '' })}${m.filing.difference_minor !== 0 ? ` · ${t('fin.tax.difference', { amount: money(m.filing.difference_minor ?? 0) })}` : ''}` : ''}</p> : null}
                        <details className="text-sm">
                            <summary className="cursor-pointer text-xs text-muted-foreground">{t('fin.tax.detail')}</summary>
                            <table className="mt-2 w-full text-sm">
                                <thead><tr className="text-left text-xs text-muted-foreground"><th scope="col">{t('fin.tax.outlet')}</th><th className="text-right" scope="col">{t('fin.tax.base')}</th><th className="text-right" scope="col">{t('fin.tax.service')}</th><th className="text-right" scope="col">{t('fin.tax.tax')}</th></tr></thead>
                                <tbody>{m.outlets.map((o) => <tr key={o.outlet}><th className="text-left font-normal" scope="row">{o.outlet || '—'}</th><td className="text-right tabular-nums">{money(o.base_minor)}</td><td className="text-right tabular-nums">{money(o.service_charge_minor)}</td><td className="text-right tabular-nums">{money(o.tax_minor)}</td></tr>)}</tbody>
                            </table>
                            <p className="mt-2 text-xs text-muted-foreground" data-testid={`set-aside-${m.period}`}>{t('fin.tax.setAside', { nonTaxed: money(m.set_aside.non_taxed_minor), comp: money(m.set_aside.complimentary_minor), discounts: money(m.set_aside.discounts_minor), voided: money(m.set_aside.voided_minor), cancelled: money(m.set_aside.cancelled_minor), reversed: money(m.set_aside.reversed_minor) })}</p>
                        </details>
                        <div className="flex flex-wrap gap-2">
                            <Button asChild size="sm" variant="outline"><a href={`/finance/tax/${m.period}/recap`}>{t('fin.tax.recap')}</a></Button>
                            {m.may.report ? <Button onClick={() => { action.clear(); setReport({ period: m.period, reference: '' }); }} size="sm" type="button">{t('fin.tax.markReported')}</Button> : null}
                            {m.may.deposit ? <Button onClick={() => { action.clear(); setDeposit({ month: m, amount: '', on: overview.today, reference: '' }); }} size="sm" type="button">{t('fin.tax.markDeposited')}</Button> : null}
                        </div>
                    </article>
                ))}
            </section>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setReport(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void doReport()} type="button">{t('fin.tax.markReported')}</Button></>}
                onClose={() => setReport(null)}
                open={report !== null}
                title={t('fin.tax.markReported')}
            >
                {report !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <p className="text-sm text-muted-foreground">{t('fin.tax.reportHint', { period: report.period })}</p>
                        <FormField error={action.fieldError('reference')} field="reference" label={t('fin.tax.reportReference')}><Input maxLength={80} onChange={(e) => setReport({ ...report, reference: e.target.value })} value={report.reference} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setDeposit(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={deposit === null || parseMajorToMinor(deposit.amount, overview.currency) === null || deposit.reference.trim() === '' || deposit.on === ''} loading={action.busy} onClick={() => void doDeposit()} type="button">{t('fin.tax.markDeposited')}</Button></>}
                onClose={() => setDeposit(null)}
                open={deposit !== null}
                title={t('fin.tax.markDeposited')}
            >
                {deposit !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('amount_minor')} field="amount_minor" hint={t('fin.tax.amountHint', { tax: money(deposit.month.filing?.tax_minor ?? 0) })} label={t('fin.tax.amount')}><Input inputMode="decimal" onChange={(e) => setDeposit({ ...deposit, amount: e.target.value })} value={deposit.amount} /></FormField>
                        <FormField error={action.fieldError('deposited_on')} field="deposited_on" label={t('fin.tax.on')}><DatePicker max={overview.today} onChange={(e) => setDeposit({ ...deposit, on: e.target.value })} value={deposit.on} /></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('reference')} field="reference" label={t('fin.tax.depositReference')}><Input maxLength={80} onChange={(e) => setDeposit({ ...deposit, reference: e.target.value })} value={deposit.reference} /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setDay(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={day === null || day === ''} loading={action.busy} onClick={() => void saveDay()} type="button">{t('hr.pay.saveParameters')}</Button></>}
                onClose={() => setDay(null)}
                open={day !== null}
                title={t('fin.tax.setDay')}
            >
                {day !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <FormField error={action.fieldError('report_day')} field="report_day" hint={t('fin.tax.dayHint')} label={t('fin.tax.day')}><Input inputMode="numeric" onChange={(e) => setDay(e.target.value.replace(/\D/g, ''))} value={day} /></FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
