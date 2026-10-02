import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Item = {
    id: string; number: string; description: string; room: string | null; place: string | null; found_date: string; found_at: string; found_by_name: string | null; stored_at: string; status: 'stored' | 'returned' | 'disposed';
    returned_to: string | null; closed_note: string | null; closed_at: string | null; closed_by_name: string | null; lock_version: number; has_photo: boolean; days_stored: number | null;
};
type Overview = { items: Item[]; rooms: { id: string; number: string }[]; may: { record: boolean; manage: boolean }; business_date: string; flag_days: number };

const TONE = { stored: 'info', returned: 'success', disposed: 'neutral' } as const;

/** What was found in the hotel, where it is kept and what became of it (FR-HK-012). */
export default function LostFoundPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ description: '', roomId: '', place: '', storedAt: '' });
    const [file, setFile] = useState<File | null>(null);
    const [pickerKey, setPickerKey] = useState(0);
    const [closing, setClosing] = useState<{ id: string; mode: 'returned' | 'disposed'; text: string; note: string } | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        const body = new FormData();
        body.set('description', form.description.trim());
        if (form.roomId !== '') body.set('room_id', form.roomId);
        if (form.place.trim() !== '') body.set('place', form.place.trim());
        body.set('stored_at', form.storedAt.trim());
        if (file !== null) body.set('photo', file);
        const done = await action.run('/housekeeping/lost-found', { body, reload: ['overview'] });
        if (done !== null) { setForm({ ...form, description: '', place: '' }); setFile(null); setPickerKey((k) => k + 1); }
    }

    async function close(item: Item) {
        if (closing === null) return;
        const body = closing.mode === 'returned'
            ? { returned_to: closing.text.trim(), note: closing.note.trim() || null, lock_version: item.lock_version }
            : { reason: closing.text.trim(), lock_version: item.lock_version };
        const done = await action.run(`/housekeeping/lost-found/${item.id}/${closing.mode}`, { body, reload: ['overview'] });
        if (done !== null) setClosing(null);
    }

    return (
        <HousekeepingShell description={t('hk.lf.description')} title={t('hk.lf.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <div className="flex flex-wrap items-end gap-2">
                <FormField label={t('hk.lf.filter')}>
                    <Select onChange={(e) => router.get('/housekeeping/lost-found', e.target.value === '' ? {} : { status: e.target.value }, { preserveState: true })} value={status}>
                        <option value="">{t('hk.lf.all')}</option>
                        {(['stored', 'returned', 'disposed'] as const).map((s) => <option key={s} value={s}>{t(`hk.lf.status.${s}` as 'hk.lf.status.stored')}</option>)}
                    </Select>
                </FormField>
            </div>

            {overview.items.length === 0 ? <EmptyState title={t('hk.lf.empty')} /> : (
                <ul className="flex flex-col gap-3" data-testid="lost-found">
                    {overview.items.map((i) => (
                        <li className="flex flex-col gap-2 border border-border p-3 text-sm" data-testid={`item-${i.number}`} key={i.id}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p><span className="font-medium">{i.number}</span> · {i.description}</p>
                                <span className="flex items-center gap-2">
                                    {i.days_stored !== null && i.days_stored >= overview.flag_days ? <StatusBadge label={t('hk.lf.due', { days: i.days_stored })} tone="warning" /> : null}
                                    <StatusBadge label={t(`hk.lf.status.${i.status}` as 'hk.lf.status.stored')} tone={TONE[i.status]} />
                                </span>
                            </div>
                            <p className="text-xs text-muted-foreground">{t('hk.lf.found', { where: i.room !== null ? t('hk.lf.room', { room: i.room }) + (i.place !== null ? ` · ${i.place}` : '') : (i.place ?? ''), date: format.date(i.found_date), by: i.found_by_name ?? '—' })} · {t('hk.lf.kept', { place: i.stored_at })}</p>
                            {i.status === 'returned' ? <p className="text-xs">{t('hk.lf.returnedTo', { name: i.returned_to ?? '', date: i.closed_at === null ? '' : format.instant(i.closed_at), by: i.closed_by_name ?? '—' })}{i.closed_note !== null ? ` · ${i.closed_note}` : ''}</p> : null}
                            {i.status === 'disposed' ? <p className="text-xs">{t('hk.lf.disposedBecause', { reason: i.closed_note ?? '', by: i.closed_by_name ?? '—' })}</p> : null}
                            <div className="flex flex-wrap gap-2">
                                {i.has_photo ? <Button asChild size="sm" variant="outline"><a href={`/housekeeping/lost-found/${i.id}/photo`} rel="noreferrer" target="_blank">{t('hk.lf.photo')}</a></Button> : null}
                                {overview.may.manage && i.status === 'stored' ? <>
                                    <Button onClick={() => setClosing({ id: i.id, mode: 'returned', text: '', note: '' })} size="sm" type="button" variant="outline">{t('hk.lf.return')}</Button>
                                    <Button onClick={() => setClosing({ id: i.id, mode: 'disposed', text: '', note: '' })} size="sm" type="button" variant="outline">{t('hk.lf.dispose')}</Button>
                                </> : null}
                            </div>
                            {closing?.id === i.id && (
                                <div className="flex flex-wrap items-end gap-2">
                                    <FormField error={action.fieldError(closing.mode === 'returned' ? 'returned_to' : 'reason')} label={closing.mode === 'returned' ? t('hk.lf.returnedToLabel') : t('hk.lf.disposeReason')}><Input maxLength={closing.mode === 'returned' ? 100 : 300} onChange={(e) => setClosing({ ...closing, text: e.target.value })} value={closing.text} /></FormField>
                                    {closing.mode === 'returned' ? <FormField error={action.fieldError('note')} label={t('hk.lf.note')}><Input maxLength={300} onChange={(e) => setClosing({ ...closing, note: e.target.value })} value={closing.note} /></FormField> : null}
                                    <Button disabled={closing.text.trim() === ''} loading={action.busy} onClick={() => void close(i)} size="sm" type="button">{t('hk.lf.confirm')}</Button>
                                    <Button disabled={action.busy} onClick={() => setClosing(null)} size="sm" type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {(overview.may.record || overview.may.manage) && (
                <section aria-labelledby="lf-new-h" className="flex flex-col gap-3 border-t border-border pt-4">
                    <h2 className="text-lg font-semibold" id="lf-new-h">{t('hk.lf.new')}</h2>
                    <form className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => void submit(e)}>
                        <FormField error={action.fieldError('description')} label={t('hk.lf.what')}><Input maxLength={200} onChange={(e) => setForm({ ...form, description: e.target.value })} required value={form.description} /></FormField>
                        <FormField error={action.fieldError('stored_at')} hint={t('hk.lf.keptHint')} label={t('hk.lf.keptLabel')}><Input maxLength={80} onChange={(e) => setForm({ ...form, storedAt: e.target.value })} required value={form.storedAt} /></FormField>
                        <FormField error={action.fieldError('room_id')} label={t('hk.lf.roomLabel')}><Select onChange={(e) => setForm({ ...form, roomId: e.target.value })} value={form.roomId}><option value="">—</option>{overview.rooms.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('place')} hint={t('hk.lf.placeHint')} label={t('hk.lf.placeLabel')}><Input maxLength={80} onChange={(e) => setForm({ ...form, place: e.target.value })} value={form.place} /></FormField>
                        <FormField error={action.fieldError('photo')} label={t('hk.lf.photoLabel')}><Input accept="image/jpeg,image/png" capture="environment" key={pickerKey} onChange={(e) => setFile(e.target.files?.[0] ?? null)} type="file" /></FormField>
                        <div className="flex items-end"><Button loading={action.busy} type="submit">{t('hk.lf.record')}</Button></div>
                    </form>
                </section>
            )}
        </HousekeepingShell>
    );
}
