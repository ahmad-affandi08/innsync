import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog, Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { LateChargeButton } from '@/modules/front-office/components/late-charge';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { ApiError } from '@/shared/lib/api-error';
import { parseMajorToMinor } from '@/shared/money/money';

type Posting = {
    id: string; seq: number; type: 'charge' | 'payment' | 'refund' | 'reversal'; code: string; description: string; base_minor: number; service_charge_minor: number; tax_minor: number;
    total_minor: number; business_date: string; payment_method: string | null; payment_reference: string | null; reason: string | null; is_reversed: boolean;
};
type LateFolio = { id: string; number: string; balance_minor: number; closed: boolean };
type Folio = { origin_folio_id: string | null; origin_number: string | null; late_folios: LateFolio[]; id: string; number: string; reservation_id: string; window: number; label: string; currency: string; status: string; balance_minor: number; charges_minor: number; payments_minor: number; lock_version: number; postings: Posting[] };
type Approval = { id: string; subject_type: string; subject_ref: string; status: string; consumed: boolean; amount_minor: number | null; payload: Record<string, unknown> };
type Reservation = { id: string; number: string; guest_name: string };
type Targets = { same: { folio_id: string; number: string; window: number; label: string }[]; others: { folio_id: string; number: string; label: string; room: string; guest: string }[] };

const METHODS = ['cash', 'qris', 'card', 'bank_transfer', 'online'] as const;
const statusTone: Record<string, StatusTone> = { open: 'info', partially_settled: 'warning', settled: 'success', closed: 'neutral' };

