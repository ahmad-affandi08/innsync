import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { NOTE_TONE, PromiseBadge, RECEIPT_KIND_TONE, ReceivableStatus, useCustomerKindLabel, type ReceivableRow } from '@/modules/finance/lib/finance';
import { minorToInput } from '@/modules/inventory-purchasing/lib/amounts';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Receipt = {
    id: string; number: string; kind: 'receipt' | 'reversal' | 'credit_note' | 'write_off'; amount_minor: number; method: string | null; received_on: string; reference: string | null; note: string | null; by: string | null;
    reversal_number: string | null; reverses_number: string | null; may_reverse: boolean;
};
type CollectionNote = { id: string; kind: string; note: string; promised_on: string | null; promised_minor: number | null; by: string | null; at: string };
type Receivable = ReceivableRow & {
    reference: string | null; business_date: string; booked_by: string | null; customer_kind: string; receipts: Receipt[]; notes: CollectionNote[];
    methods: string[]; note_kinds: string[]; may_receive: boolean; may_note: boolean; may_adjust: boolean; adjust_kinds: string[];
};
type ReceiptForm = { amount: string; method: string; received_on: string; reference: string; note: string };
type AdjustForm = { kind: string; amount: string; reason: string };
type NoteForm = { kind: string; note: string; promised_on: string; promised_minor: string };

/** The property's business date, worked back from the due date and the days left to it. */
function businessToday(r: Pick<ReceivableRow, 'due_date' | 'days_to_due'>): string {
    const date = new Date(`${r.due_date}T00:00:00Z`);

    date.setUTCDate(date.getUTCDate() - r.days_to_due);

    return date.toISOString().slice(0, 10);
}

