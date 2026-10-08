import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import type { Shift } from '@/modules/front-office/components/shift-summary';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Props = { shift: Shift; currency: string; mayDrop: boolean; mayClose: boolean; reload: string[] };

/** Drop cash to the safe and close the shift. The server decides what is allowed; the form only helps to fill it in. */
export function ShiftActions({ currency, mayClose, mayDrop, reload, shift }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [drop, setDrop] = useState<{ amount: string; reference: string; note: string } | null>(null);
    const [close, setClose] = useState<{ counted: string; reason: string } | null>(null);
    const [amountError, setAmountError] = useState(false);
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const expected = shift.opening_float_minor + shift.cash_net_minor - shift.drops_minor;
    const counted = close === null ? null : parseMajorToMinor(close.counted, currency);
    const variance = counted === null ? null : counted - expected;

    function closeAll() {
        action.clear();
        setDrop(null);
        setClose(null);
        setAmountError(false);
    }

    async function saveDrop() {
        const amount = drop === null ? null : parseMajorToMinor(drop.amount, currency);
        if (drop === null || amount === null || amount < 1) { setAmountError(true); return; }
        setAmountError(false);
        const done = await action.run(`/front-office/cashier/shifts/${shift.id}/drops`, { idempotencyKey: intent, body: { amount_minor: amount, reference: drop.reference.trim() || null, note: drop.note.trim() || null }, reload });
        if (done !== null) { setIntent(newIdempotencyKey()); closeAll(); }
    }

    async function saveClose() {
        if (close === null || counted === null) { setAmountError(true); return; }
        setAmountError(false);
        const done = await action.run(`/front-office/cashier/shifts/${shift.id}/close`, { body: { counted_cash_minor: counted, variance_reason: close.reason.trim() || null, lock_version: shift.lock_version }, reload });
        if (done !== null) {
            closeAll();
            router.visit(`/front-office/cashier/shifts/${shift.id}`);
        }
    }

    const error = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    return (
        <>
            <div className="flex flex-wrap gap-2 print:hidden">
                {mayDrop ? <Button onClick={() => { action.clear(); setDrop({ amount: '', reference: '', note: '' }); }} size="sm" type="button" variant="outline">{t('fo.cash.drop.add')}</Button> : null}
                {mayClose ? <Button onClick={() => { action.clear(); setClose({ counted: '', reason: '' }); }} size="sm" type="button">{t('fo.cash.close.action')}</Button> : null}
            </div>

            <Dialog footer={<><Button disabled={action.busy} onClick={closeAll} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveDrop()} type="button">{t('fo.cash.drop.save')}</Button></>} onClose={closeAll} open={drop !== null} title={t('fo.cash.drop.add')}>
                {drop !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        <FormField field="amount_minor" error={amountError ? t('fo.cash.invalidAmount') : (action.fieldError('amount_minor') ?? action.fieldError('amount'))} label={t('fo.cash.drop.amount')}><MoneyInput onChange={(e) => setDrop({ ...drop, amount: e.target.value })} value={drop.amount} /></FormField>
                        <FormField field="reference" error={action.fieldError('reference')} label={t('fo.cash.drop.reference')}><Input maxLength={80} onChange={(e) => setDrop({ ...drop, reference: e.target.value })} value={drop.reference} /></FormField>
                        <FormField field="note" error={action.fieldError('note')} label={t('fo.cash.drop.note')}><Input maxLength={300} onChange={(e) => setDrop({ ...drop, note: e.target.value })} value={drop.note} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog footer={<><Button disabled={action.busy} onClick={closeAll} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void saveClose()} type="button">{t('fo.cash.close.action')}</Button></>} onClose={closeAll} open={close !== null} title={t('fo.cash.close.title')}>
                {close !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        <p className="text-sm text-muted-foreground">{t('fo.cash.close.consequence')}</p>
                        <FormField field="counted_cash_minor" error={amountError ? t('fo.cash.invalidAmount') : (action.fieldError('counted_cash_minor') ?? action.fieldError('counted_cash'))} hint={t('fo.cash.countedHint')} label={t('fo.cash.counted')}><MoneyInput onChange={(e) => setClose({ ...close, counted: e.target.value })} value={close.counted} /></FormField>
                        {variance !== null ? <p className="text-sm font-medium" data-testid="variance-preview">{variance === 0 ? t('fo.cash.varianceNone') : t('fo.cash.varianceNow', { amount: format.money(variance, currency) })}</p> : null}
                        {variance !== null && variance !== 0 ? (
                            <FormField field="variance_reason" error={action.fieldError('variance_reason')} hint={t('fo.cash.varianceNeedsReason')} label={t('fo.cash.varianceReason')}><Input maxLength={300} onChange={(e) => setClose({ ...close, reason: e.target.value })} value={close.reason} /></FormField>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </>
    );
}
