import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

export type RateChange = { id: string; effective_from: string; old_total_minor: number; new_total_minor: number; discount_minor: number; reason: string; approval_id: string | null; business_date: string };
export type RateApproval = { id: string; status: string; consumed: boolean; payload: Record<string, unknown> };
export type Rates = { changes: RateChange[]; approvals: RateApproval[]; may_change: boolean; business_date: string };
type Preview = {
    from: string; currency: string; nights: { date: string; old_total_minor: number; new_total_minor: number }[];
    old_total_minor: number; new_total_minor: number; discount_minor: number; discount_bp: number; approval_required: boolean;
};

/** The price changes of a booked reservation and the form to make one (FR-FO-013). The server decides; this shows the effect first. */
export function RateChangePanel({ currency, reservationId, rates }: { currency: string; reservationId: string; rates: Rates }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ price: string; nett: boolean; from: string; reason: string } | null>(null);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [priceError, setPriceError] = useState(false);
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const money = (v: number) => format.money(v, currency);
    const price = form === null ? null : parseMajorToMinor(form.price, currency);

    const match = (a: RateApproval) => preview !== null && form !== null && price !== null
        && a.payload.from === preview.from && a.payload.new_nightly_minor === price && a.payload.nett === form.nett && a.payload.discount_minor === preview.discount_minor;
    const approved = rates.approvals.find((a) => match(a) && a.status === 'approved' && !a.consumed);
    const pending = rates.approvals.find((a) => match(a) && a.status === 'pending');

    function close() {
        action.clear();
        setForm(null);
        setPreview(null);
        setPriceError(false);
    }

    async function check() {
        if (form === null || price === null) { setPriceError(true); return; }
        setPriceError(false);
        setPreview(null);
        const q = new URLSearchParams({ price_minor: String(price), nett: form.nett ? '1' : '0', from: form.from });
        const done = await action.run<{ preview: Preview }>(`/front-office/reservations/${reservationId}/rate-preview?${q.toString()}`, { method: 'GET' });
        if (done !== null) setPreview(done.preview);
    }

    async function requestApproval() {
        if (form === null || price === null) return;
        await action.run(`/front-office/reservations/${reservationId}/rate-approval`, { idempotencyKey: intent, body: { price_minor: price, nett: form.nett, from: form.from, reason: form.reason.trim() }, reload: ['rates'] });
    }

    async function apply() {
        if (form === null || price === null) return;
        const done = await action.run(`/front-office/reservations/${reservationId}/rate`, { body: { price_minor: price, nett: form.nett, from: form.from, reason: form.reason.trim(), approval_id: approved?.id ?? null }, reload: ['rates', 'reservation'] });
        if (done !== null) { setIntent(newIdempotencyKey()); close(); }
    }

    const needsApproval = preview?.approval_required === true && approved === undefined;

    return (
        <section aria-labelledby="rate-h" className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold" id="rate-h">{t('fo.rate.title')}</h2>
                {rates.may_change ? <Button onClick={() => { action.clear(); setForm({ price: '', nett: false, from: rates.business_date, reason: '' }); }} size="sm" type="button" variant="outline">{t('fo.rate.change')}</Button> : null}
            </div>
            {rates.changes.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.rate.none')}</p> : (
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="rate-changes">{rates.changes.map((c) => (
                    <li className="flex flex-col gap-0.5 py-2" key={c.id}>
                        <span>{t('fo.rate.history', { date: format.date(c.business_date), from: format.date(c.effective_from), old: money(c.old_total_minor), new: money(c.new_total_minor) })}</span>
                        <span className="text-xs text-muted-foreground">{t('fo.rate.historyReason', { reason: c.reason })}{c.approval_id !== null ? ` · ${t('fo.rate.approvedBy')}` : ''}</span>
                    </li>
                ))}</ul>
            )}

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={close} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button disabled={action.busy} onClick={() => void check()} type="button" variant="outline">{t('fo.rate.check')}</Button>
                    {preview !== null && needsApproval && pending === undefined ? <Button loading={action.busy} onClick={() => void requestApproval()} type="button">{t('fo.rate.requestApproval')}</Button> : null}
                    {preview !== null && !needsApproval ? <Button loading={action.busy} onClick={() => void apply()} type="button">{t('fo.rate.apply')}</Button> : null}
                </>}
                onClose={close} open={form !== null} title={t('fo.rate.dialogTitle')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-xs text-muted-foreground">{t('fo.rate.note')}</p>
                        <FormField field="price_minor" error={priceError ? t('fo.rate.invalidPrice') : (action.fieldError('price_minor') ?? action.fieldError('price'))} hint={t('fo.rate.priceHint')} label={t('fo.rate.price')}><Input inputMode="decimal" onChange={(e) => { setForm({ ...form, price: e.target.value }); setPreview(null); }} value={form.price} /></FormField>
                        <fieldset className="flex flex-col gap-1 text-sm">
                            <label className="flex items-center gap-2"><input checked={!form.nett} name="rate-nett" onChange={() => { setForm({ ...form, nett: false }); setPreview(null); }} type="radio" />{t('fo.rate.plusPlus')}</label>
                            <label className="flex items-center gap-2"><input checked={form.nett} name="rate-nett" onChange={() => { setForm({ ...form, nett: true }); setPreview(null); }} type="radio" />{t('fo.rate.nett')}</label>
                        </fieldset>
                        <FormField field="from" error={action.fieldError('from')} label={t('fo.rate.from')}><DatePicker onChange={(e) => { setForm({ ...form, from: e.target.value }); setPreview(null); }} value={form.from} /></FormField>
                        <FormField field="reason" error={action.fieldError('reason')} label={t('fo.rate.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} /></FormField>
                        {preview !== null && (
                            <div className="flex flex-col gap-1 text-sm" data-testid="rate-preview">
                                <ul>{preview.nights.map((n) => <li key={n.date}>{t('fo.rate.nightRow', { date: format.date(n.date), old: money(n.old_total_minor), new: money(n.new_total_minor) })}</li>)}</ul>
                                <p className="font-medium">{t('fo.rate.total', { old: money(preview.old_total_minor), new: money(preview.new_total_minor) })}</p>
                                <p>{preview.discount_minor > 0 ? t('fo.rate.discount', { amount: money(preview.discount_minor), percent: (preview.discount_bp / 100).toFixed(1) }) : t('fo.rate.increase', { amount: money(-preview.discount_minor) })}</p>
                                {preview.approval_required ? <Alert title={approved !== undefined ? t('fo.rate.approved') : pending !== undefined ? t('fo.rate.requested') : t('fo.rate.approvalNeeded')} tone={approved !== undefined ? 'success' : 'warning'} /> : null}
                            </div>
                        )}
                    </div>
                )}
            </Dialog>
        </section>
    );
}
