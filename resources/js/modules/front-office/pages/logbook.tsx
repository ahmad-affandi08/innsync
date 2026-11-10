import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Entry = { id: string; business_date: string; shift: string; priority: string; body: string; author_name: string | null; created_at: string; read: boolean };
type Props = { log: { entries: Entry[]; unread: number; may_write: boolean; shifts: string[] } };

/** The handover log between shifts (FR-FO-034): written at the end of a shift, read at the start of the next. */
export default function LogbookPage({ log }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ shift: string; body: string; important: boolean } | null>(null);

    async function save() {
        if (form === null) return;
        const done = await action.run('/front-office/logbook', { body: form, reload: ['log'] });
        if (done !== null) setForm(null);
    }

    async function markAll() {
        const ids = log.entries.filter((e) => !e.read).map((e) => e.id);
        if (ids.length > 0) await action.run('/front-office/logbook/read', { body: { entries: ids }, reload: ['log'] });
    }

    return (
        <FrontOfficeShell description={t('fo.log.description')} title={t('fo.log.title')} wide>
            {action.error !== null && form === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {log.unread > 0
                ? <Alert actions={<Button disabled={action.busy} onClick={() => void markAll()} size="sm" type="button" variant="outline">{t('fo.log.markAll')}</Button>} title={t('fo.log.unread', { n: log.unread })} tone="warning" />
                : <p className="text-sm text-muted-foreground" data-testid="all-read">{t('fo.log.allRead')}</p>}

            {log.may_write ? <div><Button onClick={() => { action.clear(); setForm({ shift: 'night', body: '', important: false }); }} size="sm" type="button">{t('fo.log.write')}</Button></div> : null}

            {log.entries.length === 0 ? <EmptyState title={t('fo.log.empty')} /> : (
                <ul className="flex flex-col gap-3" data-testid="log-entries">
                    {log.entries.map((e) => (
                        <li className={`flex flex-col gap-1 border p-3 text-sm ${e.read ? 'border-border' : 'border-warning'}`} data-testid={`entry-${e.id}`} key={e.id}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-xs text-muted-foreground">{t('fo.log.entry', { shift: t(`fo.log.shift.${e.shift}` as 'fo.log.shift.night'), date: format.date(e.business_date), name: e.author_name ?? '—' })} · {format.instant(e.created_at)}</span>
                                <span className="flex gap-2">
                                    {e.priority === 'important' ? <StatusBadge label={t('fo.log.importantTag')} tone="danger" /> : null}
                                    {!e.read ? <StatusBadge label={t('fo.log.new')} tone="warning" /> : null}
                                </span>
                            </div>
                            <p className="whitespace-pre-wrap">{e.body}</p>
                        </li>
                    ))}
                </ul>
            )}

            {form !== null && (
                <Dialog footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void save()} type="button">{t('fo.log.save')}</Button></>} onClose={() => setForm(null)} open title={t('fo.log.write')}>
                    <div className="flex flex-col gap-3" data-testid="log-form">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField field="shift" error={action.fieldError('shift')} label={t('fo.log.shift')}><Select onChange={(e) => setForm({ ...form, shift: e.target.value })} value={form.shift}>{log.shifts.map((s) => <option key={s} value={s}>{t(`fo.log.shift.${s}` as 'fo.log.shift.night')}</option>)}</Select></FormField>
                        <FormField field="body" error={action.fieldError('body')} label={t('fo.log.body')}><Textarea maxLength={2000} onChange={(e) => setForm({ ...form, body: e.target.value })} rows={5} value={form.body} /></FormField>
                        <label className="flex items-center gap-2 text-sm"><input checked={form.important} onChange={(e) => setForm({ ...form, important: e.target.checked })} type="checkbox" />{t('fo.log.important')}</label>
                    </div>
                </Dialog>
            )}
        </FrontOfficeShell>
    );
}
