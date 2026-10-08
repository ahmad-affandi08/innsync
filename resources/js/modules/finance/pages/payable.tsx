import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { PAYMENT_METHODS, PAYMENT_TONE, PayableStatus, type PayableRow } from '@/modules/finance/lib/finance';
import { minorToInput } from '@/modules/inventory-purchasing/lib/amounts';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Payment = {
    id: string; number: string; amount_minor: number; method: string; paid_on: string; reference: string | null; status: string; reverses_id: string | null; created_by_name: string | null; proofs: { id: string; name: string | null }[];
};
type Application = { id: string; credit_note_number: string; source_number: string; amount_minor: number; business_date: string };
type Credit = { id: string; supplier_name: string; credit_note_number: string; source_number: string; amount_minor: number; applied_minor: number; available_minor: number; business_date: string; currency: string };
type Payable = PayableRow & {
    issued_on: string; business_date: string; tax_minor: number; source_type: string; source_id: string; order_number: string | null; occurred_at: string; correlation_id: string | null;
    expense_account_id: string | null; expense_account: string | null; accounts: { id: string; code: string; name: string }[]; payments: Payment[]; applications: Application[]; credits: Credit[];
    may: { manage: boolean; pay: boolean };
};
type PayForm = { amount: string; method: string; paid_on: string; reference: string; note: string };
type CreditForm = { credit_id: string; amount: string };

const BLANK_PAY: PayForm = { amount: '', method: 'transfer', paid_on: '', reference: '', note: '' };

