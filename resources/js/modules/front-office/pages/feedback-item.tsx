import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { type FeedbackRow, severityTone } from '@/modules/front-office/pages/feedback';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Item = FeedbackRow & { channel: string; detail: string | null; resolution: string | null; evidence_ref: string | null; owner_id: string | null; lock_version: number };
type Event = { id: string; kind: string; text: string | null; actor_name: string | null; created_at: string };
type Props = { detail: { item: Item; events: Event[]; may_manage: boolean; owners: { id: string; name: string }[] } };

const statusTone: Record<string, StatusTone> = { open: 'warning', in_progress: 'info', resolved: 'success', closed: 'neutral' };

/** One piece of feedback with its history and the next steps (FR-FO-031). */
export default function FeedbackItemPage({ detail: d }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const { item: i } = d;
    const [owner, setOwner] = useState(i.owner_id ?? '');
    const [note, setNote] = useState('');
    const [resolution, setResolution] = useState('');
    const [evidence, setEvidence] = useState('');
    const [reopen, setReopen] = useState('');
    const reload = ['detail'];
    const lock = { lock_version: i.lock_version };
    const post = (path: string, body: Record<string, unknown>) => action.run(`/front-office/feedback/${i.id}/${path}`, { body, reload });

    return (
        <FrontOfficeShell description={t('fo.fb.item.description')} title={t('fo.fb.item.title', { number: i.number })} wide>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button asChild size="sm" variant="outline"><Link href="/front-office/feedback">{t('fo.fb.back')}</Link></Button>
                <span className="flex flex-wrap items-center gap-2">
                    <StatusBadge label={t(`fo.fb.kind.${i.kind}` as 'fo.fb.kind.complaint')} tone="neutral" />
                    {i.severity !== null ? <StatusBadge label={t(`fo.fb.severity.${i.severity}` as 'fo.fb.severity.low')} tone={severityTone[i.severity] ?? 'neutral'} /> : null}
                    {i.overdue ? <StatusBadge label={t('fo.fb.overdue')} tone="danger" /> : null}
                    <StatusBadge label={t(`fo.fb.status.${i.status}` as 'fo.fb.status.open')} tone={statusTone[i.status] ?? 'neutral'} />
                </span>
            </div>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <section className="flex flex-col gap-1 text-sm" data-testid="feedback-body">
                <p className="text-base font-medium">{i.summary}</p>
                {i.detail !== null ? <p>{i.detail}</p> : null}
                <p className="text-xs text-muted-foreground">{i.guest_name ?? '—'}{i.room !== null ? ` · ${i.room}` : ''} · {t(`fo.fb.channel.${i.channel}` as 'fo.fb.channel.phone')} · {format.instant(i.created_at)}{i.follow_up_by !== null ? ` · ${t('fo.fb.followUp')}: ${format.date(i.follow_up_by)}` : ''}</p>
                <p className="text-xs text-muted-foreground">{i.owner_name !== null ? t('fo.fb.owner', { name: i.owner_name }) : t('fo.fb.noOwner')}</p>
                {i.resolution !== null ? <p>{t('fo.fb.resolutionLine', { text: i.resolution })}{i.evidence_ref !== null ? ` · ${t('fo.fb.evidenceLine', { ref: i.evidence_ref })}` : ''}</p> : null}
            </section>

            {d.may_manage && i.status !== 'closed' ? (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-end gap-2">
                        <FormField field="owner_id" error={action.fieldError('owner_id')} label={t('fo.fb.assign')}>
                            <Select onChange={(e) => setOwner(e.target.value)} value={owner}><option value="">{t('fo.fb.chooseOwner')}</option>{d.owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</Select>
                        </FormField>
                        <Button disabled={action.busy || owner === ''} onClick={() => void post('assign', { owner_id: owner, ...lock })} size="sm" type="button" variant="outline">{t('fo.fb.assignSave')}</Button>
                    </div>
                    <div className="flex flex-wrap items-end gap-2">
                        <FormField field="text" error={action.fieldError('text')} label={t('fo.fb.note')}><Input maxLength={500} onChange={(e) => setNote(e.target.value)} value={note} /></FormField>
                        <Button disabled={action.busy || note.trim() === ''} onClick={async () => { if ((await post('note', { text: note })) !== null) setNote(''); }} size="sm" type="button" variant="outline">{t('fo.fb.noteSave')}</Button>
                    </div>
                    {i.status === 'open' ? <div><Button disabled={action.busy} onClick={() => void post('start', lock)} size="sm" type="button">{t('fo.fb.start')}</Button></div> : null}
                    {i.status === 'open' || i.status === 'in_progress' ? (
                        <div className="flex flex-wrap items-end gap-2">
                            <FormField field="resolution" error={action.fieldError('resolution')} label={t('fo.fb.resolution')}><Input maxLength={500} onChange={(e) => setResolution(e.target.value)} value={resolution} /></FormField>
                            <FormField field="evidence_ref" error={action.fieldError('evidence_ref')} hint={t('fo.fb.evidenceHint')} label={t('fo.fb.evidence')}><Input maxLength={120} onChange={(e) => setEvidence(e.target.value)} value={evidence} /></FormField>
                            <Button disabled={action.busy} onClick={() => void post('resolve', { resolution, evidence_ref: evidence.trim() || null, ...lock })} size="sm" type="button">{t('fo.fb.resolve')}</Button>
                        </div>
                    ) : null}
                    {i.status === 'resolved' ? (
                        <div className="flex flex-wrap items-end gap-2">
                            <Button disabled={action.busy} onClick={() => void post('close', lock)} size="sm" type="button">{t('fo.fb.close')}</Button>
                            <FormField field="note" error={action.fieldError('note')} label={t('fo.fb.reopenNote')}><Input maxLength={300} onChange={(e) => setReopen(e.target.value)} value={reopen} /></FormField>
                            <Button disabled={action.busy} onClick={() => void post('reopen', { note: reopen, ...lock })} size="sm" type="button" variant="outline">{t('fo.fb.reopen')}</Button>
                        </div>
                    ) : null}
                </div>
            ) : null}

            <section aria-labelledby="hist-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="hist-h">{t('fo.fb.history')}</h2>
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="feedback-events">
                    {d.events.map((e) => (
                        <li className="flex flex-col py-1" key={e.id}>
                            <span>{t(`fo.fb.event.${e.kind}` as 'fo.fb.event.opened', { text: e.text ?? '' })}</span>
                            <span className="text-xs text-muted-foreground">{t('fo.fb.event.line', { time: format.instant(e.created_at), name: e.actor_name ?? '—' })}</span>
                        </li>
                    ))}
                </ul>
            </section>
        </FrontOfficeShell>
    );
}
