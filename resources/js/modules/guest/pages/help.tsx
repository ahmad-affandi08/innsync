import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { GuestShell } from '@/modules/guest/components/guest-shell';
import { StayProof } from '@/modules/guest/components/stay-proof';
import type { GuestHelp } from '@/modules/guest/lib/guest';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<string, StatusTone> = { open: 'pending', in_progress: 'info', done: 'success', cancelled: 'neutral' };

/** Ask the hotel for something, or tell it about a problem, and follow both. Needs the stay confirmed first. */
export default function HelpPage({ view }: { view: GuestHelp }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const action = useServerAction();
    const [category, setCategory] = useState(view.categories[0] ?? 'housekeeping');
    const [title, setTitle] = useState('');
    const [detail, setDetail] = useState('');
    const [problem, setProblem] = useState('');
    const [problemDetail, setProblemDetail] = useState('');
    const [sent, setSent] = useState<'request' | 'complaint' | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function ask() {
        setError(null);
        const done = await action.run('/g/request', { body: { client_key: newIdempotencyKey(), category, title: title.trim(), detail: detail.trim() === '' ? null : detail.trim() } });

        if (done === null) {
            setError(t('guest.help.notSent'));

            return;
        }

        setSent('request');
        setTitle('');
        setDetail('');
        router.reload({ only: ['view'] });
    }

    async function complain() {
        setError(null);
        const done = await action.run('/g/complaint', { body: { client_key: newIdempotencyKey(), summary: problem.trim(), detail: problemDetail.trim() === '' ? null : problemDetail.trim() } });

        if (done === null) {
            setError(t('guest.help.notSent'));

            return;
        }

        setSent('complaint');
        setProblem('');
        setProblemDetail('');
        router.reload({ only: ['view'] });
    }

    return (
        <>
            <Head title={t('guest.help.title')} />
            <GuestShell hotel={view.hotel} subtitle={view.label} title={t('guest.help.title')}>
                {!view.verified ? (
                    <>
                        <Alert title={t('guest.help.needsProof')} tone="info" />
                        <StayProof locked={view.locked} onDone={() => router.reload({ only: ['view'] })} />
                    </>
                ) : (
                    <>
                        {sent !== null ? <Alert title={sent === 'request' ? t('guest.help.requestSent') : t('guest.help.complaintSent')} tone="success" /> : null}
                        {error !== null ? <Alert title={error} tone="warning" /> : null}
                        <section aria-labelledby="guest-ask-h" className="flex flex-col gap-3 border border-border bg-surface p-3">
                            <h2 className="font-semibold" id="guest-ask-h">{t('guest.help.ask')}</h2>
                            <FormField label={t('guest.help.who')}>
                                <Select onChange={(e) => setCategory(e.target.value)} value={category}>{view.categories.map((c) => <option key={c} value={c}>{t(`guest.help.category.${c}` as MessageKey)}</option>)}</Select>
                            </FormField>
                            <FormField label={t('guest.help.what')}><Input maxLength={120} onChange={(e) => setTitle(e.target.value)} placeholder={t('guest.help.whatHint')} value={title} /></FormField>
                            <FormField label={t('guest.help.detail')}><Input maxLength={500} onChange={(e) => setDetail(e.target.value)} value={detail} /></FormField>
                            <div><Button disabled={title.trim() === ''} loading={action.busy} onClick={() => void ask()} type="button">{t('guest.help.send')}</Button></div>
                        </section>
                        <section aria-labelledby="guest-problem-h" className="flex flex-col gap-3 border border-border bg-surface p-3">
                            <h2 className="font-semibold" id="guest-problem-h">{t('guest.help.problem')}</h2>
                            <p className="text-xs text-muted-foreground">{t('guest.help.problemHint')}</p>
                            <FormField label={t('guest.help.what')}><Input maxLength={120} onChange={(e) => setProblem(e.target.value)} value={problem} /></FormField>
                            <FormField label={t('guest.help.detail')}><Input maxLength={500} onChange={(e) => setProblemDetail(e.target.value)} value={problemDetail} /></FormField>
                            <div><Button disabled={problem.trim() === ''} loading={action.busy} onClick={() => void complain()} type="button" variant="outline">{t('guest.help.sendProblem')}</Button></div>
                        </section>
                        <section aria-labelledby="guest-mine-h" className="flex flex-col gap-2">
                            <h2 className="font-semibold" id="guest-mine-h">{t('guest.help.mine')}</h2>
                            {view.requests.length === 0 ? <EmptyState illustration="checklist" title={t('guest.help.none')} /> : (
                                <ul className="flex flex-col divide-y divide-border border border-border bg-surface" data-testid="guest-requests">
                                    {view.requests.map((r) => (
                                        <li className="flex flex-col gap-1 px-3 py-2 text-sm" key={r.id}>
                                            <div className="flex items-center justify-between gap-2"><span className="font-medium">{r.title}</span><StatusBadge label={t(`guest.help.status.${r.status}` as MessageKey)} tone={TONE[r.status] ?? 'neutral'} /></div>
                                            <p className="text-xs text-muted-foreground">{r.number} · {format.instant(r.sent_at)}</p>
                                            {r.resolution !== null ? <p className="text-xs">{r.resolution}</p> : null}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </>
                )}
            </GuestShell>
        </>
    );
}
