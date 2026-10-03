import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { PETTY_SETTLEMENT_TONE, PettyVariance, usePettyVoucherColumns, type PettySettlementHead, type PettyVoucher } from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Settlement = PettySettlementHead & {
    fund_id: string; fund_code: string; fund_name: string; imprest_minor: number; currency: string; book_minor: number; variance_reason: string | null; decision_note: string | null;
    lock_version: number; vouchers: PettyVoucher[]; may_decide: boolean;
};
type DecideForm = { decision: 'approve' | 'reject'; note: string };

/** One settlement of a petty cash fund: the account the custodian gives, the vouchers behind it, and the decision of the person who checks it. */
export default function PettySettlementPage({ settlement }: { settlement: Settlement }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<DecideForm | null>(null);
    const money = (minor: number) => format.money(minor, settlement.currency);
    const status = (s: string) => t(`fin.petty.sStatus.${s}` as MessageKey);
    const columns = usePettyVoucherColumns(settlement.currency);
    const withoutReceipt = settlement.vouchers.filter((v) => v.no_receipt_reason !== null).length;
    const waiting = settlement.status === 'submitted';
    const reload = ['settlement'];

    function openDecide() {
        action.clear();
        setForm({ decision: 'approve', note: '' });
    }

    async function decide() {
        if (form === null) return;
        const done = await action.run(`/finance/petty/settlements/${settlement.id}/decide`, {
            body: { decision: form.decision, note: form.note.trim() || null, lock_version: settlement.lock_version },
            reload,
        });
        if (done !== null) setForm(null);
    }

    const facts: [string, string][] = [
        [t('fin.petty.s.fund'), `${settlement.fund_code} · ${settlement.fund_name}`],
        [t('fin.petty.s.date'), format.date(settlement.business_date)],
        [t('fin.petty.s.submittedBy'), settlement.submitted_by ?? '—'],
        [t('fin.petty.s.decidedBy'), settlement.decided_by === null ? '—' : `${settlement.decided_by}${settlement.decided_at === null ? '' : ` · ${format.instant(settlement.decided_at)}`}`],
    ];
    const account: [string, string][] = [
        [t('fin.petty.imprest'), money(settlement.imprest_minor)],
        [t('fin.petty.s.vouchers'), money(settlement.voucher_total_minor)],
        [t('fin.petty.s.book'), money(settlement.book_minor)],
        [t('fin.petty.s.counted'), money(settlement.counted_minor)],
        [t('fin.petty.s.replenish'), money(settlement.replenish_minor)],
    ];

    return (
        <FinanceShell
            actions={<div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild variant="outline"><Link href={`/finance/petty/${settlement.fund_id}`}>{t('fin.petty.s.back')}</Link></Button>
                {settlement.may_decide ? <Button onClick={openDecide} type="button">{t('fin.petty.s.decide')}</Button> : null}
                <Button onClick={() => window.print()} type="button" variant="outline">{t('fin.print')}</Button>
            </div>}
            description={t('fin.petty.s.description')}
            title={`${settlement.number} · ${settlement.fund_name}`}
            wide
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" data-testid="petty-settlement-head">
                <StatusBadge label={status(settlement.status)} tone={PETTY_SETTLEMENT_TONE[settlement.status] ?? 'neutral'} />
            </div>

            {waiting && !settlement.may_decide ? <Alert title={t('fin.petty.s.cannotDecide')} tone="info" /> : null}
            {settlement.decision_note !== null ? (
                <Alert title={status(settlement.status)} tone={settlement.status === 'rejected' ? 'danger' : 'info'}>
                    <p data-testid="petty-decision-note">{t('fin.petty.s.decisionNote')}: {settlement.decision_note}</p>
                </Alert>
            ) : null}

            <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-4" data-testid="petty-settlement-facts">
                {facts.map(([name, value]) => (
                    <div key={name}>
                        <dt className="text-xs text-muted-foreground">{name}</dt>
                        <dd className="break-words">{value}</dd>
                    </div>
                ))}
            </dl>

            <section aria-labelledby="fin-petty-account-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-petty-account-h">{t('fin.petty.s.account')}</h2>
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="petty-settlement-account">
                    {account.map(([name, value]) => (
                        <div className="border border-border bg-surface p-3" key={name}>
                            <dt className="text-xs text-muted-foreground">{name}</dt>
                            <dd className="mt-1 text-base font-semibold tabular-nums">{value}</dd>
                        </div>
                    ))}
                </dl>
                <div className="flex flex-col gap-1 border border-border bg-surface p-3 text-sm">
                    <p className="flex flex-wrap items-center gap-2"><span className="text-muted-foreground">{t('fin.petty.s.difference')}:</span> <PettyVariance currency={settlement.currency} minor={settlement.variance_minor} /></p>
                    <p><span className="text-muted-foreground">{t('fin.petty.s.reason')}:</span> {settlement.variance_reason ?? '—'}</p>
                </div>
                <p className="text-sm text-muted-foreground">{t('fin.petty.s.explain')}</p>
            </section>

            <section aria-labelledby="fin-petty-vouchers-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-petty-vouchers-h">{t('fin.petty.tabVouchers')}</h2>
                {withoutReceipt > 0 ? <Alert title={t('fin.petty.s.noReceipts', { count: withoutReceipt })} tone="warning" /> : null}
                <DataGrid caption={t('fin.petty.tabVouchers')} columns={columns} empty={<EmptyState title={t('fin.petty.vouchersEmpty')} />} getRowId={(v) => v.id} id="fin.petty.settlement.vouchers" rows={settlement.vouchers} testId="petty-settlement-vouchers" />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void decide()} type="button">{form?.decision === 'reject' ? t('fin.petty.s.reject') : t('fin.petty.s.approve')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.petty.s.decideTitle', { number: settlement.number })}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.petty.s.decideHint', { replenish: money(settlement.replenish_minor), imprest: money(settlement.imprest_minor) })}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('decision')} field="decision" label={t('fin.petty.s.decision')}>
                            <Select onChange={(e) => setForm({ ...form, decision: e.target.value === 'reject' ? 'reject' : 'approve' })} searchable={false} value={form.decision}>
                                <option value="approve">{t('fin.petty.s.approve')}</option>
                                <option value="reject">{t('fin.petty.s.reject')}</option>
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('note')} field="note" hint={form.decision === 'reject' ? t('fin.petty.s.rejectHint') : t('fin.petty.s.noteHint')} label={t('fin.petty.s.note')} required={form.decision === 'reject'}>
                            <Textarea maxLength={300} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
