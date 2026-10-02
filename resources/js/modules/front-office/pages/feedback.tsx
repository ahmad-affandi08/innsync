import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

export type FeedbackRow = {
    id: string; number: string; kind: string; severity: string | null; status: string; guest_name: string | null; room: string | null; summary: string; owner_name: string | null; overdue: boolean; created_at: string; follow_up_by: string | null;
};
type Props = {
    queue: { items: FeedbackRow[]; may_manage: boolean; kinds: string[]; severities: string[]; channels: string[] };
    filters: { kind: string; status: string; severity: string; owner: string };
};

export const severityTone: Record<string, StatusTone> = { low: 'neutral', medium: 'info', high: 'warning', critical: 'danger' };
const statusTone: Record<string, StatusTone> = { open: 'warning', in_progress: 'info', resolved: 'success', closed: 'neutral' };

/** Guest comments and complaints (FR-FO-031): the list, and a form to record one. */
export default function FeedbackPage({ filters, queue }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [f, setF] = useState(filters);
    const [form, setForm] = useState<{ kind: string; severity: string; channel: string; reservation: string; guest: string; summary: string; detail: string; due: string } | null>(null);
    const [intent, setIntent] = useState(() => newIdempotencyKey());

    function close() {
        action.clear();
        setForm(null);
    }

    async function save() {
        if (form === null) return;
        const done = await action.run<{ item: { id: string } }>('/front-office/feedback', {
            idempotencyKey: intent,
            body: {
                kind: form.kind, severity: form.kind === 'complaint' ? form.severity : null, channel: form.channel, reservation_id: form.reservation.trim() || null, guest_name: form.guest.trim() || null,
                summary: form.summary, detail: form.detail.trim() || null, follow_up_by: form.due || null,
            },
        });
        if (done !== null) { setIntent(newIdempotencyKey()); close(); router.visit(`/front-office/feedback/${done.item.id}`); }
    }

    return (
        <FrontOfficeShell description={t('fo.fb.description')} title={t('fo.fb.title')} wide>
            <div className="flex flex-wrap items-end justify-between gap-3">
                <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); router.get('/front-office/feedback', { status: f.status, ...(f.kind !== '' ? { kind: f.kind } : {}), ...(f.severity !== '' ? { severity: f.severity } : {}) }); }}>
                    <FormField label={t('fo.fb.filter.status')}>
                        <Select onChange={(e) => setF({ ...f, status: e.target.value })} value={f.status}>
                            <option value="active">{t('fo.fb.filter.active')}</option><option value="">{t('fo.fb.filter.all')}</option>
                            {['open', 'in_progress', 'resolved', 'closed'].map((s) => <option key={s} value={s}>{t(`fo.fb.status.${s}` as 'fo.fb.status.open')}</option>)}
                        </Select>
                    </FormField>
                    <FormField label={t('fo.fb.filter.kind')}>
                        <Select onChange={(e) => setF({ ...f, kind: e.target.value })} value={f.kind}>
                            <option value="">{t('fo.fb.filter.all')}</option>
                            {queue.kinds.map((k) => <option key={k} value={k}>{t(`fo.fb.kind.${k}` as 'fo.fb.kind.complaint')}</option>)}
                        </Select>
                    </FormField>
                    <FormField label={t('fo.fb.filter.severity')}>
                        <Select onChange={(e) => setF({ ...f, severity: e.target.value })} value={f.severity}>
                            <option value="">{t('fo.fb.filter.all')}</option>
                            {queue.severities.map((s) => <option key={s} value={s}>{t(`fo.fb.severity.${s}` as 'fo.fb.severity.low')}</option>)}
                        </Select>
                    </FormField>
                    <Button size="sm" type="submit" variant="outline">{t('fo.fb.filter.apply')}</Button>
                </form>
                {queue.may_manage ? <Button onClick={() => { action.clear(); setForm({ kind: 'complaint', severity: 'medium', channel: 'in_person', reservation: '', guest: '', summary: '', detail: '', due: '' }); }} size="sm" type="button">{t('fo.fb.new')}</Button> : null}
            </div>

            {queue.items.length === 0 ? <EmptyState title={t('fo.fb.empty')} /> : (
                <ul className="divide-y divide-border border-y border-border" data-testid="feedback-list">
                    {queue.items.map((i) => (
                        <li className="flex flex-col gap-1 py-3 text-sm" key={i.id}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Link className="font-medium underline-offset-2 hover:underline" href={`/front-office/feedback/${i.id}`}>{t('fo.fb.row', { number: i.number, guest: i.guest_name ?? '—' })}{i.room !== null ? ` · ${i.room}` : ''}</Link>
                                <span className="flex flex-wrap items-center gap-2">
                                    <StatusBadge label={t(`fo.fb.kind.${i.kind}` as 'fo.fb.kind.complaint')} tone="neutral" />
                                    {i.severity !== null ? <StatusBadge label={t(`fo.fb.severity.${i.severity}` as 'fo.fb.severity.low')} tone={severityTone[i.severity] ?? 'neutral'} /> : null}
                                    {i.overdue ? <StatusBadge label={t('fo.fb.overdue')} tone="danger" /> : null}
                                    <StatusBadge label={t(`fo.fb.status.${i.status}` as 'fo.fb.status.open')} tone={statusTone[i.status] ?? 'neutral'} />
                                </span>
                            </div>
                            <p>{i.summary}</p>
                            <p className="text-xs text-muted-foreground">{i.owner_name !== null ? t('fo.fb.owner', { name: i.owner_name }) : t('fo.fb.noOwner')} · {format.instant(i.created_at)}{i.follow_up_by !== null ? ` · ${t('fo.fb.followUp')}: ${format.date(i.follow_up_by)}` : ''}</p>
                        </li>
                    ))}
                </ul>
            )}

            <Dialog footer={<><Button disabled={action.busy} onClick={close} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void save()} type="button">{t('fo.fb.save')}</Button></>} onClose={close} open={form !== null} title={t('fo.fb.dialogTitle')}>
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('kind')} label={t('fo.fb.kind')}><Select onChange={(e) => setForm({ ...form, kind: e.target.value })} value={form.kind}>{queue.kinds.map((k) => <option key={k} value={k}>{t(`fo.fb.kind.${k}` as 'fo.fb.kind.complaint')}</option>)}</Select></FormField>
                        {form.kind === 'complaint' ? <FormField error={action.fieldError('severity')} label={t('fo.fb.severity')}><Select onChange={(e) => setForm({ ...form, severity: e.target.value })} value={form.severity}>{queue.severities.map((s) => <option key={s} value={s}>{t(`fo.fb.severity.${s}` as 'fo.fb.severity.low')}</option>)}</Select></FormField> : null}
                        <FormField error={action.fieldError('channel')} label={t('fo.fb.channel')}><Select onChange={(e) => setForm({ ...form, channel: e.target.value })} value={form.channel}>{queue.channels.map((c) => <option key={c} value={c}>{t(`fo.fb.channel.${c}` as 'fo.fb.channel.phone')}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('reservation_id')} hint={t('fo.fb.reservationHint')} label={t('fo.fb.reservation')}><Input maxLength={26} onChange={(e) => setForm({ ...form, reservation: e.target.value })} value={form.reservation} /></FormField>
                        <FormField error={action.fieldError('guest_name')} label={t('fo.fb.guestName')}><Input maxLength={150} onChange={(e) => setForm({ ...form, guest: e.target.value })} value={form.guest} /></FormField>
                        <FormField error={action.fieldError('summary')} label={t('fo.fb.summary')}><Input maxLength={150} onChange={(e) => setForm({ ...form, summary: e.target.value })} value={form.summary} /></FormField>
                        <FormField error={action.fieldError('detail')} label={t('fo.fb.detail')}><Input maxLength={1000} onChange={(e) => setForm({ ...form, detail: e.target.value })} value={form.detail} /></FormField>
                        <FormField error={action.fieldError('follow_up_by')} hint={t('fo.fb.followUpHint')} label={t('fo.fb.followUp')}><DatePicker onChange={(e) => setForm({ ...form, due: e.target.value })} value={form.due} /></FormField>
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
