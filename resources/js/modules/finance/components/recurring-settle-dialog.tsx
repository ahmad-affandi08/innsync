import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { minorToMajorText, RecurringDifference, REFERENCE_METHODS, type RecurringItem } from '@/modules/finance/lib/finance';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Props = {
    /** The expense whose next due date is settled; null keeps the dialog closed. Give the dialog a `key` per expense so it starts fresh. */
    item: RecurringItem | null;
    currency: string;
    methods: readonly string[];
    today: string;
    /** Inertia props to reload once the due date is settled. */
    reload: string[];
    onClose: () => void;
};

type Form = { action: 'paid' | 'skipped'; amount: string; paid_on: string; method: string; reference: string; note: string };

/** Settles the next due date of a recurring expense as paid (amount, date, method) or skipped (with the reason). One idempotency key per intent. */
export function RecurringSettleDialog({ currency, item, methods, onClose, reload, today }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<Form>(() => ({ action: 'paid', amount: item === null ? '' : minorToMajorText(item.amount_minor, currency), paid_on: today, method: '', reference: '', note: '' }));
    const [badAmount, setBadAmount] = useState(false);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(form)]);
    const paid = form.action === 'paid';
    const typed = paid ? parseMajorToMinor(form.amount, currency) : null;

    async function save() {
        if (item === null) return;
        const invalid = paid && (typed === null || typed < 1);

        setBadAmount(invalid);
        if (invalid) return;
        const done = await action.run(`/finance/recurring/${item.id}/settle`, {
            body: {
                action: form.action, amount_minor: paid ? typed : null, paid_on: paid ? form.paid_on || null : null, method: paid ? form.method || null : null,
                reference: paid ? form.reference.trim() || null : null, note: form.note.trim() || null,
            },
            idempotencyKey: intent,
            reload,
        });
        if (done !== null) onClose();
    }

    return (
        <Dialog
            footer={<>
                <Button disabled={action.busy} onClick={onClose} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                <Button loading={action.busy} onClick={() => void save()} type="button">{paid ? t('fin.rec.settle.savePaid') : t('fin.rec.settle.saveSkipped')}</Button>
            </>}
            onClose={onClose}
            open={item !== null}
            title={t('fin.rec.settle.title', { name: item?.name ?? '' })}
        >
            {item !== null && (
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">
                        {t('fin.rec.settle.hint', { date: item.next_due === null ? '—' : format.date(item.next_due), amount: format.money(item.amount_minor, currency) })}
                    </p>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={action.fieldError('action')} field="action" label={t('fin.rec.settle.action')}>
                        <RadioGroup className="grid-cols-2" onValueChange={(next) => setForm({ ...form, action: next === 'skipped' ? 'skipped' : 'paid' })} value={form.action}>
                            {(['paid', 'skipped'] as const).map((a) => (
                                <label className="flex min-h-11 items-center gap-2 border border-input px-3 text-sm" key={a}>
                                    <RadioGroupItem value={a} />
                                    {t(`fin.rec.settle.${a}` as MessageKey)}
                                </label>
                            ))}
                        </RadioGroup>
                    </FormField>
                    {paid ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField
                                error={badAmount ? t('fin.petty.badAmount') : action.fieldError('amount_minor')}
                                field="amount_minor"
                                hint={t('fin.rec.settle.amountHint')}
                                label={t('fin.rec.settle.amount', { currency })}
                            >
                                <MoneyInput onChange={(e) => { setBadAmount(false); setForm({ ...form, amount: e.target.value }); }} value={form.amount} />
                            </FormField>
                            <FormField error={action.fieldError('paid_on')} field="paid_on" hint={t('fin.rec.settle.paidOnHint')} label={t('fin.rec.settle.paidOn')}>
                                <DatePicker max={today} min={`${item.start_month}-01`} onChange={(e) => setForm({ ...form, paid_on: e.target.value })} value={form.paid_on} />
                            </FormField>
                            {typed !== null && typed > 0 ? <div aria-live="polite" className="sm:col-span-2"><RecurringDifference currency={currency} minor={typed - item.amount_minor} /></div> : null}
                            <FormField error={action.fieldError('method')} field="method" label={t('fin.rec.settle.method')} required>
                                <Select onChange={(e) => setForm({ ...form, method: e.target.value })} searchable={false} value={form.method}>
                                    <option value="">{t('fin.rec.settle.chooseMethod')}</option>
                                    {methods.map((m) => <option key={m} value={m}>{t(`fin.method.${m}` as MessageKey)}</option>)}
                                </Select>
                            </FormField>
                            <FormField error={action.fieldError('reference')} field="reference" hint={t('fin.rec.settle.referenceHint')} label={t('fin.rec.settle.reference')} required={REFERENCE_METHODS.includes(form.method)}>
                                <Input maxLength={60} onChange={(e) => setForm({ ...form, reference: e.target.value })} value={form.reference} />
                            </FormField>
                            <div className="sm:col-span-2">
                                <FormField error={action.fieldError('note')} field="note" label={t('fin.rec.settle.note')}>
                                    <Input maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                                </FormField>
                            </div>
                        </div>
                    ) : (
                        <FormField error={action.fieldError('note')} field="note" hint={t('fin.rec.settle.skipHint')} label={t('fin.rec.settle.skipNote')} required>
                            <Textarea maxLength={200} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                        </FormField>
                    )}
                </div>
            )}
        </Dialog>
    );
}
