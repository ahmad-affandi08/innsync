import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { LaundryShell } from '@/modules/laundry/components/laundry-shell';
import { statusTone } from '@/modules/laundry/pages/queue';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Line = { id: string; item_name: string; brand: string | null; quantity: number; verified_quantity: number | null; unit_price_minor: number; treatment_name: string | null; treatment_extra_minor: number; express_extra_minor: number; piece_minor: number; condition_note: string | null; total_minor: number };
type Order = {
    id: string; number: string; barcode: string; room_number: string | null; status: string; express: boolean; promised_at: string; overdue: boolean; pickup_date: string; notes: string | null;
    has_discrepancy: boolean; discrepancy_note: string | null; charged_minor: number | null; billable_minor: number; lock_version: number; lines: Line[];
    history: { from: string | null; to: string; occurred_at: string }[];
};
type May = { process: boolean; deliver: boolean; cancel: boolean };

const NEXT: Record<string, string> = { received: 'washing', washing: 'drying', drying: 'ironing' };

export default function LaundryOrderPage({ currency, may, order: o }: { currency: string; may: May; order: Order }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['order'];
    const [counts, setCounts] = useState<Record<string, string>>(() => Object.fromEntries(o.lines.map((l) => [l.id, String(l.quantity)])));
    const [note, setNote] = useState('');
    const [dialog, setDialog] = useState<'ready' | 'deliver' | 'cancel' | null>(null);
    const [text, setText] = useState('');
    const [saved, setSaved] = useState(false);
    const close = () => { setDialog(null); setText(''); action.clear(); };
    const post = async (path: string, body: Record<string, unknown>) => {
        const done = await action.run(`/laundry/orders/${o.id}/${path}`, { body: { ...body, lock_version: o.lock_version }, reload });
        if (done !== null) { setSaved(true); close(); }
        return done;
    };

    return (
        <LaundryShell description={t('ldy.order.description', { room: o.room_number ?? '', barcode: o.barcode })} title={t('ldy.order.title', { number: o.number })} wide>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button asChild size="sm" variant="outline"><Link href="/laundry">{t('ldy.order.back')}</Link></Button>
                <div className="flex flex-wrap items-center gap-2">
                    {o.express ? <StatusBadge label={t('ldy.flag.express')} tone="warning" /> : null}
                    {o.overdue ? <StatusBadge label={t('ldy.flag.overdue')} tone="danger" /> : null}
                    <StatusBadge label={t(`ldy.status.${o.status}` as 'ldy.status.sent')} tone={statusTone[o.status] ?? 'neutral'} />
                </div>
            </div>
            {saved ? <Alert title={t('ldy.order.saved')} tone="success" /> : null}
            {action.error !== null && dialog === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {o.has_discrepancy ? <Alert title={t('ldy.order.discrepancy', { note: o.discrepancy_note ?? '' })} tone="warning" /> : null}

            <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
                <dt className="text-muted-foreground">{t('ldy.order.promised')}</dt><dd>{format.instant(o.promised_at)}</dd>
                <dt className="text-muted-foreground">{t('ldy.order.pickup')}</dt><dd>{format.date(o.pickup_date, 'long')}</dd>
            </dl>

            <section aria-labelledby="lines-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="lines-h">{t('ldy.order.items')}</h2>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('ldy.order.col.item')}</th><th scope="col">{t('ldy.order.col.listed')}</th><th scope="col">{t('ldy.order.col.counted')}</th><th scope="col">{t('ldy.order.col.price')}</th><th scope="col">{t('ldy.order.col.total')}</th></tr></thead>
                        <tbody>{o.lines.map((l) => (
                            <tr className="border-t border-border align-top" key={l.id}>
                                <th className="py-2 font-medium" scope="row">{l.item_name}{l.brand !== null ? ` · ${l.brand}` : ''}{l.treatment_name !== null ? <span className="block text-xs font-normal text-muted-foreground">{t('ldy.order.treatment', { name: l.treatment_name, extra: format.money(l.treatment_extra_minor, currency) })}</span> : null}{l.express_extra_minor > 0 ? <span className="block text-xs font-normal text-muted-foreground">{t('ldy.order.expressExtra', { extra: format.money(l.express_extra_minor, currency) })}</span> : null}{l.condition_note !== null ? <span className="block text-xs font-normal text-muted-foreground">{l.condition_note}</span> : null}</th>
                                <td>{l.quantity}</td>
                                <td>{o.status === 'sent' && may.process
                                    ? <Input aria-label={`${t('ldy.order.col.counted')}: ${l.item_name}`} className="min-h-9 w-20" inputMode="numeric" onChange={(e) => setCounts({ ...counts, [l.id]: e.target.value })} value={counts[l.id] ?? ''} />
                                    : (l.verified_quantity ?? '—')}</td>
                                <td>{format.money(l.piece_minor, currency)}</td>
                                <td>{format.money(l.total_minor, currency)}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
                <p className="text-sm">{o.charged_minor !== null ? t('ldy.order.charged', { amount: format.money(o.charged_minor, currency) }) : `${t('ldy.order.billable')}: ${format.money(o.billable_minor, currency)}`}</p>
            </section>

            {o.status === 'sent' && may.process && (
                <section className="flex flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold">{t('ldy.order.count')}</h2>
                    <FormField error={action.fieldError('note')} label={t('ldy.order.countNote')}><Input maxLength={500} onChange={(e) => setNote(e.target.value)} value={note} /></FormField>
                    <div><Button disabled={action.busy} onClick={() => void post('receive', { counts: Object.fromEntries(Object.entries(counts).map(([k, v]) => [k, Number(v)])), note: note || null })} type="button">{t('ldy.order.countSave')}</Button></div>
                </section>
            )}

            <div className="flex flex-wrap gap-2">
                {may.process && NEXT[o.status] !== undefined ? <Button disabled={action.busy} onClick={() => void post('advance', {})} type="button">{t('ldy.order.advanceTo', { step: t(`ldy.status.${NEXT[o.status]}` as 'ldy.status.washing') })}</Button> : null}
                {may.process && o.status === 'ironing' ? <Button onClick={() => { action.clear(); setDialog('ready'); }} type="button">{t('ldy.order.ready')}</Button> : null}
                {may.deliver && o.status === 'ready' ? <Button onClick={() => { action.clear(); setDialog('deliver'); }} type="button">{t('ldy.order.deliver')}</Button> : null}
                {may.cancel && (o.status === 'sent' || o.status === 'received') ? <Button onClick={() => { action.clear(); setDialog('cancel'); }} type="button" variant="outline">{t('ldy.order.cancel')}</Button> : null}
            </div>

            <section aria-labelledby="hist-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="hist-h">{t('ldy.order.history')}</h2>
                <ul className="divide-y divide-border border-y border-border text-sm">{o.history.map((h, i) => <li className="flex justify-between gap-2 py-2" key={i}><span>{t(`ldy.status.${h.to}` as 'ldy.status.sent')}</span><span className="text-xs text-muted-foreground">{format.instant(h.occurred_at.replace(' ', 'T') + 'Z')}</span></li>)}</ul>
            </section>

            <ConfirmDialog
                cancelLabel={t('ldy.order.back2')}
                confirmLabel={dialog === 'ready' ? t('ldy.order.ready') : dialog === 'deliver' ? t('ldy.order.deliverSave') : t('ldy.order.cancel')}
                consequence={dialog === 'ready' ? t('ldy.order.readyConsequence') : dialog === 'cancel' ? t('ldy.order.cancelConsequence') : ''}
                destructive={dialog !== 'deliver'}
                onCancel={close}
                onConfirm={() => void (dialog === 'ready' ? post('ready', {}) : dialog === 'deliver' ? post('deliver', { receipt: text }) : post('cancel', { reason: text }))}
                open={dialog !== null}
                pending={action.busy}
                title={dialog === 'deliver' ? t('ldy.order.deliver') : dialog === 'cancel' ? t('ldy.order.cancel') : t('ldy.order.ready')}
            >
                <div className="flex flex-col gap-3">
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    {dialog === 'deliver' ? <FormField error={action.fieldError('receipt')} label={t('ldy.order.receipt')}><Input maxLength={200} onChange={(e) => setText(e.target.value)} value={text} /></FormField> : null}
                    {dialog === 'cancel' ? <FormField error={action.fieldError('reason')} label={t('ldy.order.cancelReason')}><Input maxLength={300} onChange={(e) => setText(e.target.value)} value={text} /></FormField> : null}
                </div>
            </ConfirmDialog>
        </LaundryShell>
    );
}
