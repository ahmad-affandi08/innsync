import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import {
    PETTY_SETTLEMENT_TONE, PettyVariance, usePettyVoucherColumns, useSignedMoney, type PettyFund, type PettySettlementHead, type PettyVoucher,
} from '@/modules/finance/lib/finance';
import { minorToInput } from '@/modules/inventory-purchasing/lib/amounts';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Entry = { seq: number; kind: string; signed_minor: number; voucher_number: string | null; settlement_number: string | null; note: string | null; business_date: string; by: string | null };
type Fund = PettyFund & {
    max_proofs: number; accounts: { id: string; code: string; name: string }[]; vouchers: PettyVoucher[]; settlements: PettySettlementHead[]; entries: Entry[];
    may_record: boolean; may_submit: boolean; may_proof: boolean; may: { manage: boolean };
};
type RecordForm = { voucher_date: string; payee: string; description: string; expense_account_id: string; amount: string; receipt_ref: string; no_receipt_reason: string };
type EditForm = { name: string; max: string; active: boolean };
type SubmitForm = { counted: string; reason: string };

/** One petty cash fund: what is left in it, the vouchers the custodian wrote, the settlements that brought it back to its amount, and its ledger. */
export default function PettyFundPage({ fund }: { fund: Fund }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const signed = useSignedMoney();
    const record = useServerAction();
    const edit = useServerAction();
    const proof = useServerAction();
    const voidAction = useServerAction();
    const submit = useServerAction();
    const [tab, setTab] = useState('vouchers');
    const [recordForm, setRecordForm] = useState<RecordForm | null>(null);
    const [editForm, setEditForm] = useState<EditForm | null>(null);
    const [submitForm, setSubmitForm] = useState<SubmitForm | null>(null);
    const [proofFor, setProofFor] = useState<PettyVoucher | null>(null);
    const [voidFor, setVoidFor] = useState<PettyVoucher | null>(null);
    const [voidReason, setVoidReason] = useState('');
    const [pickerKey, setPickerKey] = useState(0);
    const [badAmount, setBadAmount] = useState(false);
    const [badMax, setBadMax] = useState(false);
    const [badCounted, setBadCounted] = useState(false);
    const money = (minor: number) => format.money(minor, fund.currency);
    const reload = ['fund'];
    const recordIntent = useMemo(() => newIdempotencyKey(), [JSON.stringify(recordForm)]);
    const submitIntent = useMemo(() => newIdempotencyKey(), [JSON.stringify(submitForm)]);
    const pending = fund.settlements.find((s) => s.id === fund.pending_settlement_id) ?? null;
    const kindLabel = (k: string) => t(`fin.petty.kind.${k}` as MessageKey);
    const settlementStatus = (s: string) => t(`fin.petty.sStatus.${s}` as MessageKey);
    const limitText = fund.max_voucher_minor === null ? t('fin.petty.noLimit') : money(fund.max_voucher_minor);

    const recordAmount = recordForm === null ? null : parseMajorToMinor(recordForm.amount, fund.currency);
    const counted = submitForm === null ? null : parseMajorToMinor(submitForm.counted, fund.currency);
    const variance = counted === null ? null : counted - fund.balance_minor;
    const replenish = counted === null ? null : fund.imprest_minor - counted;

    function openRecord() {
        record.clear();
        setBadAmount(false);
        setRecordForm({ voucher_date: '', payee: '', description: '', expense_account_id: '', amount: '', receipt_ref: '', no_receipt_reason: '' });
    }

    function openEdit() {
        edit.clear();
        setBadMax(false);
        setEditForm({ name: fund.name, max: fund.max_voucher_minor === null ? '' : minorToInput(fund.max_voucher_minor, fund.currency), active: fund.active });
    }

    function openSubmit() {
        submit.clear();
        setBadCounted(false);
        setSubmitForm({ counted: '', reason: '' });
    }

    function openProof(v: PettyVoucher) {
        proof.clear();
        setProofFor(v);
    }

    function openVoid(v: PettyVoucher) {
        voidAction.clear();
        setVoidReason('');
        setVoidFor(v);
    }

    async function saveRecord() {
        if (recordForm === null) return;
        const minor = parseMajorToMinor(recordForm.amount, fund.currency);

        setBadAmount(minor === null);
        if (minor === null) return;
        const done = await record.run(`/finance/petty/${fund.id}/vouchers`, {
            body: {
                voucher_date: recordForm.voucher_date || null, payee: recordForm.payee.trim(), description: recordForm.description.trim(), expense_account_id: recordForm.expense_account_id,
                amount_minor: minor, receipt_ref: recordForm.receipt_ref.trim() || null, no_receipt_reason: recordForm.no_receipt_reason.trim() || null,
            },
            idempotencyKey: recordIntent,
            reload,
        });
        if (done !== null) setRecordForm(null);
    }

    async function saveEdit() {
        if (editForm === null) return;
        const max = editForm.max.trim() === '' ? null : parseMajorToMinor(editForm.max, fund.currency);
        const invalid = editForm.max.trim() !== '' && max === null;

        setBadMax(invalid);
        if (invalid) return;
        const done = await edit.run(`/finance/petty/${fund.id}`, {
            body: { name: editForm.name.trim(), custodian_id: fund.custodian_id, max_voucher_minor: max, active: editForm.active, lock_version: fund.lock_version },
            reload,
        });
        if (done !== null) setEditForm(null);
    }

    async function upload(file: File | undefined) {
        if (file === undefined || proofFor === null) return;
        const body = new FormData();
        body.set('proof', file);
        const done = await proof.run(`/finance/petty/vouchers/${proofFor.id}/proofs`, { body, reload });
        setPickerKey((k) => k + 1);
        if (done !== null) setProofFor(null);
    }

    async function saveVoid() {
        if (voidFor === null) return;
        const done = await voidAction.run(`/finance/petty/vouchers/${voidFor.id}/void`, { body: { reason: voidReason.trim() }, reload });
        if (done !== null) { setVoidFor(null); setVoidReason(''); }
    }

    async function saveSubmit() {
        if (submitForm === null) return;
        const minor = parseMajorToMinor(submitForm.counted, fund.currency);

        setBadCounted(minor === null);
        if (minor === null) return;
        const done = await submit.run<{ settlement: { id: string } }>(`/finance/petty/${fund.id}/settlements`, {
            body: { counted_minor: minor, variance_reason: submitForm.reason.trim() || null },
            idempotencyKey: submitIntent,
        });
        if (done !== null) router.visit(`/finance/petty/settlements/${done.settlement.id}`);
    }

    const voucherActions: DataGridColumn<PettyVoucher> | undefined = fund.may_proof || fund.may.manage ? {
        id: 'actions', label: t('inv.col.actions'),
        cell: (v) => (
            <span className="flex flex-wrap gap-2">
                {fund.may_proof && v.state !== 'voided' && v.proof_count < fund.max_proofs ? <Button onClick={() => openProof(v)} size="sm" type="button" variant="outline">{t('fin.petty.addProof')}</Button> : null}
                {v.may_void ? <Button onClick={() => openVoid(v)} size="sm" type="button" variant="outline">{t('fin.petty.void')}</Button> : null}
            </span>
        ),
    } : undefined;
    const voucherColumns = usePettyVoucherColumns(fund.currency, voucherActions);

    const settlementColumns: DataGridColumn<PettySettlementHead>[] = [
        { id: 'number', label: t('fin.petty.s.number'), value: (s) => s.number, rowHeader: true },
        { id: 'date', label: t('fin.petty.s.date'), value: (s) => s.business_date, cell: (s) => format.date(s.business_date) },
        { id: 'vouchers', label: t('fin.petty.s.vouchers'), align: 'right', value: (s) => s.voucher_total_minor, cell: (s) => money(s.voucher_total_minor) },
        { id: 'counted', label: t('fin.petty.s.counted'), align: 'right', value: (s) => s.counted_minor, cell: (s) => money(s.counted_minor) },
        { id: 'difference', label: t('fin.petty.s.difference'), value: (s) => s.variance_minor, cell: (s) => <PettyVariance currency={fund.currency} minor={s.variance_minor} /> },
        { id: 'replenish', label: t('fin.petty.s.replenish'), align: 'right', value: (s) => s.replenish_minor, cell: (s) => money(s.replenish_minor) },
        { id: 'status', label: t('inv.col.status'), value: (s) => s.status, filter: 'select', filterLabel: settlementStatus, cell: (s) => <StatusBadge label={settlementStatus(s.status)} tone={PETTY_SETTLEMENT_TONE[s.status] ?? 'neutral'} /> },
        { id: 'by', label: t('fin.petty.s.submittedBy'), value: (s) => s.submitted_by ?? '', cell: (s) => s.submitted_by ?? '—', hidden: true },
        { id: 'decidedBy', label: t('fin.petty.s.decidedBy'), value: (s) => s.decided_by ?? '', cell: (s) => (s.decided_by === null ? '—' : `${s.decided_by}${s.decided_at === null ? '' : ` · ${format.instant(s.decided_at)}`}`) },
        { id: 'actions', label: t('inv.col.actions'), cell: (s) => <Button asChild size="sm" variant="outline"><Link href={`/finance/petty/settlements/${s.id}`}>{t('fin.petty.open')}</Link></Button> },
    ];

    const entryColumns: DataGridColumn<Entry>[] = [
        { id: 'seq', label: t('fin.petty.l.seq'), align: 'right', value: (e) => e.seq, rowHeader: true },
        { id: 'date', label: t('fin.petty.l.date'), value: (e) => e.business_date, cell: (e) => format.date(e.business_date) },
        { id: 'kind', label: t('fin.petty.l.kind'), value: (e) => e.kind, filter: 'select', filterLabel: kindLabel, cell: (e) => kindLabel(e.kind) },
        { id: 'amount', label: t('fin.petty.l.amount'), align: 'right', value: (e) => e.signed_minor, cell: (e) => signed(e.signed_minor, fund.currency) },
        { id: 'reference', label: t('fin.petty.l.reference'), value: (e) => e.voucher_number ?? e.settlement_number ?? '', cell: (e) => e.voucher_number ?? e.settlement_number ?? '—' },
        { id: 'note', label: t('fin.petty.l.note'), value: (e) => e.note ?? '', cell: (e) => e.note ?? '—' },
        { id: 'by', label: t('fin.petty.l.by'), value: (e) => e.by ?? '', cell: (e) => e.by ?? '—', hidden: true },
    ];

    const actions = (
        <div className="flex flex-wrap gap-2 print:hidden">
            <Button asChild variant="outline"><Link href="/finance/petty">{t('fin.petty.back')}</Link></Button>
            {fund.may.manage ? <Button onClick={openEdit} type="button" variant="outline">{t('fin.petty.edit')}</Button> : null}
            {fund.may_submit ? <Button onClick={openSubmit} type="button" variant="outline">{t('fin.petty.submit')}</Button> : null}
            {fund.may_record ? <Button onClick={openRecord} type="button">{t('fin.petty.record')}</Button> : null}
        </div>
    );

    return (
        <FinanceShell actions={actions} description={t('fin.petty.fundDescription', { custodian: fund.custodian_name ?? '—', limit: limitText })} title={`${fund.code} · ${fund.name}`} wide>
            {!fund.active ? <Alert title={t('fin.petty.closedTitle')} tone="info"><p>{t('fin.petty.closedBody')}</p></Alert> : null}
            {fund.pending_settlement_id !== null ? (
                <Alert
                    actions={<Button asChild size="sm" variant="outline"><Link href={`/finance/petty/settlements/${fund.pending_settlement_id}`}>{t('fin.petty.viewSettlement')}</Link></Button>}
                    title={t('fin.petty.pendingTitle', { number: pending?.number ?? '' })}
                    tone="warning"
                >
                    <p>{t('fin.petty.pendingBody')}</p>
                </Alert>
            ) : null}

            <section aria-label={t('fin.petty.title')} className="grid gap-3 sm:grid-cols-3" data-testid="petty-kpis">
                <Metric detail={t('fin.petty.balanceHint')} label={t('fin.petty.balance')} value={money(fund.balance_minor)} />
                <Metric detail={t('fin.petty.imprestHintShort')} label={t('fin.petty.imprest')} value={money(fund.imprest_minor)} />
                <Metric detail={t('fin.petty.unsettledCount', { count: fund.unsettled_count })} label={t('fin.petty.unsettledAmount')} value={money(fund.unsettled_minor)} />
            </section>

            <Tabs onValueChange={setTab} value={tab}>
                <TabsList>
                    <TabsTrigger value="vouchers">{t('fin.petty.tabVouchers')}</TabsTrigger>
                    <TabsTrigger value="settlements">{t('fin.petty.tabSettlements')}</TabsTrigger>
                    <TabsTrigger value="ledger">{t('fin.petty.tabLedger')}</TabsTrigger>
                </TabsList>

                <TabsContent className="flex flex-col gap-3" value="vouchers">
                    <DataGrid caption={t('fin.petty.tabVouchers')} columns={voucherColumns} empty={<EmptyState title={t('fin.petty.vouchersEmpty')} />} getRowId={(v) => v.id} id="fin.petty.vouchers" rows={fund.vouchers} testId="petty-vouchers" />
                </TabsContent>

                <TabsContent className="flex flex-col gap-3" value="settlements">
                    <DataGrid caption={t('fin.petty.tabSettlements')} columns={settlementColumns} empty={<EmptyState title={t('fin.petty.settlementsEmpty')} />} getRowId={(s) => s.id} id="fin.petty.settlements" rows={fund.settlements} testId="petty-settlements" />
                </TabsContent>

                <TabsContent className="flex flex-col gap-3" value="ledger">
                    <p className="text-sm text-muted-foreground">{t('fin.petty.ledgerHint')}</p>
                    <DataGrid caption={t('fin.petty.tabLedger')} columns={entryColumns} empty={<EmptyState title={t('fin.petty.ledgerEmpty')} />} getRowId={(e) => String(e.seq)} id="fin.petty.ledger" rows={fund.entries} testId="petty-ledger" />
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<>
                    <Button disabled={record.busy} onClick={() => setRecordForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={record.busy} onClick={() => void saveRecord()} type="button">{t('fin.petty.rec.save')}</Button>
                </>}
                onClose={() => setRecordForm(null)}
                open={recordForm !== null}
                title={t('fin.petty.rec.title', { fund: fund.name })}
            >
                {recordForm !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.petty.rec.hint')}</p>
                        {record.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={record.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={record.fieldError('payee')} field="payee" label={t('fin.petty.rec.payee')}>
                            <Input maxLength={120} onChange={(e) => setRecordForm({ ...recordForm, payee: e.target.value })} value={recordForm.payee} />
                        </FormField>
                        <FormField error={record.fieldError('voucher_date')} field="voucher_date" hint={t('fin.petty.rec.dateHint')} label={t('fin.petty.rec.date')}>
                            <DatePicker onChange={(e) => setRecordForm({ ...recordForm, voucher_date: e.target.value })} value={recordForm.voucher_date} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={record.fieldError('description')} field="description" label={t('fin.petty.rec.description')}>
                                <Input maxLength={200} onChange={(e) => setRecordForm({ ...recordForm, description: e.target.value })} value={recordForm.description} />
                            </FormField>
                        </div>
                        <FormField error={record.fieldError('expense_account_id')} field="expense_account_id" label={t('fin.petty.rec.account')}>
                            <Select onChange={(e) => setRecordForm({ ...recordForm, expense_account_id: e.target.value })} value={recordForm.expense_account_id}>
                                <option value="">{t('fin.petty.rec.chooseAccount')}</option>
                                {fund.accounts.map((a) => <option key={a.id} value={a.id}>{`${a.code} · ${a.name}`}</option>)}
                            </Select>
                        </FormField>
                        <FormField
                            error={badAmount ? t('fin.petty.badAmount') : record.fieldError('amount_minor')}
                            field="amount_minor"
                            hint={fund.max_voucher_minor === null ? t('fin.petty.rec.amountHintNoLimit', { balance: money(fund.balance_minor) }) : t('fin.petty.rec.amountHint', { balance: money(fund.balance_minor), limit: limitText })}
                            label={t('fin.petty.rec.amount', { currency: fund.currency })}
                        >
                            <MoneyInput onChange={(e) => { setBadAmount(false); setRecordForm({ ...recordForm, amount: e.target.value }); }} value={recordForm.amount} />
                        </FormField>
                        {recordAmount !== null && recordAmount > fund.balance_minor ? <p aria-live="polite" className="text-sm text-danger sm:col-span-2">{t('fin.petty.rec.overBalance')}</p> : null}
                        {recordAmount !== null && fund.max_voucher_minor !== null && recordAmount > fund.max_voucher_minor ? <p aria-live="polite" className="text-sm text-danger sm:col-span-2">{t('fin.petty.rec.overLimit')}</p> : null}
                        <FormField error={record.fieldError('receipt_ref')} field="receipt_ref" hint={t('fin.petty.rec.receiptHint')} label={t('fin.petty.rec.receipt')}>
                            <Input maxLength={40} onChange={(e) => setRecordForm({ ...recordForm, receipt_ref: e.target.value })} value={recordForm.receipt_ref} />
                        </FormField>
                        <FormField error={record.fieldError('no_receipt_reason')} field="no_receipt_reason" hint={t('fin.petty.rec.noReceiptHint')} label={t('fin.petty.rec.noReceipt')}>
                            <Input maxLength={200} onChange={(e) => setRecordForm({ ...recordForm, no_receipt_reason: e.target.value })} value={recordForm.no_receipt_reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={submit.busy} onClick={() => setSubmitForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={submit.busy} onClick={() => void saveSubmit()} type="button">{t('fin.petty.sub.save')}</Button>
                </>}
                onClose={() => setSubmitForm(null)}
                open={submitForm !== null}
                title={t('fin.petty.sub.title', { fund: fund.name })}
            >
                {submitForm !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.petty.sub.hint', { count: fund.unsettled_count, amount: money(fund.unsettled_minor) })}</p>
                        {submit.error !== null ? <ErrorState {...errorCopy} error={submit.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField
                            error={badCounted ? t('fin.petty.badAmount') : submit.fieldError('counted_minor')}
                            field="counted_minor"
                            hint={t('fin.petty.sub.book', { amount: money(fund.balance_minor) })}
                            label={t('fin.petty.sub.counted', { currency: fund.currency })}
                        >
                            <MoneyInput onChange={(e) => { setBadCounted(false); setSubmitForm({ ...submitForm, counted: e.target.value }); }} value={submitForm.counted} />
                        </FormField>
                        {variance !== null && replenish !== null ? (
                            <div aria-live="polite" className="flex flex-col gap-1 text-sm" data-testid="petty-live-variance">
                                <p>{variance === 0 ? t('fin.petty.sub.liveMatch') : t('fin.petty.sub.liveVariance', { difference: signed(variance, fund.currency) })}</p>
                                <p>{replenish < 0 ? t('fin.petty.sub.overImprest', { amount: money(fund.imprest_minor) }) : t('fin.petty.sub.liveReplenish', { amount: money(replenish), imprest: money(fund.imprest_minor) })}</p>
                            </div>
                        ) : null}
                        <FormField error={submit.fieldError('variance_reason')} field="variance_reason" hint={t('fin.petty.sub.reasonHint')} label={t('fin.petty.sub.reason')} required={variance !== null && variance !== 0}>
                            <Textarea maxLength={300} onChange={(e) => setSubmitForm({ ...submitForm, reason: e.target.value })} value={submitForm.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={edit.busy} onClick={() => setEditForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={edit.busy} onClick={() => void saveEdit()} type="button">{t('fin.petty.editSave')}</Button>
                </>}
                onClose={() => setEditForm(null)}
                open={editForm !== null}
                title={t('fin.petty.editTitle', { code: fund.code })}
            >
                {editForm !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {edit.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={edit.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={edit.fieldError('name')} field="name" label={t('fin.petty.name')}>
                            <Input maxLength={80} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} value={editForm.name} />
                        </FormField>
                        <FormField error={badMax ? t('fin.petty.badAmount') : edit.fieldError('max_voucher_minor')} field="max_voucher_minor" hint={t('fin.petty.maxHint')} label={t('fin.petty.maxField', { currency: fund.currency })}>
                            <MoneyInput onChange={(e) => { setBadMax(false); setEditForm({ ...editForm, max: e.target.value }); }} value={editForm.max} />
                        </FormField>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input checked={editForm.active} onChange={(e) => setEditForm({ ...editForm, active: e.target.checked })} type="checkbox" />
                            {t('fin.petty.activeField')}
                        </label>
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.petty.activeHint')}</p>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<Button onClick={() => setProofFor(null)} type="button" variant="outline">{t('fin.petty.proofClose')}</Button>}
                onClose={() => setProofFor(null)}
                open={proofFor !== null}
                title={t('fin.petty.proofTitle', { number: proofFor?.number ?? '' })}
            >
                {proofFor !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.petty.proofHint', { count: proofFor.proof_count, max: fund.max_proofs })}</p>
                        {proof.error !== null ? <ErrorState {...errorCopy} error={proof.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={proof.fieldError('proof')} field="proof" hint={t('fin.petty.proofLimit')} label={t('fin.petty.proofField')}>
                            <Input accept="application/pdf,image/jpeg,image/png" key={pickerKey} onChange={(e) => void upload(e.target.files?.[0])} type="file" />
                        </FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={voidAction.busy} onClick={() => setVoidFor(null)} type="button" variant="outline">{t('fin.petty.keep')}</Button>
                    <Button loading={voidAction.busy} onClick={() => void saveVoid()} type="button">{t('fin.petty.void')}</Button>
                </>}
                onClose={() => setVoidFor(null)}
                open={voidFor !== null}
                title={t('fin.petty.voidTitle', { number: voidFor?.number ?? '' })}
            >
                {voidFor !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.petty.voidHint', { amount: money(voidFor.amount_minor) })}</p>
                        {voidAction.error !== null ? <ErrorState {...errorCopy} error={voidAction.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={voidAction.fieldError('reason')} field="reason" label={t('fin.petty.voidReason')}>
                            <Input maxLength={200} onChange={(e) => setVoidReason(e.target.value)} value={voidReason} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
