import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { AnnouncementOverview } from '@/modules/hr/lib/hr';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

/** The notice board of the staff and the policies they must read and confirm. */
export default function AnnouncementsPage({ overview }: { overview: AnnouncementOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [publish, setPublish] = useState<{ kind: string; title: string; body: string; audience: string; ack: boolean; expires: string } | null>(null);
    const [file, setFile] = useState<File | null>(null);
    const [fileKey, setFileKey] = useState(0);
    const [withdraw, setWithdraw] = useState<{ id: string; lock: number; reason: string } | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const audience = (a: string) => (a === 'all' ? t('hr.ann.everyone') : label('hr.department', a));
    const reload = ['overview'];
    const toConfirm = overview.feed.filter((a) => a.requires_ack && !a.acknowledged).length;

    async function send() {
        if (publish === null) return;
        const body = new FormData();
        body.set('kind', publish.kind);
        body.set('title', publish.title.trim());
        body.set('body', publish.body.trim());
        body.set('audience', publish.audience);
        body.set('requires_ack', publish.ack ? '1' : '0');
        if (publish.expires !== '') body.set('expires_on', publish.expires);
        if (file !== null) body.set('document', file);
        const result = await action.run('/hr/announcements', { idempotencyKey: newIdempotencyKey(), body, reload });

        if (result !== null) {
            setPublish(null);
            setFile(null);
            setFileKey((k) => k + 1);
        }
    }

    async function doWithdraw() {
        if (withdraw === null) return;
        const result = await action.run(`/hr/announcements/${withdraw.id}/withdraw`, { body: { reason: withdraw.reason.trim(), lock_version: withdraw.lock }, reload });

        if (result !== null) setWithdraw(null);
    }

    return (
        <HrShell actions={overview.may.manage ? <Button onClick={() => { action.clear(); setFile(null); setPublish({ kind: 'announcement', title: '', body: '', audience: 'all', ack: false, expires: '' }); }} type="button">{t('hr.ann.publish')}</Button> : undefined} description={t('hr.ann.description')} title={t('hr.ann.title')}>
            {action.error !== null && publish === null && withdraw === null ? failure : null}
            <Tabs defaultValue="feed">
                <TabsList aria-label={t('hr.ann.title')}>
                    <TabsTrigger value="feed">{t('hr.ann.feedTab', { n: toConfirm })}</TabsTrigger>
                    {overview.may.manage ? <TabsTrigger value="board">{t('hr.ann.boardTab')}</TabsTrigger> : null}
                </TabsList>
                <TabsContent className="flex flex-col gap-3" value="feed">
                    {!overview.linked ? <Alert title={t('hr.att.noEmployee')} tone="warning" /> : null}
                    {overview.feed.length === 0 ? <EmptyState illustration="checklist" title={t('hr.ann.noneFeed')} /> : overview.feed.map((a) => (
                        <article className="flex flex-col gap-2 border border-border bg-surface p-4" data-testid="hr-ann-item" key={a.id}>
                            <header className="flex flex-wrap items-center gap-2">
                                <h2 className="text-base font-semibold">{a.title}</h2>
                                <StatusBadge label={label('hr.ann.kinds', a.kind)} tone={a.kind === 'policy' ? 'info' : 'neutral'} />
                                {a.requires_ack ? <StatusBadge label={a.acknowledged ? t('hr.ann.confirmed') : t('hr.ann.mustConfirm')} tone={a.acknowledged ? 'success' : 'warning'} /> : null}
                            </header>
                            <p className="text-xs text-muted-foreground">{format.date(a.published_at.slice(0, 10))}{a.expires_on !== null ? ` · ${t('hr.ann.expires', { date: format.date(a.expires_on) })}` : ''}</p>
                            <p className="whitespace-pre-line text-sm">{a.body}</p>
                            <div className="flex flex-wrap gap-2">
                                {a.has_document ? <Button asChild size="sm" variant="outline"><a href={`/hr/announcements/${a.id}/document`} onClick={() => { if (!a.read) void action.run(`/hr/announcements/${a.id}/read`, { body: {}, reload }); }} rel="noreferrer" target="_blank">{t('hr.ann.document')}</a></Button> : null}
                                {!a.read ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/announcements/${a.id}/read`, { body: {}, reload })} size="sm" type="button" variant="outline">{t('hr.ann.markRead')}</Button> : null}
                                {a.requires_ack && !a.acknowledged ? <Button disabled={action.busy} onClick={() => void action.run(`/hr/announcements/${a.id}/acknowledge`, { body: {}, reload })} size="sm" type="button">{t('hr.ann.confirm')}</Button> : null}
                            </div>
                        </article>
                    ))}
                </TabsContent>
                {overview.may.manage ? (
                    <TabsContent className="flex flex-col gap-3" value="board">
                        {(overview.board ?? []).length === 0 ? <EmptyState illustration="checklist" title={t('hr.ann.noneBoard')} /> : (overview.board ?? []).map((a) => (
                            <article className="flex flex-col gap-2 border border-border bg-surface p-4" data-testid="hr-ann-board-item" key={a.id}>
                                <header className="flex flex-wrap items-center gap-2">
                                    <h2 className="text-base font-semibold">{a.title}</h2>
                                    <StatusBadge label={label('hr.ann.kinds', a.kind)} tone="neutral" />
                                    <StatusBadge label={a.status === 'published' ? t('hr.ann.published') : t('hr.ann.withdrawn')} tone={a.status === 'published' ? 'success' : 'neutral'} />
                                </header>
                                <p className="text-xs text-muted-foreground">{audience(a.audience)} · {t('hr.ann.counts', { read: a.read, size: a.audience_size, confirmed: a.acknowledged })}{a.withdraw_reason !== null ? ` · ${t('hr.conduct.revokedBecause', { reason: a.withdraw_reason })}` : ''}</p>
                                {a.pending.length > 0 ? <p className="text-sm">{t('hr.ann.pending', { names: a.pending.map((p) => p.name).join(', ') })}</p> : null}
                                {a.status === 'published' ? <div><Button onClick={() => { action.clear(); setWithdraw({ id: a.id, lock: a.lock_version, reason: '' }); }} size="sm" type="button" variant="outline">{t('hr.ann.withdraw')}</Button></div> : null}
                            </article>
                        ))}
                    </TabsContent>
                ) : null}
            </Tabs>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setPublish(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={publish?.title.trim() === '' || publish?.body.trim() === ''} loading={action.busy} onClick={() => void send()} type="button">{t('hr.ann.publish')}</Button></>}
                onClose={() => setPublish(null)}
                open={publish !== null}
                title={t('hr.ann.publish')}
            >
                {publish !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {failure !== null ? <div className="sm:col-span-2">{failure}</div> : null}
                        <FormField error={action.fieldError('kind')} field="kind" label={t('hr.conduct.kind')}><Select onChange={(e) => setPublish({ ...publish, kind: e.target.value, ack: e.target.value === 'policy' })} value={publish.kind}>{overview.kinds.map((k) => <option key={k} value={k}>{label('hr.ann.kinds', k)}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('audience')} field="audience" label={t('hr.ann.audience')}><Select onChange={(e) => setPublish({ ...publish, audience: e.target.value })} value={publish.audience}><option value="all">{t('hr.ann.everyone')}</option>{overview.departments.map((d) => <option key={d} value={d}>{label('hr.department', d)}</option>)}</Select></FormField>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('title')} field="title" label={t('hr.ann.titleLabel')}><Input maxLength={120} onChange={(e) => setPublish({ ...publish, title: e.target.value })} value={publish.title} /></FormField></div>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('body')} field="body" label={t('hr.ann.text')}><textarea className="min-h-32 w-full border border-border bg-surface p-2 text-sm" maxLength={4000} onChange={(e) => setPublish({ ...publish, body: e.target.value })} value={publish.body} /></FormField></div>
                        <FormField error={action.fieldError('expires_on')} field="expires_on" hint={t('hr.ann.expiresHint')} label={t('hr.ann.expiresOn')}><DatePicker min={overview.today} onChange={(e) => setPublish({ ...publish, expires: e.target.value })} value={publish.expires} /></FormField>
                        <label className="flex items-center gap-2 self-end text-sm"><input checked={publish.ack} onChange={(e) => setPublish({ ...publish, ack: e.target.checked })} type="checkbox" />{t('hr.ann.requireConfirm')}</label>
                        <div className="sm:col-span-2"><FormField error={action.fieldError('document')} field="document" hint={t('hr.conduct.letterHint')} label={t('hr.ann.document')}><Input accept="application/pdf,image/jpeg,image/png" key={fileKey} onChange={(e) => setFile(e.target.files?.[0] ?? null)} type="file" /></FormField></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setWithdraw(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={withdraw?.reason.trim() === ''} loading={action.busy} onClick={() => void doWithdraw()} type="button">{t('hr.ann.withdraw')}</Button></>}
                onClose={() => setWithdraw(null)}
                open={withdraw !== null}
                title={t('hr.ann.withdraw')}
            >
                {withdraw !== null && (
                    <div className="grid gap-3">
                        {failure !== null ? failure : null}
                        <p className="text-sm text-muted-foreground">{t('hr.ann.withdrawHint')}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('hr.att.reasonShort')}><Input maxLength={200} onChange={(e) => setWithdraw({ ...withdraw, reason: e.target.value })} value={withdraw.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </HrShell>
    );
}
