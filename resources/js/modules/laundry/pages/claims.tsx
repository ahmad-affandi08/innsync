import { Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { ConfirmDialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { LaundryShell } from '@/modules/laundry/components/laundry-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Claim = {
    id: string; number: string; order_id: string; order_number: string; room: string; item_name: string | null; pieces: number; kind: 'damage' | 'loss'; description: string; claimed_minor: number; status: 'open' | 'approved' | 'rejected';
    approved_minor: number | null; decision_note: string | null; recorded_by_name: string | null; decided_by_name: string | null; decided_at: string | null; created_at: string; lock_version: number; has_photo: boolean; cap_minor: number | null; may_decide: boolean;
};
type OrderChoice = { id: string; number: string; room: string | null; status: string; lines: { id: string; item_name: string; quantity: number; piece_minor: number }[] };
type Overview = { claims: Claim[]; orders: OrderChoice[]; currency: string; cap_multiple: number; settings_lock: number | null; may: { record: boolean; approve: boolean; settings: boolean } };

const TONE: Record<string, StatusTone> = { open: 'warning', approved: 'success', rejected: 'neutral' };

/** Claims for damaged or lost guest laundry, decided by a Manager on Duty (FR-LDY-006). */
export default function LaundryClaimsPage({ order, overview, status }: { order: string | null; overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const currency = overview.currency;
    const [form, setForm] = useState({ orderId: order ?? '', lineId: '', pieces: '1', kind: 'damage', description: '', claimed: '' });
    const [file, setFile] = useState<File | null>(null);
    const [pickerKey, setPickerKey] = useState(0);
    const [invalid, setInvalid] = useState(false);
    const [deciding, setDeciding] = useState<{ claim: Claim; mode: 'approve' | 'reject'; amount: string; note: string } | null>(null);
    const [cap, setCap] = useState({ multiple: String(overview.cap_multiple), reason: '' });
    const chosen = overview.orders.find((o) => o.id === form.orderId);
    const money = (v: number) => format.money(v, currency);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const minor = parseMajorToMinor(form.claimed, currency);
        setInvalid(minor === null);
        if (minor === null) return;
        const body = new FormData();
        body.set('order_id', form.orderId);
        if (form.lineId !== '') body.set('line_id', form.lineId);
        body.set('pieces', form.lineId === '' ? '1' : form.pieces);
        body.set('kind', form.kind);
        body.set('description', form.description.trim());
        body.set('claimed_minor', String(minor));
        if (file !== null) body.set('photo', file);
        const done = await action.run('/laundry/claims', { body, reload: ['overview'] });
        if (done !== null) { setForm({ ...form, lineId: '', pieces: '1', description: '', claimed: '' }); setFile(null); setPickerKey((k) => k + 1); }
    }

    async function decide() {
        if (deciding === null) return;
        const { claim, mode } = deciding;
        const minor = mode === 'approve' ? parseMajorToMinor(deciding.amount, currency) : 0;
        setInvalid(minor === null);
        if (minor === null) return;
        const body = mode === 'approve' ? { approved_minor: minor, note: deciding.note.trim() || null, lock_version: claim.lock_version } : { note: deciding.note.trim(), lock_version: claim.lock_version };
        const done = await action.run(`/laundry/claims/${claim.id}/${mode}`, { body, reload: ['overview'] });
        if (done !== null) setDeciding(null);
    }

    async function saveCap() {
        await action.run('/laundry/claims/cap', { body: { cap_multiple: Number(cap.multiple), lock_version: overview.settings_lock, reason: cap.reason.trim() }, reload: ['overview'] });
    }

    const columns: DataGridColumn<Claim>[] = [
        {
            id: 'number', label: t('ldy.claim.number'), value: (c) => c.number, searchText: (c) => `${c.number} ${c.recorded_by_name ?? ''}`, rowHeader: true,
            cell: (c) => <>{c.number}<span className="block text-xs font-normal text-muted-foreground">{format.instant(c.created_at + 'Z')} · {c.recorded_by_name}</span></>,
        },
        {
            id: 'order', label: t('ldy.claim.order'), value: (c) => c.order_number, searchText: (c) => `${c.order_number} ${c.room}`,
            cell: (c) => <><Link className="underline-offset-2 hover:underline" href={`/laundry/orders/${c.order_id}`}>{c.order_number}</Link> · {c.room}</>,
        },
        {
            id: 'item', label: t('ldy.claim.item'), value: (c) => (c.item_name === null ? t('ldy.claim.wholeBag') : c.item_name), searchText: (c) => `${c.item_name ?? t('ldy.claim.wholeBag')} ${c.description}`,
            cell: (c) => <>{c.item_name === null ? t('ldy.claim.wholeBag') : `${c.item_name} × ${c.pieces}`}<span className="block text-xs text-muted-foreground">{c.description}</span></>,
        },
        { id: 'kind', label: t('ldy.claim.kind'), value: (c) => c.kind, filter: 'select', filterLabel: (v) => t(`ldy.claim.kind.${v}` as 'ldy.claim.kind.damage'), cell: (c) => t(`ldy.claim.kind.${c.kind}` as 'ldy.claim.kind.damage') },
        { id: 'claimed', label: t('ldy.claim.claimed'), align: 'right', value: (c) => c.claimed_minor, cell: (c) => money(c.claimed_minor) },
        {
            id: 'status', label: t('ldy.claim.status'), value: (c) => c.status, searchText: (c) => `${t(`ldy.claim.status.${c.status}` as 'ldy.claim.status.open')} ${c.decided_by_name ?? ''}`, filter: 'select', filterLabel: (v) => t(`ldy.claim.status.${v}` as 'ldy.claim.status.open'),
            cell: (c) => <><StatusBadge label={t(`ldy.claim.status.${c.status}` as 'ldy.claim.status.open')} tone={TONE[c.status]} />{c.decided_by_name !== null ? <span className="block text-xs text-muted-foreground">{c.decided_by_name}</span> : null}</>,
        },
        {
            id: 'approved', label: t('ldy.claim.approved'), align: 'right', value: (c) => c.approved_minor, searchText: (c) => c.decision_note ?? '',
            cell: (c) => <>{c.approved_minor === null ? '—' : money(c.approved_minor)}{c.decision_note !== null ? <span className="block text-xs text-muted-foreground">{c.decision_note}</span> : null}</>,
        },
        {
            id: 'actions', label: t('ldy.actions'),
            cell: (c) => (
                <div className="flex flex-wrap gap-2">
                    {c.has_photo ? <Button asChild size="sm" variant="outline"><a href={`/laundry/claims/${c.id}/photo`} rel="noreferrer" target="_blank">{t('ldy.claim.photo')}</a></Button> : null}
                    {c.may_decide ? <Button onClick={() => { action.clear(); setInvalid(false); setDeciding({ claim: c, mode: 'approve', amount: String(c.claimed_minor / 100), note: '' }); }} size="sm" type="button">{t('ldy.claim.approve')}</Button> : null}
                    {c.may_decide ? <Button onClick={() => { action.clear(); setDeciding({ claim: c, mode: 'reject', amount: '', note: '' }); }} size="sm" type="button" variant="outline">{t('ldy.claim.reject')}</Button> : null}
                </div>
            ),
        },
    ];

    return (
        <LaundryShell description={t('ldy.claim.description')} title={t('ldy.claim.title')} wide>
            {action.error !== null && deciding === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <Alert title={t('ldy.claim.note')} tone="info" />

            <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); router.get('/laundry/claims', (e.currentTarget.elements.namedItem('status') as HTMLSelectElement).value === '' ? {} : { status: (e.currentTarget.elements.namedItem('status') as HTMLSelectElement).value }); }}>
                <FormField label={t('ldy.claim.status')}>
                    <Select defaultValue={status} name="status"><option value="">{t('ldy.claim.all')}</option>{(['open', 'approved', 'rejected'] as const).map((s) => <option key={s} value={s}>{t(`ldy.claim.status.${s}` as 'ldy.claim.status.open')}</option>)}</Select>
                </FormField>
                <Button type="submit" variant="outline">{t('ldy.claim.filter')}</Button>
            </form>

            <DataGrid
                caption={t('ldy.claim.title')}
                columns={columns}
                empty={<EmptyState title={t('ldy.claim.empty')} />}
                getRowId={(c) => c.id}
                id="ldy.claims"
                rows={overview.claims}
                testId="claims"
            />

            {overview.may.record && (
                <section aria-labelledby="claim-new-h" className="flex max-w-3xl flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="claim-new-h">{t('ldy.claim.new')}</h2>
                    <form className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => void submit(e)}>
                        <FormField field="order_id" error={action.fieldError('order_id')} label={t('ldy.claim.order')}>
                            <Select onChange={(e) => setForm({ ...form, orderId: e.target.value, lineId: '', pieces: '1' })} required value={form.orderId}>
                                <option value="">—</option>{overview.orders.map((o) => <option key={o.id} value={o.id}>{o.number} · {o.room}</option>)}
                            </Select>
                        </FormField>
                        <FormField field="line_id" error={action.fieldError('line_id')} label={t('ldy.claim.item')}>
                            <Select onChange={(e) => setForm({ ...form, lineId: e.target.value, pieces: '1' })} value={form.lineId}>
                                <option value="">{t('ldy.claim.wholeBag')}</option>{(chosen?.lines ?? []).map((l) => <option key={l.id} value={l.id}>{l.item_name} (×{l.quantity})</option>)}
                            </Select>
                        </FormField>
                        {form.lineId !== '' ? <FormField field="pieces" error={action.fieldError('pieces')} label={t('ldy.claim.pieces')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, pieces: e.target.value })} value={form.pieces} /></FormField> : null}
                        <FormField field="kind" error={action.fieldError('kind')} label={t('ldy.claim.kind')}><Select onChange={(e) => setForm({ ...form, kind: e.target.value })} value={form.kind}><option value="damage">{t('ldy.claim.kind.damage')}</option><option value="loss">{t('ldy.claim.kind.loss')}</option></Select></FormField>
                        <FormField field="claimed_minor" error={invalid ? t('ldy.claim.invalidAmount') : action.fieldError('claimed_minor')} label={t('ldy.claim.claimed')}><Input inputMode="decimal" onChange={(e) => setForm({ ...form, claimed: e.target.value })} required value={form.claimed} /></FormField>
                        <div className="sm:col-span-2"><FormField field="description" error={action.fieldError('description')} label={t('ldy.claim.what')}><Textarea maxLength={300} onChange={(e) => setForm({ ...form, description: e.target.value })} required rows={2} value={form.description} /></FormField></div>
                        <div className="sm:col-span-2"><FormField field="photo" error={action.fieldError('photo')} label={t('ldy.claim.photoLabel')}><Input accept="image/jpeg,image/png" capture="environment" key={pickerKey} onChange={(e) => setFile(e.target.files?.[0] ?? null)} type="file" /></FormField></div>
                        <div className="sm:col-span-2"><Button loading={action.busy} type="submit">{t('ldy.claim.record')}</Button></div>
                    </form>
                </section>
            )}

            {overview.may.settings && (
                <section aria-labelledby="claim-cap-h" className="flex flex-col gap-2 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="claim-cap-h">{t('ldy.claim.cap')}</h2>
                    <p className="text-sm text-muted-foreground">{t('ldy.claim.capHint')}</p>
                    <div className="flex flex-wrap items-end gap-2">
                        <FormField field="cap_multiple" error={action.fieldError('cap_multiple')} label={t('ldy.claim.capMultiple')}><Input inputMode="numeric" onChange={(e) => setCap({ ...cap, multiple: e.target.value })} value={cap.multiple} /></FormField>
                        <FormField field="reason" error={action.fieldError('reason')} label={t('fo.folio.reason')}><Input maxLength={300} onChange={(e) => setCap({ ...cap, reason: e.target.value })} value={cap.reason} /></FormField>
                        <Button disabled={action.busy || cap.reason.trim() === ''} onClick={() => void saveCap()} type="button" variant="outline">{t('ldy.claim.capSave')}</Button>
                    </div>
                </section>
            )}

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={deciding?.mode === 'reject' ? t('ldy.claim.reject') : t('ldy.claim.approve')}
                consequence={t('ldy.claim.decideConsequence')}
                destructive={deciding?.mode === 'reject'}
                onCancel={() => setDeciding(null)}
                onConfirm={() => void decide()}
                open={deciding !== null}
                pending={action.busy}
                title={deciding === null ? '' : `${deciding.claim.number} · ${deciding.mode === 'reject' ? t('ldy.claim.reject') : t('ldy.claim.approve')}`}
            >
                {deciding !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        {deciding.mode === 'approve' ? <FormField field="approved_minor" error={invalid ? t('ldy.claim.invalidAmount') : action.fieldError('approved_minor')} hint={deciding.claim.cap_minor === null ? t('ldy.claim.claimedWas', { amount: money(deciding.claim.claimed_minor) }) : t('ldy.claim.claimedCap', { amount: money(deciding.claim.claimed_minor), cap: money(deciding.claim.cap_minor) })} label={t('ldy.claim.approvedAmount')}><Input inputMode="decimal" onChange={(e) => setDeciding({ ...deciding, amount: e.target.value })} value={deciding.amount} /></FormField> : null}
                        <FormField field="note" error={action.fieldError('note')} label={deciding.mode === 'reject' ? t('ldy.claim.rejectReason') : t('ldy.claim.note2')}><Input maxLength={300} onChange={(e) => setDeciding({ ...deciding, note: e.target.value })} value={deciding.note} /></FormField>
                    </div>
                )}
            </ConfirmDialog>
        </LaundryShell>
    );
}