/** One receivable: where it came from, what was received against it, and the collection notes of those chasing it. */
export default function ReceivablePage({ receivable }: { receivable: Receivable }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const receive = useServerAction();
    const note = useServerAction();
    const adjust = useServerAction();
    const reverse = useServerAction();
    const kindLabel = useCustomerKindLabel();
    const [receiptForm, setReceiptForm] = useState<ReceiptForm | null>(null);
    const [noteForm, setNoteForm] = useState<NoteForm | null>(null);
    const [adjustForm, setAdjustForm] = useState<AdjustForm | null>(null);
    const [reversing, setReversing] = useState<Receipt | null>(null);
    const [reverseReason, setReverseReason] = useState('');
    const [badAmount, setBadAmount] = useState(false);
    const [badPromised, setBadPromised] = useState(false);
    const money = (minor: number) => format.money(minor, receivable.currency);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(receiptForm)]);
    const reload = ['receivable'];
    const methodLabel = (m: string) => t(`fin.ar.method.${m}` as MessageKey);
    const entryKind = (k: string) => t(`fin.ar.kind.${k}` as MessageKey);
    const noteKind = (k: string) => t(`fin.ar.noteKind.${k}` as MessageKey);
    const timeline = useMemo(() => [...receivable.notes].sort((a, b) => b.at.localeCompare(a.at)), [receivable.notes]);
    const isFolio = receivable.source_type === 'company_folio';
    const today = businessToday(receivable);
    const promise = noteForm?.kind === 'promise';

    function openReceive() {
        receive.clear();
        setBadAmount(false);
        setReceiptForm({ amount: minorToInput(receivable.balance_minor, receivable.currency), method: receivable.methods[0] ?? 'transfer', received_on: '', reference: '', note: '' });
    }

    function openNote() {
        note.clear();
        setBadAmount(false);
        setBadPromised(false);
        setNoteForm({ kind: receivable.note_kinds[0] ?? 'note', note: '', promised_on: '', promised_minor: '' });
    }

    async function saveReceipt() {
        if (receiptForm === null) return;
        const minor = parseMajorToMinor(receiptForm.amount, receivable.currency);

        setBadAmount(minor === null);
        if (minor === null) return;
        const done = await receive.run(`/finance/receivables/${receivable.id}/receipts`, {
            body: { amount_minor: minor, method: receiptForm.method, received_on: receiptForm.received_on || null, reference: receiptForm.reference.trim(), note: receiptForm.note.trim() || null },
            idempotencyKey: intent,
            reload,
        });
        if (done !== null) setReceiptForm(null);
    }

    function openAdjust() {
        adjust.clear();
        setBadAmount(false);
        setAdjustForm({ kind: receivable.adjust_kinds[0] ?? 'credit_note', amount: minorToInput(receivable.balance_minor, receivable.currency), reason: '' });
    }

    function openReverse(receipt: Receipt) {
        reverse.clear();
        setReverseReason('');
        setReversing(receipt);
    }

    async function saveAdjustment() {
        if (adjustForm === null) return;
        const minor = parseMajorToMinor(adjustForm.amount, receivable.currency);

        setBadAmount(minor === null);
        if (minor === null) return;
        const done = await adjust.run(`/finance/receivables/${receivable.id}/adjustments`, { body: { kind: adjustForm.kind, amount_minor: minor, reason: adjustForm.reason.trim() }, reload });
        if (done !== null) setAdjustForm(null);
    }

    async function saveReversal() {
        if (reversing === null) return;
        const done = await reverse.run(`/finance/receivables/receipts/${reversing.id}/reverse`, { body: { reason: reverseReason.trim() }, reload });
        if (done !== null) { setReversing(null); setReverseReason(''); }
    }

    async function saveNote() {
        if (noteForm === null) return;
        const promised = noteForm.promised_minor.trim() !== '' ? parseMajorToMinor(noteForm.promised_minor, receivable.currency) : null;
        const invalid = noteForm.promised_minor.trim() !== '' && promised === null;

        setBadPromised(invalid);
        if (invalid) return;
        const done = await note.run(`/finance/receivables/${receivable.id}/notes`, {
            body: { kind: noteForm.kind, note: noteForm.note.trim(), promised_on: noteForm.promised_on || null, promised_minor: promised },
            reload,
        });
        if (done !== null) setNoteForm(null);
    }

    const facts: [string, string][] = [
        [t('fin.ar.customer'), `${receivable.customer_code} · ${receivable.customer_name} (${kindLabel(receivable.customer_kind)})`],
        [t('fin.ar.source'), `${t(isFolio ? 'fin.ar.sourceCompanyFolio' : 'fin.ar.sourceManual')} · ${receivable.source_number}`],
        [t('fin.ar.descriptionCol'), receivable.description],
        [t('fin.ar.reference'), receivable.reference ?? '—'],
        [t('fin.ar.issued'), format.date(receivable.issued_on)],
        [t('fin.col.dueDate'), format.date(receivable.due_date)],
        [t('fin.ar.bookedOn'), format.date(receivable.business_date)],
        [t('fin.ar.bookedBy'), receivable.booked_by ?? '—'],
    ];
    const amounts: [string, number][] = [
        [t('fin.col.amount'), receivable.amount_minor],
        [t('fin.ar.received'), receivable.received_minor],
        [t('fin.col.balance'), receivable.balance_minor],
    ];

    return (
        <FinanceShell
            actions={<div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild variant="outline"><Link href="/finance/receivables">{t('fin.ar.back')}</Link></Button>
                {receivable.may_note ? <Button onClick={openNote} type="button" variant="outline">{t('fin.ar.addNote')}</Button> : null}
                {receivable.may_adjust ? <Button onClick={openAdjust} type="button" variant="outline">{t('fin.ar.adjust')}</Button> : null}
                {receivable.may_receive ? <Button onClick={openReceive} type="button">{t('fin.ar.record')}</Button> : null}
            </div>}
            description={t('fin.ar.detailDescription')}
            title={`${receivable.customer_name} · ${receivable.number}`}
            wide
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" data-testid="receivable-head">
                <ReceivableStatus row={receivable} />
                <PromiseBadge row={receivable} today={today} />
                {receivable.balance_minor > 0 && !receivable.overdue ? <span className="text-muted-foreground">{receivable.days_to_due === 0 ? t('fin.dueToday') : t('fin.dueIn', { days: receivable.days_to_due })}</span> : null}
            </div>

            <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-4" data-testid="receivable-facts">
                {facts.map(([name, value]) => (
                    <div key={name}>
                        <dt className="text-xs text-muted-foreground">{name}</dt>
                        <dd className="break-words">{value}</dd>
                    </div>
                ))}
            </dl>

            <section aria-labelledby="fin-ar-amounts-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-ar-amounts-h">{t('fin.ar.amounts')}</h2>
                <dl className="grid gap-3 sm:grid-cols-3" data-testid="receivable-amounts">
                    {amounts.map(([name, minor]) => (
                        <div className="border border-border bg-surface p-3" key={name}>
                            <dt className="text-xs text-muted-foreground">{name}</dt>
                            <dd className="mt-1 text-base font-semibold tabular-nums">{money(minor)}</dd>
                        </div>
                    ))}
                </dl>
                {isFolio ? <p className="text-sm text-muted-foreground">{t('fin.ar.folioNote')}</p> : null}
            </section>

            <section aria-labelledby="fin-ar-receipts-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-ar-receipts-h">{t('fin.ar.receipts')}</h2>
                {receivable.receipts.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.ar.noReceipts')}</p> : (
                    <div className="overflow-x-auto border border-border bg-surface">
                        <Table data-testid="receivable-receipts">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('fin.ar.receipt')}</TableHead>
                                    <TableHead>{t('fin.ar.entryKind')}</TableHead>
                                    <TableHead className="text-right">{t('fin.col.amount')}</TableHead>
                                    <TableHead>{t('fin.col.method')}</TableHead>
                                    <TableHead>{t('fin.ar.entryDate')}</TableHead>
                                    <TableHead>{t('fin.col.reference')}</TableHead>
                                    <TableHead>{t('fin.ar.noteOrReason')}</TableHead>
                                    <TableHead>{t('fin.ar.recordedBy')}</TableHead>
                                    {receivable.receipts.some((r) => r.may_reverse) ? <TableHead><span className="sr-only">{t('inv.col.actions')}</span></TableHead> : null}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {receivable.receipts.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>
                                            <span className="font-medium">{r.number}</span>
                                            {r.reverses_number !== null ? <span className="block text-xs text-muted-foreground">{t('fin.ar.takesBack', { number: r.reverses_number })}</span> : null}
                                            {r.reversal_number !== null ? <span className="block text-xs text-muted-foreground">{t('fin.ar.reversedBy', { number: r.reversal_number })}</span> : null}
                                        </TableCell>
                                        <TableCell><StatusBadge label={entryKind(r.kind)} tone={RECEIPT_KIND_TONE[r.kind] ?? 'neutral'} /></TableCell>
                                        <TableCell className="text-right">{money(r.kind === 'reversal' ? -r.amount_minor : r.amount_minor)}</TableCell>
                                        <TableCell>{r.method === null ? '—' : methodLabel(r.method)}</TableCell>
                                        <TableCell>{format.date(r.received_on)}</TableCell>
                                        <TableCell>{r.reference ?? '—'}</TableCell>
                                        <TableCell>{r.note ?? '—'}</TableCell>
                                        <TableCell>{r.by ?? '—'}</TableCell>
                                        {receivable.receipts.some((o) => o.may_reverse) ? (
                                            <TableCell className="text-right print:hidden">
                                                {r.may_reverse ? <Button onClick={() => openReverse(r)} size="sm" type="button" variant="outline">{t('fin.ar.reverse')}</Button> : null}
                                            </TableCell>
                                        ) : null}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                {isFolio && receivable.receipts.length > 0 ? <p className="text-sm text-muted-foreground">{t('fin.ar.reverseFolioNote')}</p> : null}
            </section>

            <section aria-labelledby="fin-ar-notes-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-ar-notes-h">{t('fin.ar.collection')}</h2>
                {timeline.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.ar.noNotes')}</p> : (
                    <ol className="flex flex-col gap-2" data-testid="receivable-notes">
                        {timeline.map((n) => (
                            <li className="flex flex-col gap-1.5 border border-border bg-surface p-3 text-sm" key={n.id}>
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <StatusBadge label={noteKind(n.kind)} tone={NOTE_TONE[n.kind] ?? 'neutral'} />
                                    <span className="text-xs text-muted-foreground">{format.instant(n.at)}{n.by === null ? '' : ` · ${n.by}`}</span>
                                </div>
                                <p className="break-words">{n.note}</p>
                                {n.promised_on !== null ? (
                                    <p className="text-xs text-muted-foreground">
                                        {n.promised_minor === null
                                            ? t('fin.ar.promiseOn', { date: format.date(n.promised_on) })
                                            : t('fin.ar.promiseOnAmount', { date: format.date(n.promised_on), amount: money(n.promised_minor) })}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                )}
            </section>

            <Dialog
                footer={<>
                    <Button disabled={receive.busy} onClick={() => setReceiptForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={receive.busy} onClick={() => void saveReceipt()} type="button">{t('fin.ar.record')}</Button>
                </>}
                onClose={() => setReceiptForm(null)}
                open={receiptForm !== null}
                title={t('fin.ar.recordTitle', { customer: receivable.customer_name })}
            >
                {receiptForm !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{isFolio ? t('fin.ar.recordHintFolio') : t('fin.ar.recordHint')}</p>
                        {receive.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={receive.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField
                            error={badAmount ? t('fo.folio.invalidAmount') : receive.fieldError('amount_minor')}
                            field="amount_minor"
                            hint={t('fin.ar.receiveMax', { amount: money(receivable.balance_minor) })}
                            label={t('fin.ar.amountField', { currency: receivable.currency })}
                        >
                            <Input inputMode="decimal" onChange={(e) => { setBadAmount(false); setReceiptForm({ ...receiptForm, amount: e.target.value }); }} value={receiptForm.amount} />
                        </FormField>
                        <FormField error={receive.fieldError('method')} field="method" label={t('fin.ar.methodField')}>
                            <Select onChange={(e) => setReceiptForm({ ...receiptForm, method: e.target.value })} searchable={false} value={receiptForm.method}>
                                {receivable.methods.map((m) => <option key={m} value={m}>{methodLabel(m)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={receive.fieldError('received_on')} field="received_on" hint={t('fin.ar.receivedOnHint')} label={t('fin.ar.receivedOn')}>
                            <DatePicker onChange={(e) => setReceiptForm({ ...receiptForm, received_on: e.target.value })} value={receiptForm.received_on} />
                        </FormField>
                        <FormField error={receive.fieldError('reference')} field="reference" hint={t('fin.ar.referenceNeeded')} label={t('fin.col.reference')}>
                            <Input maxLength={80} onChange={(e) => setReceiptForm({ ...receiptForm, reference: e.target.value })} value={receiptForm.reference} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={receive.fieldError('note')} field="note" label={t('fin.ar.note')}>
                                <Input maxLength={200} onChange={(e) => setReceiptForm({ ...receiptForm, note: e.target.value })} value={receiptForm.note} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={reverse.busy} onClick={() => setReversing(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={reverse.busy} onClick={() => void saveReversal()} type="button">{t('fin.ar.reverse')}</Button>
                </>}
                onClose={() => setReversing(null)}
                open={reversing !== null}
                title={t('fin.ar.reverseTitle', { number: reversing?.number ?? '' })}
            >
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">{t('fin.ar.reverseHint')}</p>
                    {reverse.error !== null ? <ErrorState {...errorCopy} error={reverse.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={reverse.fieldError('reason')} field="reason" label={t('fin.ar.reverseReason')}>
                        <Input maxLength={200} onChange={(e) => setReverseReason(e.target.value)} value={reverseReason} />
                    </FormField>
                </div>
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={adjust.busy} onClick={() => setAdjustForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={adjust.busy} onClick={() => void saveAdjustment()} type="button">{t('fin.ar.adjust')}</Button>
                </>}
                onClose={() => setAdjustForm(null)}
                open={adjustForm !== null}
                title={t('fin.ar.adjustTitle', { number: receivable.number })}
            >
                {adjustForm !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.ar.adjustHint')}</p>
                        {adjust.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={adjust.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <div className="sm:col-span-2">
                            <FormField error={adjust.fieldError('kind')} field="kind" hint={t(`fin.ar.adjustKindHint.${adjustForm.kind}` as MessageKey)} label={t('fin.ar.adjustKindField')}>
                                <Select onChange={(e) => setAdjustForm({ ...adjustForm, kind: e.target.value })} searchable={false} value={adjustForm.kind}>
                                    {receivable.adjust_kinds.map((k) => <option key={k} value={k}>{entryKind(k)}</option>)}
                                </Select>
                            </FormField>
                        </div>
                        <div className="sm:col-span-2">
                            <FormField
                                error={badAmount ? t('fo.folio.invalidAmount') : adjust.fieldError('amount_minor')}
                                field="amount_minor"
                                hint={t('fin.ar.receiveMax', { amount: money(receivable.balance_minor) })}
                                label={t('fin.ar.amountField', { currency: receivable.currency })}
                            >
                                <Input inputMode="decimal" onChange={(e) => { setBadAmount(false); setAdjustForm({ ...adjustForm, amount: e.target.value }); }} value={adjustForm.amount} />
                            </FormField>
                        </div>
                        <div className="sm:col-span-2">
                            <FormField error={adjust.fieldError('reason')} field="reason" label={t('fin.ar.adjustReason')}>
                                <Input maxLength={200} onChange={(e) => setAdjustForm({ ...adjustForm, reason: e.target.value })} value={adjustForm.reason} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={note.busy} onClick={() => setNoteForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={note.busy} onClick={() => void saveNote()} type="button">{t('fin.ar.saveNote')}</Button>
                </>}
                onClose={() => setNoteForm(null)}
                open={noteForm !== null}
                title={t('fin.ar.noteTitle', { customer: receivable.customer_name })}
            >
                {noteForm !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.ar.noteHint')}</p>
                        {note.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={note.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <div className="sm:col-span-2">
                            <FormField error={note.fieldError('kind')} field="kind" label={t('fin.ar.noteKindField')}>
                                <Select onChange={(e) => setNoteForm({ ...noteForm, kind: e.target.value })} searchable={false} value={noteForm.kind}>
                                    {receivable.note_kinds.map((k) => <option key={k} value={k}>{noteKind(k)}</option>)}
                                </Select>
                            </FormField>
                        </div>
                        <div className="sm:col-span-2">
                            <FormField error={note.fieldError('note')} field="note" label={t('fin.ar.noteText')}>
                                <Textarea maxLength={300} onChange={(e) => setNoteForm({ ...noteForm, note: e.target.value })} value={noteForm.note} />
                            </FormField>
                        </div>
                        <FormField error={note.fieldError('promised_on')} field="promised_on" hint={t('fin.ar.promisedOnHint')} label={t('fin.ar.promisedOnField')} required={promise}>
                            <DatePicker onChange={(e) => setNoteForm({ ...noteForm, promised_on: e.target.value })} value={noteForm.promised_on} />
                        </FormField>
                        <FormField error={badPromised ? t('fo.folio.invalidAmount') : note.fieldError('promised_minor')} field="promised_minor" hint={t('fin.ar.promisedAmountHint')} label={t('fin.ar.promisedAmount', { currency: receivable.currency })}>
                            <Input inputMode="decimal" onChange={(e) => { setBadPromised(false); setNoteForm({ ...noteForm, promised_minor: e.target.value }); }} value={noteForm.promised_minor} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
