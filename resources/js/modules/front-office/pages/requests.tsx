import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ConfirmDialog, Dialog } from '@/components/ui/dialog';
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

type Req = {
    due_at: string | null; id: string; number: string; room: string; category: string; priority: string; title: string; detail: string | null; status: string; recorded_status: string; housekeeping_state: string | null; work_order: { number: string; state: string } | null;
    resolution: string | null; created_at: string; lock_version: number;
};
type Props = {
    queue: { requests: Req[]; may_manage: boolean; categories: string[] }; filters: { status: string; category: string; room: string };
    in_house: { stay_id: string; room: string; guest: string }[];
};

const tone: Record<string, StatusTone> = { open: 'warning', in_progress: 'info', done: 'success', cancelled: 'neutral' };

/** What in-house guests ask for, by department (FR-FO-030). */
export default function RequestsPage({ filters, in_house: inHouse, queue }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [status, setStatus] = useState(filters.status);
    const [category, setCategory] = useState(filters.category);
    const [form, setForm] = useState<{ stay: string; category: string; title: string; detail: string; urgent: boolean; due: string } | null>(null);
    const [finish, setFinish] = useState<{ req: Req; kind: 'complete' | 'cancel'; text: string } | null>(null);
    const [intent, setIntent] = useState(() => newIdempotencyKey());
    const reload = ['queue', 'in_house'];

    function closeAll() {
        action.clear();
        setForm(null);
        setFinish(null);
    }

    async function save() {
        if (form === null) return;
        const done = await action.run('/front-office/requests', { idempotencyKey: intent, body: { stay_id: form.stay, category: form.category, title: form.title, detail: form.detail.trim() || null, urgent: form.urgent, due_in_minutes: form.due === '' ? null : Number(form.due) }, reload });
        if (done !== null) { setIntent(newIdempotencyKey()); closeAll(); }
    }

    async function start(r: Req) {
        await action.run(`/front-office/requests/${r.id}/start`, { body: { lock_version: r.lock_version }, reload });
    }

    async function conclude() {
        if (finish === null) return;
        const body = finish.kind === 'complete' ? { lock_version: finish.req.lock_version, resolution: finish.text.trim() || null } : { lock_version: finish.req.lock_version, reason: finish.text.trim() };
        const done = await action.run(`/front-office/requests/${finish.req.id}/${finish.kind}`, { body, reload });
        if (done !== null) closeAll();
    }

    const error = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;

    return (
        <FrontOfficeShell description={t('fo.req.description')} title={t('fo.req.title')} wide>
            <div className="flex flex-wrap items-end justify-between gap-3">
                <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); router.get('/front-office/requests', { status, ...(category !== '' ? { category } : {}), ...(filters.room !== '' ? { room: filters.room } : {}) }); }}>
                    <FormField label={t('fo.req.filter.status')}>
                        <Select onChange={(e) => setStatus(e.target.value)} value={status}>
                            <option value="active">{t('fo.req.filter.active')}</option><option value="">{t('fo.req.filter.all')}</option>
                            {['open', 'in_progress', 'done', 'cancelled'].map((s) => <option key={s} value={s}>{t(`fo.req.status.${s}` as 'fo.req.status.open')}</option>)}
                        </Select>
                    </FormField>
                    <FormField label={t('fo.req.filter.category')}>
                        <Select onChange={(e) => setCategory(e.target.value)} value={category}>
                            <option value="">{t('fo.req.filter.all')}</option>
                            {queue.categories.map((c) => <option key={c} value={c}>{t(`fo.req.category.${c}` as 'fo.req.category.other')}</option>)}
                        </Select>
                    </FormField>
                    <Button type="submit" variant="outline">{t('fo.req.filter.apply')}</Button>
                </form>
                {queue.may_manage ? <Button onClick={() => { action.clear(); setForm({ stay: '', category: 'housekeeping', title: '', detail: '', urgent: false, due: '' }); }} size="sm" type="button">{t('fo.req.new')}</Button> : null}
            </div>
            {form === null && finish === null ? error : null}

            {queue.requests.length === 0 ? <EmptyState title={t('fo.req.empty')} /> : (
                <ul className="divide-y divide-border border-y border-border" data-testid="request-list">
                    {queue.requests.map((r) => (
                        <li className="flex flex-col gap-1 py-3 text-sm" data-testid={`request-${r.number}`} key={r.id}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium">{t('fo.req.row', { number: r.number, room: r.room })} · {t(`fo.req.category.${r.category}` as 'fo.req.category.other')}</span>
                                <span className="flex items-center gap-2">
                                    {r.priority === 'urgent' ? <StatusBadge label={t('fo.req.urgentTag')} tone="danger" /> : null}
                                    <StatusBadge label={t(`fo.req.status.${r.status}` as 'fo.req.status.open')} tone={tone[r.status] ?? 'neutral'} />
                                </span>
                            </div>
                            <p>{r.title}{r.due_at !== null ? ` · ${t('fo.req.dueBy', { time: format.instant(r.due_at) })}` : ''}</p>
                            {r.detail !== null ? <p className="text-xs text-muted-foreground">{r.detail}</p> : null}
                            <p className="text-xs text-muted-foreground">{format.instant(r.created_at)}{r.housekeeping_state !== null ? ` · ${t('fo.req.hk', { state: t(`fo.req.status.${r.housekeeping_state}` as 'fo.req.status.open') })}` : ''}{r.work_order !== null ? ` · ${t('fo.req.wo', { number: r.work_order.number, state: t(`fo.req.status.${r.work_order.state}` as 'fo.req.status.open') })}` : ''}{r.resolution !== null ? ` · ${r.resolution}` : ''}</p>
                            {queue.may_manage && (r.recorded_status === 'open' || r.recorded_status === 'in_progress') ? (
                                <div className="flex flex-wrap gap-2">
                                    {r.recorded_status === 'open' ? <Button disabled={action.busy} onClick={() => void start(r)} size="sm" type="button" variant="outline">{t('fo.req.start')}</Button> : null}
                                    <Button onClick={() => { action.clear(); setFinish({ req: r, kind: 'complete', text: '' }); }} size="sm" type="button" variant="outline">{t('fo.req.complete')}</Button>
                                    <Button onClick={() => { action.clear(); setFinish({ req: r, kind: 'cancel', text: '' }); }} size="sm" type="button" variant="outline">{t('fo.req.cancel')}</Button>
                                </div>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}

            <Dialog
                footer={<><Button disabled={action.busy} onClick={closeAll} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void save()} type="button">{t('fo.req.save')}</Button></>}
                onClose={closeAll} open={form !== null} title={t('fo.req.dialogTitle')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        <FormField field="stay_id" error={action.fieldError('stay_id')} label={t('fo.req.room')}>
                            <Select onChange={(e) => setForm({ ...form, stay: e.target.value })} value={form.stay}>
                                <option value="">{t('fo.req.chooseRoom')}</option>
                                {inHouse.map((s) => <option key={s.stay_id} value={s.stay_id}>{s.room} · {s.guest}</option>)}
                            </Select>
                        </FormField>
                        <FormField field="category" error={action.fieldError('category')} label={t('fo.req.category')}>
                            <Select onChange={(e) => setForm({ ...form, category: e.target.value })} value={form.category}>
                                {queue.categories.map((c) => <option key={c} value={c}>{t(`fo.req.category.${c}` as 'fo.req.category.other')}</option>)}
                            </Select>
                        </FormField>
                        <FormField field="title" error={action.fieldError('title')} label={t('fo.req.titleLabel')}><Input maxLength={120} onChange={(e) => setForm({ ...form, title: e.target.value })} value={form.title} /></FormField>
                        <FormField field="detail" error={action.fieldError('detail')} label={t('fo.req.detail')}><Input maxLength={500} onChange={(e) => setForm({ ...form, detail: e.target.value })} value={form.detail} /></FormField>
                        <FormField field="due_in_minutes" error={action.fieldError('due_in_minutes')} label={t('fo.req.due')}>
                            <Select onChange={(e) => setForm({ ...form, due: e.target.value })} value={form.due}>
                                <option value="">{t('fo.req.due.none')}</option>
                                {['15', '30', '60', '120'].map((m) => <option key={m} value={m}>{t(`fo.req.due.${m}` as 'fo.req.due.15')}</option>)}
                            </Select>
                        </FormField>
                        <label className="flex items-center gap-2 text-sm"><input checked={form.urgent} onChange={(e) => setForm({ ...form, urgent: e.target.checked })} type="checkbox" />{t('fo.req.urgent')}</label>
                    </div>
                )}
            </Dialog>

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')} confirmLabel={finish?.kind === 'cancel' ? t('fo.req.cancel') : t('fo.req.complete')} consequence="" onCancel={closeAll} onConfirm={() => void conclude()}
                open={finish !== null} pending={action.busy} title={finish?.kind === 'cancel' ? t('fo.req.cancel') : t('fo.req.complete')}
            >
                {finish !== null && (
                    <div className="flex flex-col gap-3">
                        {error}
                        {finish.kind === 'cancel' ? (
                            <FormField error={action.fieldError('reason')} field="reason" label={t('fo.req.cancelReason')}>
                                <Input maxLength={300} onChange={(e) => setFinish({ ...finish, text: e.target.value })} value={finish.text} />
                            </FormField>
                        ) : (
                            <FormField error={action.fieldError('resolution')} field="resolution" label={t('fo.req.resolution')}>
                                <Input maxLength={300} onChange={(e) => setFinish({ ...finish, text: e.target.value })} value={finish.text} />
                            </FormField>
                        )}
                    </div>
                )}
            </ConfirmDialog>
        </FrontOfficeShell>
    );
}
