import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { LaundryShell } from '@/modules/laundry/components/laundry-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Treatment = { id: string; name: string; kind: string; pricing: string; value: number };
type Lookups = { rooms: { id: string; number: string }[]; items: { id: string; code: string; name: string; unit_price_minor: number }[]; treatments: Treatment[]; business_date: string; zone: string };
/** The extra for one piece, as the server works it out: a percentage is rounded half up. */
const extra = (tr: Treatment | undefined, unit: number) => (tr === undefined ? 0 : tr.pricing === 'percent' ? Math.floor((unit * tr.value + 5000) / 10000) : tr.value);
type Line = { itemId: string; quantity: string; brand: string; condition: string; treatmentId: string };

const blank = (): Line => ({ itemId: '', quantity: '1', brand: '', condition: '', treatmentId: '' });

/** The housekeeping hand-over screen: a bag tag, a room with a guest, what is in the bag and when it was promised back. */
export default function LaundryIntakePage({ currency, lookups }: { currency: string; lookups: Lookups }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const [form, setForm] = useState({ roomId: lookups.rooms[0]?.id ?? '', barcode: '', express: false, date: '', time: '', notes: '' });
    const [lines, setLines] = useState<Line[]>([blank()]);
    const [done, setDone] = useState<{ id: string; number: string } | null>(null);
    const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }));
    const setLine = (i: number, patch: Partial<Line>) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const expressTreatment = lookups.treatments.find((x) => x.kind === 'express');
    const estimate = lines.reduce((sum, l) => {
        const unit = lookups.items.find((x) => x.id === l.itemId)?.unit_price_minor ?? 0;
        return sum + (unit + extra(lookups.treatments.find((x) => x.id === l.treatmentId), unit) + (form.express ? extra(expressTreatment, unit) : 0)) * (Number(l.quantity) || 0);
    }, 0);

    async function submit() {
        const result = await action.run<{ order: { id: string; number: string } }>('/laundry/orders', {
            idempotencyKey: intent,
            body: {
                barcode: form.barcode, room_id: form.roomId, express: form.express, promised_date: form.date, promised_time: form.time, notes: form.notes || null,
                lines: lines.filter((l) => l.itemId !== '').map((l) => ({ price_item_id: l.itemId, quantity: Number(l.quantity), brand: l.brand || null, condition_note: l.condition || null, treatment_id: l.treatmentId || null })),
            },
        });
        if (result !== null) {
            setIntent(newIdempotencyKey());
            setDone({ id: result.order.id, number: result.order.number });
        }
    }

    if (done !== null) {
        return (
            <LaundryShell description={t('ldy.intake.description')} title={t('ldy.intake.title')}>
                <Alert title={t('ldy.intake.done', { number: done.number })} tone="success" />
                <div className="flex gap-2"><Button asChild variant="outline"><Link href={`/laundry/orders/${done.id}`}>{done.number}</Link></Button><Button asChild><a href="/laundry/new">{t('ldy.intake.another')}</a></Button></div>
            </LaundryShell>
        );
    }

    return (
        <LaundryShell description={t('ldy.intake.description')} title={t('ldy.intake.title')}>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {lookups.items.length === 0 ? <Alert title={t('ldy.intake.noItems')} tone="warning" /> : null}
            <form className="grid gap-4 sm:grid-cols-2" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
                <FormField field="room_id" error={action.fieldError('room_id')} hint={lookups.rooms.length === 0 ? t('ldy.intake.noRooms') : undefined} label={t('ldy.intake.room')}>
                    <Select onChange={(e) => set({ roomId: e.target.value })} value={form.roomId}>{lookups.rooms.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}</Select>
                </FormField>
                <FormField field="barcode" error={action.fieldError('barcode')} label={t('ldy.intake.barcode')}><Input autoComplete="off" maxLength={40} onChange={(e) => set({ barcode: e.target.value })} required value={form.barcode} /></FormField>
                <FormField field="promised_date" error={action.fieldError('promised_date')} label={t('ldy.intake.promisedDate')}><DatePicker min={lookups.business_date} onChange={(e) => set({ date: e.target.value })} required value={form.date} /></FormField>
                <FormField field="promised_time" error={action.fieldError('promised_time')} label={t('ldy.intake.promisedTime', { zone: lookups.zone })}><Input onChange={(e) => set({ time: e.target.value })} required type="time" value={form.time} /></FormField>
                <label className="flex items-center gap-2 text-sm sm:col-span-2"><input checked={form.express} onChange={(e) => set({ express: e.target.checked })} type="checkbox" />{t('ldy.intake.express')}{expressTreatment !== undefined ? ` (${expressTreatment.pricing === 'percent' ? `+${expressTreatment.value / 100}%` : `+${format.money(expressTreatment.value, currency)}`})` : ''}</label>

                <fieldset className="flex flex-col gap-3 sm:col-span-2">
                    <legend className="text-lg font-semibold">{t('ldy.intake.items')}</legend>
                    {lines.map((l, i) => (
                        <div className="grid gap-3 border border-border p-3 sm:grid-cols-4" key={i}>
                            <FormField field="lines" error={i === 0 ? action.fieldError('lines') : undefined} label={t('ldy.intake.item')}>
                                <Select onChange={(e) => setLine(i, { itemId: e.target.value })} value={l.itemId}><option value="">{t('ldy.intake.choose')}</option>{lookups.items.map((x) => <option key={x.id} value={x.id}>{x.name} · {format.money(x.unit_price_minor, currency)}</option>)}</Select>
                            </FormField>
                            <FormField field="lines.*.quantity" label={t('ldy.intake.quantity')}><Input min={1} onChange={(e) => setLine(i, { quantity: e.target.value })} type="number" value={l.quantity} /></FormField>
                            <FormField field="lines.*.brand" label={t('ldy.intake.brand')}><Input maxLength={60} onChange={(e) => setLine(i, { brand: e.target.value })} value={l.brand} /></FormField>
                            <FormField field="lines.*.condition_note" label={t('ldy.intake.condition')}><Input maxLength={200} onChange={(e) => setLine(i, { condition: e.target.value })} value={l.condition} /></FormField>
                            {lookups.treatments.some((x) => x.kind === 'service') ? (
                                <FormField field="lines.*.treatment_id" label={t('ldy.intake.treatment')}>
                                    <Select onChange={(e) => setLine(i, { treatmentId: e.target.value })} value={l.treatmentId}><option value="">{t('ldy.intake.noTreatment')}</option>{lookups.treatments.filter((x) => x.kind === 'service').map((x) => <option key={x.id} value={x.id}>{x.name} · {x.pricing === 'percent' ? `+${x.value / 100}%` : `+${format.money(x.value, currency)}`}</option>)}</Select>
                                </FormField>
                            ) : null}
                            {lines.length > 1 ? <div><Button onClick={() => setLines((ls) => ls.filter((_, j) => j !== i))} size="sm" type="button" variant="outline">{t('ldy.intake.removeLine')}</Button></div> : null}
                        </div>
                    ))}
                    <div className="flex flex-wrap items-center gap-3">
                        <Button onClick={() => setLines((ls) => [...ls, blank()])} size="sm" type="button" variant="outline">{t('ldy.intake.addLine')}</Button>
                        <span className="text-sm text-muted-foreground">{t('ldy.intake.estimate', { amount: format.money(estimate, currency) })}</span>
                    </div>
                </fieldset>

                <div className="sm:col-span-2"><FormField field="notes" error={action.fieldError('notes')} label={t('ldy.intake.notes')}><Textarea maxLength={500} onChange={(e) => set({ notes: e.target.value })} rows={2} value={form.notes} /></FormField></div>
                <div className="sm:col-span-2"><Button disabled={form.roomId === '' || lines.every((l) => l.itemId === '')} loading={action.busy} type="submit">{t('ldy.intake.submit')}</Button></div>
            </form>
        </LaundryShell>
    );
}
