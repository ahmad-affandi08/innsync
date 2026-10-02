import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli, plainMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Line = {
    id: string; item_code: string; item_name: string; base_unit: string; units: string[]; snapshot_milli: number | null; counted_unit: string | null; counted_unit_qty_milli: number | null; counted_milli: number | null;
    variance_milli: number | null; moved_since_milli: number | null; reason_code: string | null; note: string | null; value_minor: number | null; value_is_estimate: boolean;
};
type Count = {
    id: string; number: string; kind: string; status: string; note: string | null; decision_note: string | null; scheduled_for: string | null; business_date: string; location: { code: string; name: string };
    started_by_name: string | null; submitted_by_name: string | null; decided_by_name: string | null; started_at: string | null; submitted_at: string | null; decided_at: string | null; lock_version: number;
    currency: string; blind: boolean; reasons: string[]; lines: Line[]; gain_minor: number | null; loss_minor: number | null; net_minor: number | null;
    may_count: boolean; may_submit: boolean; may_cancel: boolean; may_review: boolean; review_blocked_self: boolean;
};
type Entry = { unit: string; quantity: string; reason: string; note: string };

const tone: Record<string, StatusTone> = { counting: 'info', submitted: 'pending', approved: 'success', cancelled: 'neutral' };

const entriesOf = (count: Count): Record<string, Entry> => Object.fromEntries(count.lines.map((l) => [l.id, { unit: l.counted_unit ?? l.base_unit, quantity: l.counted_unit_qty_milli === null ? '' : plainMilli(l.counted_unit_qty_milli), reason: l.reason_code ?? '', note: l.note ?? '' }]));

