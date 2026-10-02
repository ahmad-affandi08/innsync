import { Head, Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Line = { date: string; description: string; reversal: boolean; base_minor: number; service_charge_minor: number; tax_minor: number; total_minor: number };
type Outlet = { outlet: 'rooms' | 'laundry' | 'fees' | 'other'; lines: Line[]; total_minor: number };
type Payment = { date: string; type: string; method: string; reference: string | null; purpose: string; amount_minor: number };
type Bill = {
    hotel: string | null; currency: string; printed_at: string;
    folio: { number: string; label: string; window: number };
    reservation: { number: string; guest_name: string; arrival: string; departure: string; room: string | null };
    outlets: Outlet[]; payments: Payment[];
    totals: { base: number; service_charge: number; tax: number; total: number; paid: number; balance_minor: number };
};

/** The guest's bill as a printed document (FR-FO-021): outlet by outlet and date by date, then payments and what is owed. */
export default function BillPage({ bill, folio_id: folioId }: { bill: Bill; folio_id: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const money = (v: number) => format.money(v, bill.currency);
    const method = (m: string) => (['cash', 'qris', 'card', 'bank_transfer', 'online'].includes(m) ? t(`fo.folio.method.${m}` as 'fo.folio.method.cash') : m);

    return (
        <>
            <Head title={t('fo.bill.title')} />
            <main className="min-h-screen bg-surface-muted px-4 py-10 print:bg-white print:p-0">
                <article className="mx-auto flex max-w-4xl flex-col gap-5 border border-border bg-surface p-6 text-sm sm:p-8 print:max-w-none print:border-0 print:p-0 ">
                    <div className="flex flex-wrap justify-end gap-2 print:hidden">
                        <LanguageSwitcher />
                        <Button asChild size="sm" variant="outline"><Link href={`/front-office/folios/${folioId}`}>{t('common.action.back')}</Link></Button>
                        <Button onClick={() => window.print()} size="sm" type="button">{t('fo.bill.print')}</Button>
                    </div>
                    <header className="flex flex-col gap-1 border-b border-border pb-3">
                        <p className="text-lg font-semibold">{bill.hotel ?? ''}</p>
                        <h1 className="text-xl font-semibold">{t('fo.bill.title')}</h1>
                    </header>
                    <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-2" data-testid="bill-header">
                        <div className="flex gap-2"><dt className="text-muted-foreground">{t('fo.bill.guest')}</dt><dd className="font-medium">{bill.reservation.guest_name}</dd></div>
                        <div className="flex gap-2"><dt className="text-muted-foreground">{t('fo.bill.reservation')}</dt><dd>{bill.reservation.number}</dd></div>
                        <div className="flex gap-2"><dt className="text-muted-foreground">{t('fo.bill.room')}</dt><dd>{bill.reservation.room ?? '—'}</dd></div>
                        <div className="flex gap-2"><dt className="text-muted-foreground">{t('fo.bill.folio')}</dt><dd>{bill.folio.number} · {bill.folio.label}</dd></div>
                        <div className="flex gap-2"><dt className="text-muted-foreground">{t('fo.bill.stay')}</dt><dd>{format.date(bill.reservation.arrival)} – {format.date(bill.reservation.departure)}</dd></div>
                    </dl>

                    {bill.outlets.length === 0 ? <EmptyState title={t('fo.bill.empty')} /> : bill.outlets.map((o) => (
                        <section aria-label={t(`fo.bill.outlet.${o.outlet}` as 'fo.bill.outlet.rooms')} data-testid={`outlet-${o.outlet}`} key={o.outlet}>
                            <h2 className="mb-1 font-semibold">{t(`fo.bill.outlet.${o.outlet}`as 'fo.bill.outlet.rooms')}</h2>
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead scope="col">{t('fo.bill.date')}</TableHead>
                                        <TableHead scope="col">{t('fo.bill.description')}</TableHead>
                                        <TableHead className="text-right" scope="col">{t('fo.bill.base')}</TableHead>
                                        <TableHead className="text-right" scope="col">{t('fo.bill.serviceCharge')}</TableHead>
                                        <TableHead className="text-right" scope="col">{t('fo.bill.tax')}</TableHead>
                                        <TableHead className="text-right" scope="col">{t('fo.bill.total')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>{o.lines.map((l, i) => (
                                    <TableRow key={i}>
                                        <TableCell className="py-1">{format.date(l.date)}</TableCell>
                                        <TableCell className="py-1">{l.description}{l.reversal ? <span className="ml-1 text-xs text-muted-foreground">({t('fo.bill.reversal')})</span> : null}</TableCell>
                                        <TableCell className="py-1 text-right tabular-nums">{money(l.base_minor)}</TableCell>
                                        <TableCell className="py-1 text-right tabular-nums">{money(l.service_charge_minor)}</TableCell>
                                        <TableCell className="py-1 text-right tabular-nums">{money(l.tax_minor)}</TableCell>
                                        <TableCell className="py-1 text-right tabular-nums">{money(l.total_minor)}</TableCell>
                                    </TableRow>
                                ))}</TableBody>
                                <TableFooter>
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell className="py-1" colSpan={5}>{t('fo.bill.outlet.' + o.outlet as 'fo.bill.outlet.rooms')}</TableCell>
                                        <TableCell className="py-1 text-right tabular-nums">{money(o.total_minor)}</TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </section>
                    ))}

                    {bill.payments.length > 0 && (
                        <section aria-label={t('fo.bill.payments')} data-testid="bill-payments">
                            <h2 className="mb-1 font-semibold">{t('fo.bill.payments')}</h2>
                            <Table>
                                <TableBody>{bill.payments.map((p, i) => (
                                    <TableRow key={i}>
                                        <TableCell className="py-1">{format.date(p.date)}</TableCell>
                                        <TableCell className="py-1">{method(p.method)}{p.reference ? ` · ${p.reference}` : ''}</TableCell>
                                        <TableCell className="py-1 text-right tabular-nums">{money(p.amount_minor)}</TableCell>
                                    </TableRow>
                                ))}</TableBody>
                            </Table>
                        </section>
                    )}

                    <dl className="ml-auto flex w-full max-w-sm flex-col gap-1 border-t border-border pt-2" data-testid="bill-totals">
                        <div className="flex justify-between"><dt>{t('fo.bill.charges')}</dt><dd>{money(bill.totals.total)}</dd></div>
                        <div className="flex justify-between"><dt>{t('fo.bill.paid')}</dt><dd>{money(bill.totals.paid)}</dd></div>
                        <div className="flex justify-between text-base font-semibold"><dt>{bill.totals.balance_minor < 0 ? t('fo.bill.credit') : t('fo.bill.balance')}</dt><dd data-testid="bill-balance">{money(Math.abs(bill.totals.balance_minor))}</dd></div>
                    </dl>
                    <footer className="mt-6 flex items-end justify-between text-xs text-muted-foreground">
                        <span>{t('fo.bill.printed', { time: format.instant(bill.printed_at) })}</span>
                        <span className="w-56 border-t border-foreground pt-1 text-center">{t('fo.bill.signature')}</span>
                    </footer>
                </article>
            </main>
        </>
    );
}
