import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { ApprovalProgress } from '@/modules/inventory-purchasing/components/approval-progress';
import type { ApprovalSummary } from '@/modules/inventory-purchasing/lib/purchasing';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { PAYMENT_TONE } from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Proof = { id: string; name: string | null };
type Payment = {
    id: string; number: string; status: string; supplier_name: string; supplier_code: string; payable_number: string; document_number: string; amount_minor: number; currency: string; method: string;
    paid_on: string; reference: string | null; created_by_name: string | null; mine: boolean; note: string | null; decision_note: string | null; business_date: string; payable_id: string;
    max_proofs: number; approval: ApprovalSummary | null; proofs: Proof[]; may_proof: boolean; may_release: boolean; may_cancel: boolean;
    reversal_number: string | null; reverses_number: string | null; may_reverse: boolean; reason: string | null;
};

const isImage = (name: string | null) => /\.(png|jpe?g)$/i.test(name ?? '');

/** One payment to a supplier: its approval, the decision, and the proofs of payment. */
export default function PaymentPage({ payment }: { payment: Payment }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [cancelling, setCancelling] = useState(false);
    const [reversing, setReversing] = useState(false);
    const [reason, setReason] = useState('');
    const [pickerKey, setPickerKey] = useState(0);
    const reload = ['payment'];
    const label = (s: string) => t(`fin.pmt.status.${s}` as MessageKey);
    const waiting = payment.status === 'pending_approval';

    async function release() {
        await action.run(`/finance/payments/${payment.id}/release`, { reload });
    }

    function openCancel() {
        action.clear();
        setReason('');
        setCancelling(true);
    }

    async function cancel() {
        const done = await action.run(`/finance/payments/${payment.id}/cancel`, { body: { reason: reason.trim() }, reload });
        if (done !== null) { setCancelling(false); setReason(''); }
    }

    function openReverse() {
        action.clear();
        setReason('');
        setReversing(true);
    }

    async function reverse() {
        const done = await action.run<{ payment: { id: string } }>(`/finance/payments/${payment.id}/reverse`, { body: { reason: reason.trim() } });
        if (done !== null) { setReversing(false); router.visit(`/finance/payments/${done.payment.id}`); }
    }

    async function upload(file: File | undefined) {
        if (file === undefined) return;
        const body = new FormData();
        body.set('proof', file);
        await action.run(`/finance/payments/${payment.id}/proofs`, { body, reload });
        setPickerKey((k) => k + 1);
    }

    const isReversal = payment.status === 'reversal';
    const facts: [string, string][] = [
        [t('fin.col.supplier'), `${payment.supplier_code} · ${payment.supplier_name}`],
        [t('fin.col.document'), payment.document_number],
        [t('fin.col.ourNumber'), payment.payable_number],
        [t('fin.col.amount'), format.money(isReversal ? -payment.amount_minor : payment.amount_minor, payment.currency)],
        [t('fin.col.method'), t(`fin.method.${payment.method}` as MessageKey)],
        [t('fin.col.paidOn'), format.date(payment.paid_on)],
        [t('fin.col.reference'), payment.reference ?? '—'],
        [t('fin.col.madeBy'), payment.created_by_name ?? '—'],
        [t('fin.pm.businessDate'), format.date(payment.business_date)],
        [t('fin.pm.note'), payment.note ?? '—'],
    ];

    return (
        <FinanceShell
            actions={<div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild variant="outline"><Link href="/finance/payments">{t('fin.pm.back')}</Link></Button>
                <Button asChild variant="outline"><Link href={`/finance/payables/${payment.payable_id}`}>{t('fin.pm.toPayable')}</Link></Button>
                {payment.may_cancel ? <Button onClick={openCancel} type="button" variant="outline">{t('fin.pm.cancel')}</Button> : null}
                {payment.may_reverse ? <Button onClick={openReverse} type="button" variant="outline">{t('fin.pm.reverse')}</Button> : null}
                {payment.may_release ? <Button loading={action.busy} onClick={() => void release()} type="button">{t('fin.pm.release')}</Button> : null}
                <Button onClick={() => window.print()} type="button" variant="outline">{t('fin.print')}</Button>
            </div>}
            description={t('fin.pm.description')}
            title={`${payment.number} · ${payment.supplier_name}`}
            wide
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" data-testid="payment-head">
                <StatusBadge label={label(payment.status)} tone={PAYMENT_TONE[payment.status] ?? 'neutral'} />
                {payment.may_release ? <span className="text-muted-foreground">{t('fin.pm.releaseHint')}</span> : null}
            </div>

            {action.error !== null && !cancelling && !reversing ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {waiting && payment.mine === false ? <Alert title={t('fin.pm.selfOnly')} tone="info" /> : null}
            {payment.reversal_number !== null ? <Alert title={t('fin.pm.reversedBy', { number: payment.reversal_number })} tone="warning"><p>{t('fin.pm.reversedHint')}</p></Alert> : null}
            {payment.reverses_number !== null ? (
                <Alert title={t('fin.pm.takesBack', { number: payment.reverses_number })} tone="info">
                    <p data-testid="reversal-reason">{t('fin.pm.reversalReason')}: {payment.reason ?? '—'}</p>
                </Alert>
            ) : null}
            {payment.decision_note !== null ? (
                <Alert title={label(payment.status)} tone={payment.status === 'rejected' ? 'danger' : 'info'}>
                    <p data-testid="decision-note">{t('fin.pm.decisionNote')}: {payment.decision_note}</p>
                </Alert>
            ) : null}

            <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-5" data-testid="payment-facts">
                {facts.map(([name, value]) => (
                    <div key={name}>
                        <dt className="text-xs text-muted-foreground">{name}</dt>
                        <dd className="break-words">{value}</dd>
                    </div>
                ))}
            </dl>

            {isReversal ? null : (
                <section aria-labelledby="fin-approval-h" className="flex flex-col gap-3">
                    <h2 className="text-lg font-semibold" id="fin-approval-h">{t('fin.pm.approval')}</h2>
                    {payment.approval === null ? <p className="text-sm text-muted-foreground">{t('fin.pm.noApproval')}</p> : <ApprovalProgress approval={payment.approval} />}
                </section>
            )}

            <section aria-labelledby="fin-proofs-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-proofs-h">{t('fin.pm.proofs')}</h2>
                {payment.proofs.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.pm.noProofs')}</p> : (
                    <ul className="flex flex-wrap gap-3 text-sm" data-testid="payment-proofs">
                        {payment.proofs.map((p) => {
                            const href = `/finance/payments/${payment.id}/proofs/${p.id}`;

                            return (
                                <li className="flex flex-col gap-1" key={p.id}>
                                    {isImage(p.name) ? <a href={href} rel="noreferrer" target="_blank"><img alt={p.name ?? t('fin.pm.proof')} className="h-24 w-auto border border-border" src={href} /></a> : null}
                                    <a className="underline" href={href} rel="noreferrer" target="_blank">{p.name ?? t('fin.pm.proof')}</a>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {payment.may_proof && payment.proofs.length < payment.max_proofs ? (
                    <div className="max-w-sm print:hidden">
                        <FormField error={action.fieldError('proof')} field="proof" hint={t('fin.pm.proofLimit', { max: payment.max_proofs })} label={t('fin.pm.addProof')}>
                            <Input accept="application/pdf,image/jpeg,image/png" key={pickerKey} onChange={(e) => void upload(e.target.files?.[0])} type="file" />
                        </FormField>
                    </div>
                ) : null}
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setCancelling(false)} type="button" variant="outline">{t('fin.pm.keep')}</Button>
                    <Button loading={action.busy} onClick={() => void cancel()} type="button">{t('fin.pm.cancel')}</Button>
                </>}
                onClose={() => setCancelling(false)}
                open={cancelling}
                title={t('fin.pm.cancelTitle', { number: payment.number })}
            >
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">{t('fin.pm.cancelHint')}</p>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={action.fieldError('reason')} field="reason" label={t('fin.pm.cancelReason')}>
                        <Input maxLength={200} onChange={(e) => setReason(e.target.value)} value={reason} />
                    </FormField>
                </div>
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setReversing(false)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void reverse()} type="button">{t('fin.pm.reverse')}</Button>
                </>}
                onClose={() => setReversing(false)}
                open={reversing}
                title={t('fin.pm.reverseTitle', { number: payment.number })}
            >
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">{t('fin.pm.reverseHint')}</p>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={action.fieldError('reason')} field="reason" label={t('fin.pm.reverseReason')}>
                        <Input maxLength={200} onChange={(e) => setReason(e.target.value)} value={reason} />
                    </FormField>
                </div>
            </Dialog>
        </FinanceShell>
    );
}