/** One payable: where it came from, what was paid against it, and the actions that move it towards paid. */
export default function PayablePage({ payable }: { payable: Payable }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const classify = useServerAction();
    const credit = useServerAction();
    const pay = useServerAction();
    const [account, setAccount] = useState(payable.expense_account_id ?? '');
    const [payForm, setPayForm] = useState<PayForm | null>(null);
    const [creditForm, setCreditForm] = useState<CreditForm | null>(null);
    const [badAmount, setBadAmount] = useState(false);
    const money = (minor: number) => format.money(minor, payable.currency);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(payForm)]);
    const reload = ['payable'];
    const chosenCredit = creditForm === null ? undefined : payable.credits.find((c) => c.id === creditForm.credit_id);
    const creditMax = chosenCredit === undefined ? null : Math.min(chosenCredit.available_minor, payable.available_minor);
    const methodLabel = (m: string) => t(`fin.method.${m}` as MessageKey);

    function openPay() {
        pay.clear();
        setBadAmount(false);
        setPayForm({ ...BLANK_PAY });
    }

    function openCredit() {
        credit.clear();
        setBadAmount(false);
        setCreditForm({ credit_id: payable.credits[0]?.id ?? '', amount: '' });
    }

    async function saveAccount() {
        await classify.run(`/finance/payables/${payable.id}/classify`, { body: { expense_account_id: account === '' ? null : account }, reload });
    }

    async function applyCredit() {
        if (creditForm === null) return;
        const minor = parseMajorToMinor(creditForm.amount, payable.currency);

        setBadAmount(minor === null);
        if (minor === null) return;
        const done = await credit.run(`/finance/payables/${payable.id}/credits`, { body: { credit_id: creditForm.credit_id, amount_minor: minor }, reload });
        if (done !== null) setCreditForm(null);
    }

    async function record() {
        if (payForm === null) return;
        const minor = parseMajorToMinor(payForm.amount, payable.currency);

        setBadAmount(minor === null);
        if (minor === null) return;
        const done = await pay.run<{ payment: { id: string } }>('/finance/payments', {
            body: { payable_id: payable.id, amount_minor: minor, method: payForm.method, paid_on: payForm.paid_on || null, reference: payForm.reference.trim() || null, note: payForm.note.trim() || null },
            idempotencyKey: intent,
        });
        if (done !== null) router.visit(`/finance/payments/${done.payment.id}`);
    }

    const facts: [string, string][] = [
        [t('fin.col.supplier'), `${payable.supplier_code} · ${payable.supplier_name}`],
        [t('fin.pbl.source'), `${t('fin.pbl.sourceSupplierInvoice')} ${payable.document_number}`],
        [t('fin.col.ourNumber'), payable.source_number],
        [t('fin.pbl.order'), payable.order_number ?? '—'],
        [t('fin.pbl.issued'), format.date(payable.issued_on)],
        [t('fin.pbl.due'), format.date(payable.due_date)],
    ];
    const amounts: [string, number][] = [
        [t('fin.pbl.amount'), payable.amount_minor],
        [t('fin.pbl.tax'), payable.tax_minor],
        [t('fin.pbl.paid'), payable.paid_minor],
        [t('fin.pbl.credit'), payable.credit_minor],
        [t('fin.pbl.pending'), payable.pending_minor],
        [t('fin.pbl.balance'), payable.balance_minor],
    ];
    const showPay = payable.may.pay && payable.available_minor > 0;
    const accountChanged = account !== (payable.expense_account_id ?? '');

    return (
        <FinanceShell
            actions={<div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild variant="outline"><Link href="/finance/payables">{t('fin.pbl.back')}</Link></Button>
                {payable.may.manage && payable.credits.length > 0 && payable.available_minor > 0 ? <Button onClick={openCredit} type="button" variant="outline">{t('fin.pbl.applyCredit')}</Button> : null}
                {showPay ? <Button onClick={openPay} type="button">{t('fin.pbl.record')}</Button> : null}
            </div>}
            description={t('fin.pbl.description')}
            title={`${payable.supplier_name} · ${payable.document_number}`}
            wide
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" data-testid="payable-head">
                <PayableStatus row={payable} />
                {payable.pending_minor > 0 ? <span className="text-muted-foreground">{t('fin.pbl.pending')}: {money(payable.pending_minor)}</span> : null}
            </div>

            <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-3" data-testid="payable-facts">
                {facts.map(([name, value]) => (
                    <div key={name}>
                        <dt className="text-xs text-muted-foreground">{name}</dt>
                        <dd className="break-words">{value}</dd>
                    </div>
                ))}
            </dl>

            <section aria-labelledby="fin-amounts-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-amounts-h">{t('fin.pbl.amounts')}</h2>
                <dl className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6" data-testid="payable-amounts">
                    {amounts.map(([name, minor]) => (
                        <div className="border border-border bg-surface p-3" key={name}>
                            <dt className="text-xs text-muted-foreground">{name}</dt>
                            <dd className="mt-1 text-base font-semibold tabular-nums">{money(minor)}</dd>
                        </div>
                    ))}
                </dl>
                <p className="text-sm text-muted-foreground">{t('fin.pbl.available')}: <span className="font-medium text-foreground">{money(payable.available_minor)}</span></p>
            </section>

            <section aria-labelledby="fin-account-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-account-h">{t('fin.pbl.account')}</h2>
                {payable.may.manage ? (
                    <div className="flex max-w-xl flex-col gap-3 print:hidden">
                        {classify.error !== null ? <ErrorState {...errorCopy} error={classify.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={classify.fieldError('expense_account_id')} field="expense_account_id" hint={payable.accounts.length === 0 ? t('fin.pbl.noAccounts') : t('fin.pbl.accountHint')} label={t('fin.pbl.accountField')}>
                            <Select onChange={(e) => setAccount(e.target.value)} value={account}>
                                <option value="">{t('fin.pbl.accountNone')}</option>
                                {payable.accounts.map((a) => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}
                            </Select>
                        </FormField>
                        <div><Button disabled={!accountChanged} loading={classify.busy} onClick={() => void saveAccount()} type="button" variant="outline">{t('fin.pbl.accountSave')}</Button></div>
                    </div>
                ) : <p className="text-sm" data-testid="payable-account">{payable.expense_account ?? t('fin.pbl.accountNone')}</p>}
            </section>

            <section aria-labelledby="fin-payments-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-payments-h">{t('fin.pbl.payments')}</h2>
                {payable.payments.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.pbl.noPayments')}</p> : (
                    <div className="overflow-x-auto border border-border bg-surface">
                        <Table data-testid="payable-payments">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('fin.col.number')}</TableHead>
                                    <TableHead className="text-right">{t('fin.col.amount')}</TableHead>
                                    <TableHead>{t('fin.col.method')}</TableHead>
                                    <TableHead>{t('fin.col.paidOn')}</TableHead>
                                    <TableHead>{t('fin.col.reference')}</TableHead>
                                    <TableHead>{t('inv.col.status')}</TableHead>
                                    <TableHead className="text-right">{t('fin.col.proofs')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payable.payments.map((p) => {
                                    const reversal = p.status === 'reversal';
                                    const takenBack = reversal ? payable.payments.find((o) => o.id === p.reverses_id) : undefined;
                                    const reversedBy = payable.payments.find((o) => o.reverses_id === p.id);

                                    return (
                                        <TableRow key={p.id}>
                                            <TableCell>
                                                <Link className="font-medium underline" href={`/finance/payments/${p.id}`}>{p.number}</Link>
                                                {takenBack !== undefined ? <span className="block text-xs text-muted-foreground">{t('fin.pmt.takesBack', { number: takenBack.number })}</span> : null}
                                                {reversedBy !== undefined ? <span className="block text-xs text-muted-foreground">{t('fin.pmt.reversedBy', { number: reversedBy.number })}</span> : null}
                                            </TableCell>
                                            <TableCell className="text-right">{money(reversal ? -p.amount_minor : p.amount_minor)}</TableCell>
                                            <TableCell>{methodLabel(p.method)}</TableCell>
                                            <TableCell>{format.date(p.paid_on)}</TableCell>
                                            <TableCell>{p.reference ?? '—'}</TableCell>
                                            <TableCell><StatusBadge label={t(`fin.pmt.status.${p.status}` as MessageKey)} tone={PAYMENT_TONE[p.status] ?? 'neutral'} /></TableCell>
                                            <TableCell className="text-right">{p.proofs.length}</TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </section>

            <section aria-labelledby="fin-applied-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-applied-h">{t('fin.pbl.applications')}</h2>
                {payable.applications.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.pbl.noApplications')}</p> : (
                    <div className="overflow-x-auto border border-border bg-surface">
                        <Table data-testid="payable-applications">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('fin.cred.note')}</TableHead>
                                    <TableHead>{t('fin.cred.source')}</TableHead>
                                    <TableHead>{t('fin.cred.date')}</TableHead>
                                    <TableHead className="text-right">{t('fin.col.amount')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payable.applications.map((a) => (
                                    <TableRow key={a.id}>
                                        <TableCell>{a.credit_note_number}</TableCell>
                                        <TableCell>{a.source_number}</TableCell>
                                        <TableCell>{format.date(a.business_date)}</TableCell>
                                        <TableCell className="text-right">{money(a.amount_minor)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </section>

            {payable.may.manage ? (
                <section aria-labelledby="fin-credits-h" className="flex flex-col gap-3 print:hidden">
                    <h2 className="text-lg font-semibold" id="fin-credits-h">{t('fin.pbl.availableCredits')}</h2>
                    {payable.credits.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.pbl.noCredits')}</p> : (
                        <div className="overflow-x-auto border border-border bg-surface">
                            <Table data-testid="payable-credits">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('fin.cred.note')}</TableHead>
                                        <TableHead>{t('fin.cred.source')}</TableHead>
                                        <TableHead>{t('fin.cred.date')}</TableHead>
                                        <TableHead className="text-right">{t('fin.cred.available')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {payable.credits.map((c) => (
                                        <TableRow key={c.id}>
                                            <TableCell>{c.credit_note_number}</TableCell>
                                            <TableCell>{c.source_number}</TableCell>
                                            <TableCell>{format.date(c.business_date)}</TableCell>
                                            <TableCell className="text-right">{money(c.available_minor)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </section>
            ) : null}

            <p className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground" data-testid="payable-posting">
                <span className="font-medium">{t('fin.pbl.postingFacts')}</span>
                <span>{t('fin.pbl.businessDate')}: {format.date(payable.business_date)}</span>
                <span>{t('fin.pbl.eventTime')}: {format.instant(payable.occurred_at)}</span>
                <span>{t('fin.pbl.correlation')}: {payable.correlation_id ?? '—'}</span>
            </p>

            <Dialog
                footer={<>
                    <Button disabled={credit.busy} onClick={() => setCreditForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={credit.busy} onClick={() => void applyCredit()} type="button">{t('fin.pbl.applyCredit')}</Button>
                </>}
                onClose={() => setCreditForm(null)}
                open={creditForm !== null}
                title={t('fin.pbl.applyTitle')}
            >
                {creditForm !== null && (
                    <div className="flex flex-col gap-3">
                        {credit.error !== null ? <ErrorState {...errorCopy} error={credit.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={credit.fieldError('credit_id')} field="credit_id" label={t('fin.pbl.creditField')}>
                            <Select onChange={(e) => setCreditForm({ ...creditForm, credit_id: e.target.value })} value={creditForm.credit_id}>
                                <option value="">{t('fin.pbl.creditChoose')}</option>
                                {payable.credits.map((c) => <option key={c.id} value={c.id}>{t('fin.pbl.creditOption', { note: c.credit_note_number, source: c.source_number, amount: money(c.available_minor) })}</option>)}
                            </Select>
                        </FormField>
                        <FormField
                            error={badAmount ? t('fo.folio.invalidAmount') : credit.fieldError('amount_minor')}
                            field="amount_minor"
                            hint={creditMax === null ? undefined : t('fin.pbl.creditMax', { amount: money(creditMax) })}
                            label={t('fin.pbl.creditAmount', { currency: payable.currency })}
                        >
                            <MoneyInput onChange={(e) => { setBadAmount(false); setCreditForm({ ...creditForm, amount: e.target.value }); }} value={creditForm.amount} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={pay.busy} onClick={() => setPayForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={pay.busy} onClick={() => void record()} type="button">{t('fin.pbl.record')}</Button>
                </>}
                onClose={() => setPayForm(null)}
                open={payForm !== null}
                title={t('fin.pbl.recordTitle', { supplier: payable.supplier_name })}
            >
                {payForm !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.pbl.recordHint')}</p>
                        {pay.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={pay.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField
                            error={badAmount ? t('fo.folio.invalidAmount') : pay.fieldError('amount_minor')}
                            field="amount_minor"
                            hint={<span className="flex flex-wrap items-center gap-2">
                                {t('fin.pbl.payMax', { amount: money(payable.available_minor) })}
                                <Button className="h-6 px-2 text-xs" onClick={() => { setBadAmount(false); setPayForm({ ...payForm, amount: minorToInput(payable.available_minor, payable.currency) }); }} type="button" variant="outline">{t('fin.pbl.payAll')}</Button>
                            </span>}
                            label={t('fin.pbl.payAmount', { currency: payable.currency })}
                        >
                            <MoneyInput onChange={(e) => { setBadAmount(false); setPayForm({ ...payForm, amount: e.target.value }); }} value={payForm.amount} />
                        </FormField>
                        <FormField error={pay.fieldError('method')} field="method" label={t('fin.pbl.method')}>
                            <Select onChange={(e) => setPayForm({ ...payForm, method: e.target.value })} searchable={false} value={payForm.method}>
                                {PAYMENT_METHODS.map((m) => <option key={m} value={m}>{methodLabel(m)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={pay.fieldError('paid_on')} field="paid_on" hint={t('fin.pbl.paidOnHint')} label={t('fin.pbl.paidOn')}>
                            <DatePicker onChange={(e) => setPayForm({ ...payForm, paid_on: e.target.value })} value={payForm.paid_on} />
                        </FormField>
                        <FormField error={pay.fieldError('reference')} field="reference" hint={payForm.method === 'transfer' || payForm.method === 'giro' ? t('fin.pbl.referenceNeeded') : undefined} label={t('fin.pbl.reference')} required={payForm.method === 'transfer' || payForm.method === 'giro'}>
                            <Input maxLength={60} onChange={(e) => setPayForm({ ...payForm, reference: e.target.value })} value={payForm.reference} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={pay.fieldError('note')} field="note" label={t('fin.pbl.note')}>
                                <Input maxLength={200} onChange={(e) => setPayForm({ ...payForm, note: e.target.value })} value={payForm.note} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
