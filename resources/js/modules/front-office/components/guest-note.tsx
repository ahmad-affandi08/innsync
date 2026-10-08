import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

export type GuestNote = { flag: 'vip' | 'attention' | null; note: string | null; updated_at: string | null; may_write: boolean };

/** What the desk should know about this guest every time they come: a flag and a short note that follow the guest from stay to stay. */
export function GuestNotePanel({ note, reservationId }: { note: GuestNote; reservationId: string }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [current, setCurrent] = useState(note);
    const [editing, setEditing] = useState(false);
    const [flag, setFlag] = useState(note.flag ?? '');
    const [text, setText] = useState(note.note ?? '');

    async function save() {
        const done = await action.run<{ guest_note: GuestNote }>(`/front-office/reservations/${reservationId}/guest-note`, { method: 'PUT', body: { flag, note: text } });
        if (done !== null) {
            setCurrent(done.guest_note);
            setEditing(false);
        }
    }

    const empty = current.flag === null && current.note === null;

    return (
        <section aria-labelledby="gn-h" className="flex flex-col gap-2" data-testid="guest-note">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold" id="gn-h">{t('fo.gnote.title')}</h2>
                {current.may_write && !editing ? <Button onClick={() => { action.clear(); setFlag(current.flag ?? ''); setText(current.note ?? ''); setEditing(true); }} size="sm" type="button" variant="outline">{t(empty ? 'fo.gnote.add' : 'fo.gnote.edit')}</Button> : null}
            </div>
            {!editing ? (
                empty ? <p className="text-sm text-muted-foreground">{t('fo.gnote.none')}</p> : (
                    <div className="flex flex-col gap-1 text-sm">
                        {current.flag !== null ? <div><StatusBadge label={t(`fo.gnote.flag.${current.flag}` as 'fo.gnote.flag.vip')} tone={current.flag === 'vip' ? 'success' : 'warning'} /></div> : null}
                        {current.note !== null ? <p className="break-words">{current.note}</p> : null}
                    </div>
                )
            ) : (
                <div className="flex flex-col gap-3">
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={action.fieldError('flag')} label={t('fo.gnote.flag')}>
                        <Select onChange={(e) => setFlag(e.target.value)} value={flag}>
                            <option value="">{t('fo.gnote.flag.none')}</option>
                            <option value="vip">{t('fo.gnote.flag.vip')}</option>
                            <option value="attention">{t('fo.gnote.flag.attention')}</option>
                        </Select>
                    </FormField>
                    <FormField error={action.fieldError('note')} label={t('fo.gnote.note')}>
                        <Textarea maxLength={500} onChange={(e) => setText(e.target.value)} rows={3} value={text} />
                    </FormField>
                    <Alert title={t('fo.gnote.warnTitle')} tone="info">{t('fo.gnote.warn')}</Alert>
                    <div className="flex gap-2">
                        <Button loading={action.busy} onClick={() => void save()} type="button">{t('fo.gnote.save')}</Button>
                        <Button disabled={action.busy} onClick={() => setEditing(false)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    </div>
                </div>
            )}
        </section>
    );
}
