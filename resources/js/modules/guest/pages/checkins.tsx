import { useEffect, useMemo, useState } from 'react';
import qrcode from 'qrcode-generator';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { GuestStaffShell } from '@/modules/guest/components/guest-staff-shell';
import type { CheckInArrival, CheckInDetail, CheckInOverview, CheckInQueue, CheckInRow, PrivacyNotice } from '@/modules/guest/lib/guest';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<string, StatusTone> = { submitted: 'pending', verified: 'success', rejected: 'danger' };

function qrSvg(url: string): string {
    const code = qrcode(0, 'M');
    code.addData(url);
    code.make();

    return code.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
}

type Props = { overview: CheckInOverview; queue: CheckInQueue; notice: { notice: PrivacyNotice; may_define: boolean } };

/** The receptionist's side of the self check-in: what guests sent, who is due and their links, the code at the lobby and the privacy notice. */
export default function CheckInsPage({ notice, overview, queue }: Props) {
    const { locale, t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['overview', 'queue', 'notice'];
    const origin = typeof window === 'undefined' ? '' : window.location.origin;
    const [review, setReview] = useState<CheckInDetail | null>(null);
    const [choice, setChoice] = useState({ room: '', note: '', deposit: '', reason: '' });
    const [link, setLink] = useState<{ number: string; url: string } | null>(null);
    const [draft, setDraft] = useState<{ id: string; en: string; reason: string } | null>(null);
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const lobbyUrl = overview.lobby === null ? null : `${origin}/g/c/${overview.lobby.token}`;
    const lobbySvg = useMemo(() => (lobbyUrl === null ? '' : qrSvg(lobbyUrl)), [lobbyUrl]);
    const money = (minor: number, currency: string) => format.money(minor, currency);

    useEffect(() => { if (review === null) action.clear(); }, [review]); // eslint-disable-line react-hooks/exhaustive-deps

    async function open(row: CheckInRow) {
        const done = await action.run<{ checkin: CheckInDetail }>(`/guest/checkins/${row.id}`, { method: 'GET' });
        if (done === null) return;
        const first = done.checkin.rooms.find((r) => r.ready) ?? done.checkin.rooms[0];
        setChoice({ room: first?.id ?? '', note: '', deposit: done.checkin.deposit.claimed_minor === null ? '' : String(done.checkin.deposit.claimed_minor / 100), reason: '' });
        setReview(done.checkin);
    }

    async function decide(verify: boolean) {
        if (review === null) return;
        const currency = review.deposit.currency;
        const received = choice.deposit.trim() === '' ? 0 : parseMajorToMinor(choice.deposit, currency);
        const done = verify
            ? await action.run(`/guest/checkins/${review.id}/verify`, { body: { lock_version: review.lock_version, room_id: choice.room, key_note: choice.note.trim() === '' ? null : choice.note.trim(), deposit_received_minor: received ?? 0 }, reload })
            : await action.run(`/guest/checkins/${review.id}/reject`, { body: { lock_version: review.lock_version, reason: choice.reason.trim() }, reload });
        if (done !== null) setReview(null);
    }

    async function send(a: CheckInArrival) {
        const done = await action.run<{ token: string }>('/guest/checkins/links', { body: { reservation_id: a.reservation_id }, reload });
        if (done !== null) setLink({ number: a.number, url: `${origin}/g/c/${done.token}` });
    }

    const rowColumns: DataGridColumn<CheckInRow>[] = [
        { id: 'sent', label: t('guest.ck.colSent'), value: (r) => r.submitted_at, cell: (r) => format.instant(r.submitted_at) },
        { id: 'guest', label: t('guest.ck.colGuest'), value: (r) => r.guest_name, rowHeader: true, cell: (r) => <span>{r.guest_name}<span className="block text-xs text-muted-foreground">{r.number} · {format.date(r.arrival)} – {format.date(r.departure)}</span></span> },
        { id: 'deposit', label: t('guest.ck.colDeposit'), value: (r) => r.deposit.claimed_minor ?? 0, cell: (r) => (r.deposit.claimed_minor === null ? '—' : t('guest.ck.claimed', { amount: money(r.deposit.claimed_minor, r.deposit.currency) })) },
        { id: 'status', label: t('guest.ck.colStatus'), value: (r) => r.status, cell: (r) => <span><StatusBadge label={t(`guest.ck.status.${r.status}` as MessageKey)} tone={TONE[r.status] ?? 'neutral'} />{r.room_number !== null ? <span className="block text-xs text-muted-foreground">{t('guest.ck.room', { room: r.room_number })}</span> : null}{r.reject_reason !== null ? <span className="block text-xs text-muted-foreground">{r.reject_reason}</span> : null}</span> },
        { id: 'act', label: '', value: () => '', sortable: false, cell: (r) => (r.status === 'submitted' && queue.may_read_identity ? <Button disabled={action.busy} onClick={() => void open(r)} size="sm" type="button">{t('guest.ck.review')}</Button> : null) },
    ];

    const arrivalColumns: DataGridColumn<CheckInArrival>[] = [
        { id: 'arrival', label: t('guest.ck.colArrival'), value: (a) => a.arrival, cell: (a) => format.date(a.arrival) },
        { id: 'guest', label: t('guest.ck.colGuest'), value: (a) => a.guest_name, rowHeader: true, cell: (a) => <span>{a.guest_name}<span className="block text-xs text-muted-foreground">{a.number}{a.room_type !== null ? ` · ${a.room_type}` : ''}</span></span> },
        { id: 'state', label: t('guest.ck.colStatus'), value: (a) => a.checkin?.status ?? (a.link === null ? 'none' : 'link'), cell: (a) => (a.checkin !== null ? <StatusBadge label={t(`guest.ck.status.${a.checkin.status}` as MessageKey)} tone={TONE[a.checkin.status] ?? 'neutral'} /> : a.link !== null ? <span className="text-xs text-muted-foreground">{t('guest.ck.linkUntil', { time: format.instant(a.link.expires_at) })}</span> : <span className="text-xs text-muted-foreground">{t('guest.ck.noLink')}</span>) },
        {
            id: 'act', label: '', value: () => '', sortable: false,
            cell: (a) => (
                <span className="flex flex-wrap gap-2">
                    {a.link !== null ? <Button onClick={() => setLink({ number: a.number, url: `${origin}/g/c/${a.link?.token ?? ''}` })} size="sm" type="button" variant="outline">{t('guest.ck.showLink')}</Button> : null}
                    {a.checkin === null || a.checkin.status === 'rejected' ? <Button disabled={action.busy} onClick={() => void send(a)} size="sm" type="button">{a.link === null ? t('guest.ck.makeLink') : t('guest.ck.renewLink')}</Button> : null}
                    {a.link !== null && a.checkin === null ? <Button disabled={action.busy} onClick={() => void action.run(`/guest/checkins/links/${a.link?.id ?? ''}/revoke`, { body: {}, reload })} size="sm" type="button" variant="outline">{t('guest.ck.revoke')}</Button> : null}
                </span>
            ),
        },
    ];

    const body = locale === 'id' ? notice.notice.body_id : notice.notice.body_en;

    return (
        <GuestStaffShell description={t('guest.ck.description')} title={t('guest.ck.title')} wide>
            {action.error !== null && review === null && draft === null ? failure : null}
            {!queue.may_read_identity ? <Alert title={t('guest.ck.noIdentity')} tone="info" /> : null}
            <Tabs defaultValue="queue">
                <TabsList>
                    <TabsTrigger value="queue">{t('guest.ck.tabQueue', { count: queue.waiting.length })}</TabsTrigger>
                    <TabsTrigger value="arrivals">{t('guest.ck.tabArrivals')}</TabsTrigger>
                    <TabsTrigger value="lobby">{t('guest.ck.tabLobby')}</TabsTrigger>
                    <TabsTrigger value="notice">{t('guest.ck.tabNotice')}</TabsTrigger>
                </TabsList>

                <TabsContent value="queue">
                    <div className="flex flex-col gap-4">
                        <DataGrid caption={t('guest.ck.tabQueue', { count: queue.waiting.length })} columns={rowColumns} empty={<EmptyState illustration="reception" title={t('guest.ck.noneWaiting')} />} getRowId={(r) => r.id} id="guest.ck.waiting" rows={queue.waiting} testId="checkin-queue" />
                        {queue.verified.length + queue.rejected.length > 0 ? (
                            <>
                                <h2 className="text-sm font-semibold">{t('guest.ck.decided')}</h2>
                                <DataGrid caption={t('guest.ck.decided')} columns={rowColumns} getRowId={(r) => r.id} id="guest.ck.decided" rows={[...queue.verified, ...queue.rejected]} testId="checkin-decided" />
                            </>
                        ) : null}
                    </div>
                </TabsContent>

                <TabsContent value="arrivals">
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('guest.ck.arrivalsHint')}</p>
                        <DataGrid caption={t('guest.ck.tabArrivals')} columns={arrivalColumns} empty={<EmptyState illustration="reception" title={t('guest.ck.noArrivals')} />} getRowId={(a) => a.reservation_id} id="guest.ck.arrivals" rows={overview.arrivals} testId="checkin-arrivals" />
                    </div>
                </TabsContent>

                <TabsContent value="lobby">
                    <div className="flex flex-col gap-3" data-testid="checkin-lobby-code">
                        <p className="text-sm text-muted-foreground">{t('guest.ck.lobbyHint')}</p>
                        {overview.lobby === null ? <EmptyState illustration="reception" title={t('guest.ck.noLobby')} /> : (
                            <div className="flex flex-col items-start gap-2">
                                <div aria-label={t('guest.ck.lobbyCode')} className="w-full max-w-[14rem]" dangerouslySetInnerHTML={{ __html: lobbySvg }} role="img" />
                                <p className="text-xs text-muted-foreground">{t('guest.ck.lobbyUntil', { time: format.instant(overview.lobby.expires_at) })}</p>
                            </div>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button disabled={action.busy} onClick={() => void action.run('/guest/checkins/lobby', { body: { renew: false }, reload })} type="button" variant={overview.lobby === null ? 'default' : 'outline'}>{t('guest.ck.lobbyMake')}</Button>
                            {overview.lobby !== null ? <Button onClick={() => window.print()} type="button" variant="outline">{t('guest.qr.printNow')}</Button> : null}
                            {overview.lobby !== null ? <Button disabled={action.busy} onClick={() => void action.run('/guest/checkins/lobby', { body: { renew: true }, reload })} type="button" variant="outline">{t('guest.ck.lobbyRenew')}</Button> : null}
                        </div>
                    </div>
                </TabsContent>

                <TabsContent value="notice">
                    <div className="flex flex-col gap-3" data-testid="checkin-notice-admin">
                        <p className="text-sm font-medium">{notice.notice.version === 0 ? t('guest.ck.noticeBaseline') : t('guest.ck.noticeVersion', { version: notice.notice.version })}</p>
                        <p className="whitespace-pre-line border border-border bg-surface p-3 text-sm">{body}</p>
                        {notice.may_define ? <div><Button onClick={() => { action.clear(); setDraft({ id: notice.notice.body_id, en: notice.notice.body_en, reason: '' }); }} type="button" variant="outline">{t('guest.ck.noticeWrite')}</Button></div> : null}
                    </div>
                </TabsContent>
            </Tabs>

            <Dialog
                footer={<Button onClick={() => setLink(null)} type="button">{t('guest.ck.done')}</Button>}
                onClose={() => setLink(null)}
                open={link !== null}
                title={link === null ? '' : t('guest.ck.linkTitle', { number: link.number })}
            >
                {link !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('guest.ck.linkHint')}</p>
                        <Input aria-label={t('guest.ck.linkTitle', { number: link.number })} onFocus={(e) => e.currentTarget.select()} readOnly value={link.url} />
                        <div><Button onClick={() => void navigator.clipboard?.writeText(link.url)} size="sm" type="button" variant="outline">{t('guest.ck.copy')}</Button></div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={review === null ? undefined : (
                    <>
                        <Button disabled={action.busy} onClick={() => setReview(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                        <Button disabled={action.busy || choice.reason.trim() === ''} onClick={() => void decide(false)} type="button" variant="outline">{t('guest.ck.reject')}</Button>
                        <Button disabled={choice.room === ''} loading={action.busy} onClick={() => void decide(true)} type="button">{t('guest.ck.verify')}</Button>
                    </>
                )}
                onClose={() => setReview(null)}
                open={review !== null}
                title={review === null ? '' : t('guest.ck.reviewTitle', { number: review.number })}
            >
                {review !== null && (
                    <div className="flex flex-col gap-3">
                        {failure}
                        <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]" data-testid="checkin-review">
                            <dt className="text-muted-foreground">{t('guest.checkin.fullName')}</dt><dd className="font-medium">{review.data?.full_name}</dd>
                            <dt className="text-muted-foreground">{t('guest.checkin.nationality')}</dt><dd>{review.data?.nationality}</dd>
                            <dt className="text-muted-foreground">{t('guest.checkin.idType')}</dt><dd>{review.data === null ? '' : t(`guest.checkin.idType.${review.data.id_type}` as MessageKey)} · <span className="font-mono">{review.data?.id_number}</span></dd>
                            {review.data?.id_valid_until ? <><dt className="text-muted-foreground">{t('guest.checkin.idValidUntil')}</dt><dd>{format.date(review.data.id_valid_until)}</dd></> : null}
                            {review.data?.visa_number ? <><dt className="text-muted-foreground">{t('guest.checkin.visa')}</dt><dd className="font-mono">{review.data.visa_number}</dd></> : null}
                            <dt className="text-muted-foreground">{t('guest.checkin.address')}</dt><dd className="break-words">{review.data?.address}</dd>
                            {review.data?.phone ? <><dt className="text-muted-foreground">{t('guest.checkin.phone')}</dt><dd>{review.data.phone}</dd></> : null}
                            {review.data?.email ? <><dt className="text-muted-foreground">{t('guest.checkin.email')}</dt><dd>{review.data.email}</dd></> : null}
                            <dt className="text-muted-foreground">{t('guest.ck.guests')}</dt><dd>{t('guest.ck.guestsValue', { adults: review.adults, children: review.children })}</dd>
                            <dt className="text-muted-foreground">{t('guest.ck.consent')}</dt><dd>{t('guest.ck.consentValue', { version: review.consent.version, time: format.instant(review.consent.at) })}</dd>
                        </dl>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {review.has_photo ? <img alt={t('guest.ck.photoAlt')} className="max-h-64 w-full border border-border object-contain" src={`/guest/checkins/${review.id}/photo`} /> : null}
                            {review.has_signature ? <img alt={t('guest.ck.signatureAlt')} className="max-h-64 w-full border border-border bg-white object-contain" src={`/guest/checkins/${review.id}/signature`} /> : null}
                        </div>
                        <FormField error={action.fieldError('room_id')} label={t('guest.ck.chooseRoom')}>
                            <Select onChange={(e) => setChoice({ ...choice, room: e.target.value })} value={choice.room}>
                                {review.rooms.map((r) => <option key={r.id} value={r.id}>{r.ready ? t('guest.ck.roomReady', { number: r.number }) : t('guest.ck.roomNotReady', { number: r.number })}</option>)}
                            </Select>
                        </FormField>
                        {review.deposit.claimed_minor !== null ? <Alert title={t('guest.ck.depositClaim', { amount: money(review.deposit.claimed_minor, review.deposit.currency), reference: review.deposit.reference ?? '—' })} tone="info" /> : null}
                        <FormField error={action.fieldError('deposit_received_minor')} hint={t('guest.ck.depositHint', { required: money(review.deposit.required_minor, review.deposit.currency), held: money(review.deposit.held_minor, review.deposit.currency) })} label={t('guest.ck.depositReceived')}><Input inputMode="decimal" onChange={(e) => setChoice({ ...choice, deposit: e.target.value })} value={choice.deposit} /></FormField>
                        <FormField error={action.fieldError('key_note')} label={t('guest.ck.keyNote')}><Input maxLength={300} onChange={(e) => setChoice({ ...choice, note: e.target.value })} value={choice.note} /></FormField>
                        <FormField error={action.fieldError('reason')} hint={t('guest.ck.rejectHint')} label={t('guest.ck.rejectReason')}><Input maxLength={300} onChange={(e) => setChoice({ ...choice, reason: e.target.value })} value={choice.reason} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setDraft(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => { if (draft !== null) void action.run('/guest/checkins/notice', { body: { body_id: draft.id, body_en: draft.en, reason: draft.reason.trim() }, reload }).then((r) => { if (r !== null) setDraft(null); }); }} type="button">{t('guest.ck.noticePublish')}</Button></>}
                onClose={() => setDraft(null)}
                open={draft !== null}
                title={t('guest.ck.noticeWrite')}
            >
                {draft !== null && (
                    <div className="flex flex-col gap-3">
                        {failure}
                        <p className="text-sm text-muted-foreground">{t('guest.ck.noticeHint')}</p>
                        <FormField error={action.fieldError('body_id')} label={t('guest.ck.noticeId')}><Textarea maxLength={4000} onChange={(e) => setDraft({ ...draft, id: e.target.value })} rows={6} value={draft.id} /></FormField>
                        <FormField error={action.fieldError('body_en')} label={t('guest.ck.noticeEn')}><Textarea maxLength={4000} onChange={(e) => setDraft({ ...draft, en: e.target.value })} rows={6} value={draft.en} /></FormField>
                        <FormField error={action.fieldError('reason')} label={t('guest.ck.noticeReason')}><Input maxLength={300} onChange={(e) => setDraft({ ...draft, reason: e.target.value })} value={draft.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </GuestStaffShell>
    );
}