export default function FolioPage({ approvals, folio, may_late_charge: mayLateCharge, reservation }: { approvals: Approval[]; folio: Folio; may_late_charge: boolean; reservation: Reservation }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['folio', 'approvals'];
    const [charge, setCharge] = useState<{ code: string; description: string; amount: string; nett: boolean } | null>(null);
    const [payment, setPayment] = useState<{ method: string; amount: string; reference: string; purpose: string } | null>(null);
    const [reverse, setReverse] = useState<{ posting: Posting; reason: string } | null>(null);
    const [refund, setRefund] = useState<{ method: string; amount: string; reference: string; reason: string } | null>(null);
    const [closing, setClosing] = useState(false);
    const [move, setMove] = useState<{ posting: Posting; target: string; reason: string; targets: Targets | null } | null>(null);
    const [amountError, setAmountError] = useState(false);
    const [needsApproval, setNeedsApproval] = useState(false);
    const [requested, setRequested] = useState(false);
    // One key per intent: a retry of the same form is the same posting (NFR-18, BR-005).
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const open = folio.status !== 'closed';
    const money = (v: number) => format.money(v, folio.currency);
    const readyApproval = (ref: string, type: string) => approvals.find((a) => a.subject_ref === ref && a.subject_type === type && a.status === 'approved' && !a.consumed);
    const pendingApproval = (ref: string, type: string) => approvals.find((a) => a.subject_ref === ref && a.subject_type === type && a.status === 'pending');

    function closeAll() {
        action.clear();
        setCharge(null);
        setPayment(null);
        setReverse(null);
        setRefund(null);
        setMove(null);
        setClosing(false);
        setAmountError(false);
        setNeedsApproval(false);
        setRequested(false);
        setIntent(newIdempotencyKey());
    }

    const onFailure = (failure: { conflict: { reason: string } | null }) => {
        if (failure.conflict?.reason === 'approval_required') setNeedsApproval(true);
    };

    async function saveCharge() {
        if (charge === null) return;
        const minor = parseMajorToMinor(charge.amount, folio.currency);
        setAmountError(minor === null);
        if (minor === null) return;
        const done = await action.run(`/front-office/folios/${folio.id}/charges`, { idempotencyKey: intent, body: { code: charge.code, description: charge.description, amount_minor: minor, prices_include_charges: charge.nett }, reload });
        if (done !== null) closeAll();
    }

    async function savePayment() {
        if (payment === null) return;
        const minor = parseMajorToMinor(payment.amount, folio.currency);
        setAmountError(minor === null);
        if (minor === null) return;
        const done = await action.run(`/front-office/folios/${folio.id}/payments`, { idempotencyKey: intent, body: { payment_method: payment.method, amount_minor: minor, reference: payment.reference || null, purpose: payment.purpose }, reload });
        if (done !== null) closeAll();
    }

    async function doReverse() {
        if (reverse === null) return;
        const ready = readyApproval(reverse.posting.id, 'front-office.folio.reversal');
        const done = await action.run(`/front-office/postings/${reverse.posting.id}/reverse`, { body: { reason: reverse.reason, approval_id: ready?.id ?? null }, reload, onFailure });
        if (done !== null) closeAll();
    }

    async function requestReversal() {
        if (reverse === null) return;
        const done = await action.run(`/front-office/postings/${reverse.posting.id}/reversal-request`, { idempotencyKey: intent, body: { reason: reverse.reason }, reload });
        if (done !== null) {
            setRequested(true);
            setNeedsApproval(false);
        }
    }

    async function doRefund() {
        if (refund === null) return;
        const minor = parseMajorToMinor(refund.amount, folio.currency);
        setAmountError(minor === null);
        if (minor === null) return;
        const ready = approvals.find((a) => a.subject_type === 'front-office.folio.refund' && a.subject_ref === folio.id && a.status === 'approved' && !a.consumed && a.amount_minor === minor);
        const done = await action.run(`/front-office/folios/${folio.id}/refund`, { body: { payment_method: refund.method, amount_minor: minor, reference: refund.reference || null, reason: refund.reason, approval_id: ready?.id ?? null }, reload, onFailure });
        if (done !== null) closeAll();
    }

    async function requestRefund() {
        if (refund === null) return;
        const minor = parseMajorToMinor(refund.amount, folio.currency);
        setAmountError(minor === null);
        if (minor === null) return;
        const done = await action.run(`/front-office/folios/${folio.id}/refund-request`, { idempotencyKey: intent, body: { payment_method: refund.method, amount_minor: minor, reason: refund.reason }, reload });
        if (done !== null) {
            setRequested(true);
            setNeedsApproval(false);
        }
    }

    async function doClose() {
        const done = await action.run(`/front-office/folios/${folio.id}/close`, { body: { lock_version: folio.lock_version }, reload });
        if (done !== null) closeAll();
    }

    const conflictReason = action.error instanceof ApiError ? action.error.failure.conflict?.reason : null;
    async function startMove(posting: Posting) {
        action.clear();
        setMove({ posting, target: '', reason: '', targets: null });
        const done = await action.run<{ targets: Targets }>(`/front-office/folios/${folio.id}/transfer-targets`, { method: 'GET' });
        setMove((m) => (m === null ? m : { ...m, targets: done?.targets ?? { same: [], others: [] } }));
    }

    async function doMove() {
        if (move === null) return;
        const done = await action.run(`/front-office/postings/${move.posting.id}/transfer`, { body: { target_folio_id: move.target, reason: move.reason }, reload });
        if (done !== null) closeAll();
    }

    const error = action.error !== null && conflictReason !== 'approval_required' ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const balanceLabel = folio.balance_minor > 0 ? t('fo.folio.balanceOwed') : folio.balance_minor < 0 ? t('fo.folio.balanceCredit') : t('fo.folio.balanceZero');
    const footer = (onSave: () => void) => (<>
        <Button disabled={action.busy} onClick={closeAll} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
        <Button loading={action.busy} onClick={onSave} type="button">{t('property.action.save')}</Button>
    </>);

    return (
        <FrontOfficeShell description={t('fo.folio.description', { label: folio.label, reservation: reservation.number, guest: reservation.guest_name })} title={t('fo.folio.title', { number: folio.number })} wide>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button asChild size="sm" variant="outline"><Link href={`/front-office/reservations/${reservation.id}`}>{t('fo.folio.back')}</Link></Button>
                <StatusBadge label={t(`fo.folio.status.${folio.status}` as 'fo.folio.status.open')} tone={statusTone[folio.status] ?? 'neutral'} />
            </div>
            {charge === null && payment === null && reverse === null && refund === null && !closing ? error : null}

            <dl className="grid gap-4 sm:grid-cols-3">
                <div><dt className="text-xs text-muted-foreground">{balanceLabel}</dt><dd className="text-xl font-semibold" data-testid="balance">{money(Math.abs(folio.balance_minor))}</dd></div>
                <div><dt className="text-xs text-muted-foreground">{t('fo.folio.charges')}</dt><dd className="text-lg">{money(folio.charges_minor)}</dd></div>
                <div><dt className="text-xs text-muted-foreground">{t('fo.folio.payments')}</dt><dd className="text-lg">{money(folio.payments_minor)}</dd></div>
            </dl>

            <div className="flex flex-wrap items-center gap-2">
                <Button asChild size="sm" variant="outline"><a href={`/front-office/folios/${folio.id}/bill`}>{t('fo.bill.open')}</a></Button>
                {!open && mayLateCharge && folio.origin_folio_id === null ? <LateChargeButton currency={folio.currency} folioId={folio.id} /> : null}
            </div>
            {folio.origin_folio_id !== null ? (
                <p className="text-sm" data-testid="late-origin">{t('fo.late.origin', { number: folio.origin_number ?? '' })} · <Link className="underline-offset-2 hover:underline" href={`/front-office/folios/${folio.origin_folio_id}`}>{t('fo.late.originLink', { number: folio.origin_number ?? '' })}</Link></p>
            ) : null}
            {folio.late_folios.length > 0 ? (
                <section aria-labelledby="late-h" className="flex flex-col gap-1 text-sm">
                    <h2 className="text-lg font-semibold" id="late-h">{t('fo.late.list')}</h2>
                    <ul className="divide-y divide-border border-y border-border" data-testid="late-folios">{folio.late_folios.map((l) => (
                        <li className="flex flex-wrap justify-between gap-2 py-1" key={l.id}><Link className="font-medium underline-offset-2 hover:underline" href={`/front-office/folios/${l.id}`}>{t('fo.late.row', { number: l.number, status: l.closed ? t('fo.late.closed') : t('fo.late.open') })}</Link><span>{money(l.balance_minor)}</span></li>
                    ))}</ul>
                </section>
            ) : null}

            {open && (
                <div className="flex flex-wrap gap-2">
                    <Button onClick={() => { action.clear(); setCharge({ code: '', description: '', amount: '', nett: false }); }} size="sm" type="button">{t('fo.folio.addCharge')}</Button>
                    <Button onClick={() => { action.clear(); setPayment({ method: 'cash', amount: '', reference: '', purpose: 'settlement' }); }} size="sm" type="button">{t('fo.folio.addPayment')}</Button>
                    <Button onClick={() => { action.clear(); setRefund({ method: 'bank_transfer', amount: '', reference: '', reason: '' }); }} size="sm" type="button" variant="outline">{t('fo.folio.refund')}</Button>
                    <Button disabled={folio.balance_minor !== 0} onClick={() => { action.clear(); setClosing(true); }} size="sm" type="button" variant="outline">{t('fo.folio.close')}</Button>
                </div>
            )}

            <section aria-labelledby="posts-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="posts-h">{t('fo.folio.postings')}</h2>
                {folio.postings.length === 0 ? <EmptyState title={t('fo.folio.empty')} /> : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('fo.folio.col.seq')}</th><th scope="col">{t('fo.folio.col.date')}</th><th scope="col">{t('fo.folio.col.description')}</th><th className="text-right" scope="col">{t('fo.folio.col.base')}</th><th className="text-right" scope="col">{t('fo.folio.col.service')}</th><th className="text-right" scope="col">{t('fo.folio.col.tax')}</th><th className="text-right" scope="col">{t('fo.folio.col.total')}</th><th scope="col"><span className="sr-only">{t('fo.folio.reverse')}</span></th></tr></thead>
                            <tbody>{folio.postings.map((p) => (
                                <tr className={`border-t border-border ${p.is_reversed ? 'text-muted-foreground line-through' : ''}`} key={p.id}>
                                    <td className="py-2">{p.seq}</td>
                                    <td>{format.date(p.business_date)}</td>
                                    <td className="no-underline"><span className="mr-2 text-xs">[{t(`fo.folio.type.${p.type}` as 'fo.folio.type.charge')}]</span>{p.description}{p.payment_reference ? ` · ${p.payment_reference}` : ''}{p.is_reversed ? <em className="ml-2 text-xs">({t('fo.folio.reversed')})</em> : null}</td>
                                    <td className="text-right">{p.base_minor === 0 ? '' : money(p.base_minor)}</td>
                                    <td className="text-right">{p.service_charge_minor === 0 ? '' : money(p.service_charge_minor)}</td>
                                    <td className="text-right">{p.tax_minor === 0 ? '' : money(p.tax_minor)}</td>
                                    <td className="text-right font-medium">{p.total_minor < 0 ? `−${money(-p.total_minor)}` : money(p.total_minor)}</td>
                                    <td className="text-right"><div className="flex justify-end gap-1">
                                        {open && p.type === 'charge' && !p.is_reversed ? <Button onClick={() => void startMove(p)} size="sm" type="button" variant="outline">{t('fo.folio.move')}</Button> : null}
                                        {open && p.type !== 'reversal' && !p.is_reversed ? <Button onClick={() => { action.clear(); setReverse({ posting: p, reason: '' }); }} size="sm" type="button" variant="outline">{t('fo.folio.reverse')}</Button> : null}
                                    </div></td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                )}
            </section>

            {approvals.length > 0 && (
                <section aria-labelledby="appr-h" className="flex flex-col gap-1 text-sm">
                    <h2 className="text-lg font-semibold" id="appr-h">{t('fo.folio.approvals')}</h2>
                    <ul>{approvals.map((a) => <li key={a.id}>{t('fo.folio.approvalRow', { type: a.subject_type, status: a.consumed ? 'consumed' : a.status })}</li>)}</ul>
                    <Button asChild size="sm" variant="outline"><Link href="/approvals">{t('identity.approvalPolicies.inbox')}</Link></Button>
                </section>
            )}

            <Dialog footer={footer(() => void saveCharge())} onClose={closeAll} open={charge !== null} title={t('fo.folio.addCharge')}>
                {charge !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        <FormField error={action.fieldError('code')} hint={t('fo.folio.chargeCodeHint')} label={t('fo.folio.chargeCode')}><Input maxLength={20} onChange={(e) => setCharge({ ...charge, code: e.target.value.toUpperCase() })} value={charge.code} /></FormField>
                        <FormField error={action.fieldError('description')} label={t('fo.folio.chargeDescription')}><Input maxLength={200} onChange={(e) => setCharge({ ...charge, description: e.target.value })} value={charge.description} /></FormField>
                        <FormField error={amountError ? t('fo.folio.invalidAmount') : action.fieldError('amount')} hint={t('fo.folio.chargeAmountHint')} label={t('fo.folio.chargeAmount')}><Input inputMode="decimal" onChange={(e) => setCharge({ ...charge, amount: e.target.value })} value={charge.amount} /></FormField>
                        <fieldset className="flex flex-col gap-1 text-sm">
                            <label className="flex items-center gap-2"><input checked={!charge.nett} name="nett" onChange={() => setCharge({ ...charge, nett: false })} type="radio" />{t('fo.folio.plusPlus')}</label>
                            <label className="flex items-center gap-2"><input checked={charge.nett} name="nett" onChange={() => setCharge({ ...charge, nett: true })} type="radio" />{t('fo.folio.nett')}</label>
                        </fieldset>
                    </div>
                )}
            </Dialog>

            <Dialog footer={footer(() => void savePayment())} onClose={closeAll} open={payment !== null} title={t('fo.folio.addPayment')}>
                {payment !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        <FormField error={action.fieldError('payment_method')} label={t('fo.folio.method')}><Select onChange={(e) => setPayment({ ...payment, method: e.target.value })} value={payment.method}>{METHODS.map((m) => <option key={m} value={m}>{t(`fo.folio.method.${m}`)}</option>)}</Select></FormField>
                        <FormField error={amountError ? t('fo.folio.invalidAmount') : action.fieldError('amount')} label={t('fo.folio.amount')}><Input inputMode="decimal" onChange={(e) => setPayment({ ...payment, amount: e.target.value })} value={payment.amount} /></FormField>
                        <FormField error={action.fieldError('payment_reference')} hint={t('fo.folio.referenceHint')} label={t('fo.folio.reference')}><Input maxLength={80} onChange={(e) => setPayment({ ...payment, reference: e.target.value })} value={payment.reference} /></FormField>
                        <FormField label={t('fo.folio.purpose')}><Select onChange={(e) => setPayment({ ...payment, purpose: e.target.value })} value={payment.purpose}><option value="settlement">{t('fo.folio.purpose.settlement')}</option><option value="deposit">{t('fo.folio.purpose.deposit')}</option></Select></FormField>
                    </div>
                )}
            </Dialog>

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={t('fo.folio.reverse')}
                consequence={t('fo.folio.reverse.consequence')}
                destructive
                onCancel={closeAll}
                onConfirm={() => void doReverse()}
                open={reverse !== null}
                pending={action.busy}
                title={t('fo.folio.reverse.title')}
            >
                <div className="flex flex-col gap-3">
                    {error}
                    {reverse !== null && readyApproval(reverse.posting.id, 'front-office.folio.reversal') ? <StatusBadge label={t('fo.folio.approvalReady')} tone="success" /> : null}
                    {reverse !== null && pendingApproval(reverse.posting.id, 'front-office.folio.reversal') ? <StatusBadge label={t('fo.folio.approvalPending')} tone="pending" /> : null}
                    {needsApproval ? <Alert actions={<Button loading={action.busy} onClick={() => void requestReversal()} size="sm" type="button">{t('fo.folio.requestApproval')}</Button>} title={t('fo.folio.approvalNeeded')} tone="warning" /> : null}
                    {requested ? <Alert title={t('fo.folio.approvalRequested')} tone="info" /> : null}
                    <FormField error={action.fieldError('reason')} label={t('fo.folio.reason')}><Input maxLength={500} onChange={(e) => reverse !== null && setReverse({ ...reverse, reason: e.target.value })} value={reverse?.reason ?? ''} /></FormField>
                </div>
            </ConfirmDialog>

            <Dialog footer={<><Button disabled={action.busy} onClick={closeAll} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={move === null || move.target === '' || move.reason.trim() === ''} loading={action.busy} onClick={() => void doMove()} type="button">{t('fo.folio.move')}</Button></>} onClose={closeAll} open={move !== null} title={t('fo.folio.move.title')}>
                {move !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        <p className="text-sm">{t('fo.folio.move.about', { description: move.posting.description, amount: money(move.posting.total_minor) })}</p>
                        <FormField error={action.fieldError('target_folio_id')} label={t('fo.folio.move.target')}>
                            <Select onChange={(e) => setMove({ ...move, target: e.target.value })} value={move.target}>
                                <option value="">{move.targets === null ? '…' : t('fo.folio.move.choose')}</option>
                                {(move.targets?.same ?? []).map((x) => <option key={x.folio_id} value={x.folio_id}>{t('fo.folio.move.same', { number: x.number, label: x.label })}</option>)}
                                {(move.targets?.others ?? []).map((x) => <option key={x.folio_id} value={x.folio_id}>{t('fo.folio.move.other', { room: x.room, guest: x.guest, number: x.number })}</option>)}
                            </Select>
                        </FormField>
                        {move.targets !== null && move.targets.same.length === 0 && move.targets.others.length === 0 ? <p className="text-xs text-muted-foreground">{t('fo.folio.move.none')}</p> : null}
                        <FormField error={action.fieldError('reason')} label={t('fo.folio.reason')}><Input maxLength={400} onChange={(e) => setMove({ ...move, reason: e.target.value })} value={move.reason} /></FormField>
                        <p className="text-xs text-muted-foreground">{t('fo.folio.move.note')}</p>
                    </div>
                )}
            </Dialog>

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={t('fo.folio.refund')}
                consequence={t('fo.folio.refund.consequence')}
                destructive
                onCancel={closeAll}
                onConfirm={() => void doRefund()}
                open={refund !== null}
                pending={action.busy}
                title={t('fo.folio.refund.title')}
            >
                {refund !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        {needsApproval ? <Alert actions={<Button loading={action.busy} onClick={() => void requestRefund()} size="sm" type="button">{t('fo.folio.requestApproval')}</Button>} title={t('fo.folio.approvalNeeded')} tone="warning" /> : null}
                        {requested ? <Alert title={t('fo.folio.approvalRequested')} tone="info" /> : null}
                        <FormField error={action.fieldError('payment_method')} label={t('fo.folio.method')}><Select onChange={(e) => setRefund({ ...refund, method: e.target.value })} value={refund.method}>{METHODS.map((m) => <option key={m} value={m}>{t(`fo.folio.method.${m}`)}</option>)}</Select></FormField>
                        <FormField error={amountError ? t('fo.folio.invalidAmount') : action.fieldError('amount')} label={t('fo.folio.amount')}><Input inputMode="decimal" onChange={(e) => setRefund({ ...refund, amount: e.target.value })} value={refund.amount} /></FormField>
                        <FormField error={action.fieldError('payment_reference')} hint={t('fo.folio.referenceHint')} label={t('fo.folio.reference')}><Input maxLength={80} onChange={(e) => setRefund({ ...refund, reference: e.target.value })} value={refund.reference} /></FormField>
                        <FormField error={action.fieldError('reason')} label={t('fo.folio.reason')}><Input maxLength={500} onChange={(e) => setRefund({ ...refund, reason: e.target.value })} value={refund.reason} /></FormField>
                    </div>
                )}
            </ConfirmDialog>

            <ConfirmDialog cancelLabel={t('ui.dialog.cancel')} confirmLabel={t('fo.folio.close')} consequence={t('fo.folio.close.consequence')} onCancel={closeAll} onConfirm={() => void doClose()} open={closing} pending={action.busy} title={t('fo.folio.close.title')}>
                {error}
            </ConfirmDialog>
        </FrontOfficeShell>
    );
}
