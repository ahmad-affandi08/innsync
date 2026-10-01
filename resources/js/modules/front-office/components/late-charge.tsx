import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import { router } from '@inertiajs/react';

/** A charge found after the folio was closed (FR-FO-038): it goes to a linked folio and the closed one stays as it was. */
export function LateChargeButton({ currency, folioId }: { currency: string; folioId: string }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ code: string; description: string; amount: string; nett: boolean; reason: string } | null>(null);
    const [amountError, setAmountError] = useState(false);
    const [intent] = useState(() => newIdempotencyKey());

    function close() {
        action.clear();
        setForm(null);
        setAmountError(false);
    }

    async function save() {
        if (form === null) return;
        const minor = parseMajorToMinor(form.amount, currency);
        setAmountError(minor === null);
        if (minor === null) return;
        const done = await action.run<{ folio_id: string }>(`/front-office/folios/${folioId}/late-charges`, {
            idempotencyKey: intent, body: { code: form.code, description: form.description, amount_minor: minor, prices_include_charges: form.nett, reason: form.reason.trim() },
        });
        if (done !== null) { close(); router.visit(`/front-office/folios/${done.folio_id}`); }
    }

    return (
        <>
            <Button onClick={() => { action.clear(); setForm({ code: '', description: '', amount: '', nett: false, reason: '' }); }} size="sm" type="button">{t('fo.late.action')}</Button>
            <Dialog
                footer={<><Button disabled={action.busy} onClick={close} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void save()} type="button">{t('fo.late.save')}</Button></>}
                onClose={close} open={form !== null} title={t('fo.late.title')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-xs text-muted-foreground">{t('fo.late.note')}</p>
                        <FormField error={action.fieldError('code')} hint={t('fo.folio.chargeCodeHint')} label={t('fo.folio.chargeCode')}><Input maxLength={20} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} value={form.code} /></FormField>
                        <FormField error={action.fieldError('description')} label={t('fo.folio.chargeDescription')}><Input maxLength={160} onChange={(e) => setForm({ ...form, description: e.target.value })} value={form.description} /></FormField>
                        <FormField error={amountError ? t('fo.folio.invalidAmount') : action.fieldError('amount')} hint={t('fo.folio.chargeAmountHint')} label={t('fo.folio.chargeAmount')}><Input inputMode="decimal" onChange={(e) => setForm({ ...form, amount: e.target.value })} value={form.amount} /></FormField>
                        <fieldset className="flex flex-col gap-1 text-sm">
                            <label className="flex items-center gap-2"><input checked={!form.nett} name="late-nett" onChange={() => setForm({ ...form, nett: false })} type="radio" />{t('fo.folio.plusPlus')}</label>
                            <label className="flex items-center gap-2"><input checked={form.nett} name="late-nett" onChange={() => setForm({ ...form, nett: true })} type="radio" />{t('fo.folio.nett')}</label>
                        </fieldset>
                        <FormField error={action.fieldError('reason')} label={t('fo.late.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </>
    );
}