/** The count sheet while it is counted, and the minutes (berita acara) once it is handed in. */
export default function CountPage({ count }: { count: Count }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [entries, setEntries] = useState<Record<string, Entry>>(() => entriesOf(count));
    const [dialog, setDialog] = useState<'back' | 'cancel' | 'approve' | null>(null);
    const [note, setNote] = useState('');
    const [saved, setSaved] = useState(false);
    const reload = ['count'];
    const qty = (n: number) => formatMilli(n, locale);
    const money = (minor: number) => format.money(minor, count.currency);
    const label = (s: string) => t(`inv.cnt.status.${s}` as MessageKey);
    const counting = count.may_count;

    useEffect(() => { setEntries(entriesOf(count)); }, [count.lock_version, count.status]);

    const set = (id: string, patch: Partial<Entry>) => setEntries((e) => ({ ...e, [id]: { ...e[id], ...patch } }));
    const body = () => ({ lock_version: count.lock_version, lines: count.lines.map((l) => ({ line_id: l.id, unit: entries[l.id]?.unit || null, quantity: entries[l.id]?.quantity ?? '', reason_code: entries[l.id]?.reason || null, note: entries[l.id]?.note || null })) });

    async function save(): Promise<{ count: Count } | null> {
        setSaved(false);
        const done = await action.run<{ count: Count }>(`/inventory/counts/${count.id}/lines`, { body: body(), reload });
        if (done !== null) setSaved(true);

        return done;
    }

    async function submit() {
        const done = await save();
        if (done === null) return;
        setSaved(false);
        await action.run(`/inventory/counts/${count.id}/submit`, { body: { lock_version: done.count.lock_version }, reload });
    }

    async function decide(kind: 'approve' | 'send-back' | 'cancel') {
        const done = await action.run(`/inventory/counts/${count.id}/${kind}`, { body: { lock_version: count.lock_version, note: note || null }, reload });
        if (done !== null) { setDialog(null); setNote(''); }
    }

    const showMinutes = !counting;
    const reviewer = count.may_review || count.status === 'approved' || count.status === 'submitted' || count.status === 'cancelled';

    return (
        <InventoryShell
            actions={<>
                <Button asChild variant="outline"><Link href="/inventory/counts">{t('inv.cnt.back')}</Link></Button>
                {showMinutes ? <Button onClick={() => window.print()} type="button" variant="outline">{t('inv.cnt.print')}</Button> : null}
            </>}
            description={t('inv.cnt.description')}
            title={`${count.number} · ${showMinutes ? t('inv.cnt.minutes') : t('inv.cnt.sheet')}`}
            wide
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" data-testid="count-head">
                <StatusBadge label={label(count.status)} tone={tone[count.status] ?? 'neutral'} />
                <span>{count.location.name}</span>
                <span className="text-muted-foreground">{t(`inv.cnt.kind.${count.kind}` as MessageKey)}</span>
                <span className="text-muted-foreground">{t('inv.cnt.startedBy')}: {count.started_by_name ?? '—'}{count.started_at ? ` · ${format.instant(count.started_at)}` : ''}</span>
                {count.note ? <span>{count.note}</span> : null}
            </div>

            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {saved ? <Alert title={t('inv.cnt.saved')} tone="success" /> : null}
            {count.blind ? <Alert title={t('inv.cnt.blind')} tone="info" /> : null}
            {count.decision_note ? <Alert title={count.decision_note} tone="warning" /> : null}
            {count.review_blocked_self ? <Alert title={t('inv.cnt.selfReview')} tone="warning" /> : null}
            {count.status === 'submitted' && !count.may_review && !count.review_blocked_self ? <Alert title={t('inv.cnt.waiting')} tone="info" /> : null}

            <div className="overflow-x-auto border border-border bg-surface">
                <Table data-testid="count-lines">
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('inv.col.item')}</TableHead>
                            {!count.blind ? <TableHead className="text-right">{t('inv.cnt.system')}</TableHead> : null}
                            <TableHead className={counting ? undefined : 'text-right'}>{t('inv.cnt.counted')}</TableHead>
                            {counting ? <TableHead>{t('inv.cnt.reasonLabel')}</TableHead> : null}
                            {counting ? <TableHead>{t('inv.cnt.noteLabel')}</TableHead> : null}
                            {!count.blind ? <TableHead className="text-right">{t('inv.cnt.variance')}</TableHead> : null}
                            {!count.blind && count.status !== 'approved' && count.status !== 'cancelled' ? <TableHead className="text-right">{t('inv.cnt.moved')}</TableHead> : null}
                            {!count.blind ? <TableHead className="text-right">{t('inv.cnt.value')}</TableHead> : null}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {count.lines.map((l) => {
                            const e = entries[l.id];

                            return (
                                <TableRow data-testid={`count-line-${l.item_code}`} key={l.id}>
                                    <TableCell><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span></TableCell>
                                    {!count.blind ? <TableCell className="text-right">{l.snapshot_milli === null ? '—' : `${qty(l.snapshot_milli)} ${l.base_unit}`}</TableCell> : null}
                                    {counting && e !== undefined ? (
                                        <>
                                            <TableCell>
                                                <div className="flex gap-2">
                                                    <Input aria-label={`${t('inv.cnt.counted')} ${l.item_code}`} className="w-28" inputMode="decimal" onChange={(ev) => set(l.id, { quantity: ev.target.value })} value={e.quantity} />
                                                    <div className="w-24">
                                                        <Select aria-label={`${t('inv.cnt.unit')} ${l.item_code}`} onChange={(ev) => set(l.id, { unit: ev.target.value })} searchable={false} value={e.unit}>
                                                            {l.units.map((u) => <option key={u} value={u}>{u}</option>)}
                                                        </Select>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="w-44">
                                                    <Select aria-label={`${t('inv.cnt.reasonLabel')} ${l.item_code}`} onChange={(ev) => set(l.id, { reason: ev.target.value })} searchable={false} value={e.reason}>
                                                        <option value="">—</option>
                                                        {count.reasons.map((r) => <option key={r} value={r}>{t(`inv.reason.${r}` as MessageKey)}</option>)}
                                                    </Select>
                                                </div>
                                            </TableCell>
                                            <TableCell><Input aria-label={`${t('inv.cnt.noteLabel')} ${l.item_code}`} maxLength={200} onChange={(ev) => set(l.id, { note: ev.target.value })} value={e.note} /></TableCell>
                                        </>
                                    ) : (
                                        <TableCell className="text-right">{l.counted_milli === null ? '—' : <>{qty(l.counted_milli)} {l.base_unit}{l.counted_unit !== null && l.counted_unit !== l.base_unit && l.counted_unit_qty_milli !== null ? <span className="text-muted-foreground"> ({qty(l.counted_unit_qty_milli)} {l.counted_unit})</span> : null}</>}</TableCell>
                                    )}
                                    {!count.blind ? (
                                        <TableCell className={`text-right font-medium ${l.variance_milli !== null && l.variance_milli < 0 ? 'text-danger' : ''}`}>
                                            {l.variance_milli === null ? '—' : `${l.variance_milli > 0 ? '+' : ''}${qty(l.variance_milli)} ${l.base_unit}`}
                                        </TableCell>
                                    ) : null}
                                    {!count.blind && count.status !== 'approved' && count.status !== 'cancelled' ? <TableCell className="text-right text-muted-foreground">{l.moved_since_milli === null ? '—' : `${l.moved_since_milli > 0 ? '+' : ''}${qty(l.moved_since_milli)}`}</TableCell> : null}
                                    {!count.blind ? <TableCell className="text-right">{l.value_minor === null || l.variance_milli === 0 ? '—' : <>{money(l.value_minor)}{l.value_is_estimate ? <span className="text-muted-foreground"> ({t('inv.cnt.estimate')})</span> : null}</>}</TableCell> : null}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                    {!count.blind && count.net_minor !== null ? (
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={count.status === 'approved' || count.status === 'cancelled' ? 3 : 4}>
                                    {t('inv.cnt.gain')}: <strong data-testid="count-gain">{money(count.gain_minor ?? 0)}</strong> · {t('inv.cnt.loss')}: <strong data-testid="count-loss">{money(count.loss_minor ?? 0)}</strong>
                                </TableCell>
                                <TableCell className="text-right">{t('inv.cnt.net')}</TableCell>
                                <TableCell className="text-right"><strong data-testid="count-net">{money(count.net_minor)}</strong></TableCell>
                            </TableRow>
                        </TableFooter>
                    ) : null}
                </Table>
            </div>

            {counting ? (
                <div className="flex flex-wrap gap-2 print:hidden">
                    <Button disabled={action.busy} loading={action.busy} onClick={() => void save()} type="button" variant="outline">{t('inv.cnt.save')}</Button>
                    <Button disabled={action.busy} onClick={() => void submit()} type="button">{t('inv.cnt.submit')}</Button>
                    {count.may_cancel ? <Button disabled={action.busy} onClick={() => { action.clear(); setDialog('cancel'); }} type="button" variant="outline">{t('inv.cnt.cancel')}</Button> : null}
                </div>
            ) : null}

            {count.may_review ? (
                <div className="flex flex-wrap gap-2 print:hidden">
                    <Button disabled={action.busy} onClick={() => { action.clear(); setDialog('approve'); }} type="button">{t('inv.cnt.approve')}</Button>
                    <Button disabled={action.busy} onClick={() => { action.clear(); setDialog('back'); }} type="button" variant="outline">{t('inv.cnt.sendBack')}</Button>
                    {count.may_cancel ? <Button disabled={action.busy} onClick={() => { action.clear(); setDialog('cancel'); }} type="button" variant="outline">{t('inv.cnt.cancel')}</Button> : null}
                </div>
            ) : null}

            {showMinutes && reviewer ? (
                <section aria-label={t('inv.cnt.signature')} className="mt-6 grid gap-8 sm:grid-cols-3">
                    {[[t('inv.cnt.countedBy'), count.submitted_by_name, count.submitted_at], [t('inv.cnt.decidedBy'), count.decided_by_name, count.decided_at], [t('inv.cnt.startedBy'), count.started_by_name, count.started_at]].map(([title, name, at]) => (
                        <div className="flex flex-col gap-10 text-sm" key={title as string}>
                            <span className="text-muted-foreground">{title}</span>
                            <div className="border-t border-foreground pt-1"><div>{(name as string | null) ?? '—'}</div><div className="text-xs text-muted-foreground">{at ? format.instant(at as string) : ''}</div></div>
                        </div>
                    ))}
                </section>
            ) : null}

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setDialog(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void decide(dialog === 'back' ? 'send-back' : dialog === 'cancel' ? 'cancel' : 'approve')} type="button">{dialog === 'back' ? t('inv.cnt.sendBack') : dialog === 'cancel' ? t('inv.cnt.cancel') : t('inv.cnt.approve')}</Button>
                </>}
                onClose={() => setDialog(null)}
                open={dialog !== null}
                title={dialog === 'approve' ? t('inv.cnt.confirmApprove') : dialog === 'back' ? t('inv.cnt.sendBack') : t('inv.cnt.cancel')}
            >
                <div className="flex flex-col gap-3">
                    {dialog === 'approve' ? <p className="text-sm text-muted-foreground">{t('inv.cnt.approveHint')}</p> : null}
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={action.fieldError('note')} field="note" label={dialog === 'back' ? t('inv.cnt.sendBackReason') : dialog === 'cancel' ? t('inv.cnt.cancelReason') : t('inv.cnt.reviewNote')} required={dialog !== 'approve'}>
                        <Input maxLength={200} onChange={(e) => setNote(e.target.value)} value={note} />
                    </FormField>
                </div>
            </Dialog>
        </InventoryShell>
    );
}
