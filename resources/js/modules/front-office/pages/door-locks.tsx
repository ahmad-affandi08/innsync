import { useState } from 'react';

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
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Job = { id: string; kind: string; room_number: string; reservation_number: string | null; key_count: number; valid_until: string | null; status: string; attempts: number; message: string | null; created_at: string };
type Overview = { settings: { enabled: boolean; vendor: string; key_count: number; token_hint: string | null; lock_version: number | null }; vendors: string[]; counts: Record<string, number>; jobs: Job[] };

const TONE: Record<string, StatusTone> = { pending: 'info', sent: 'info', done: 'success', manual: 'neutral', failed: 'danger' };

/** Door locks that follow the stay: the settings, the token of the program beside the lock system, and the list of key jobs. */
export default function DoorLocksPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ enabled: overview.settings.enabled, vendor: overview.settings.vendor, keys: String(overview.settings.key_count) });
    const [token, setToken] = useState<string | null>(null);
    const [settle, setSettle] = useState<{ id: string; reason: string } | null>(null);
    const reload = ['overview'];

    async function save() {
        await action.run('/front-office/door-locks', { method: 'PUT', body: { enabled: form.enabled, vendor: form.vendor, key_count: Number(form.keys) || 2, lock_version: overview.settings.lock_version }, reload });
    }

    async function makeToken() {
        const done = await action.run<{ token: string }>('/front-office/door-locks/token', { body: {}, reload });
        if (done !== null) setToken(done.token);
    }

    async function doSettle() {
        if (settle === null) return;
        const done = await action.run(`/front-office/door-locks/jobs/${settle.id}/settle`, { body: { reason: settle.reason.trim() }, reload });
        if (done !== null) setSettle(null);
    }

    const columns: DataGridColumn<Job>[] = [
        { id: 'created', label: t('fo.lock.col.created'), value: (j) => j.created_at, cell: (j) => format.instant(`${j.created_at.replace(' ', 'T')}Z`) },
        { id: 'kind', label: t('fo.lock.col.kind'), value: (j) => j.kind, filter: 'select', filterLabel: (v) => t(`fo.lock.kind.${v}` as 'fo.lock.kind.issue'), cell: (j) => t(`fo.lock.kind.${j.kind}` as 'fo.lock.kind.issue') },
        { id: 'room', label: t('fo.lock.col.room'), value: (j) => j.room_number, rowHeader: true },
        { id: 'reservation', label: t('fo.lock.col.reservation'), value: (j) => j.reservation_number ?? '' },
        { id: 'keys', label: t('fo.lock.col.keys'), align: 'right', value: (j) => j.key_count },
        { id: 'until', label: t('fo.lock.col.until'), value: (j) => j.valid_until ?? '', cell: (j) => (j.valid_until === null ? '—' : format.date(j.valid_until)) },
        { id: 'status', label: t('fo.lock.col.status'), value: (j) => j.status, filter: 'select', filterLabel: (v) => t(`fo.lock.status.${v}` as 'fo.lock.status.done'), cell: (j) => <StatusBadge label={t(`fo.lock.status.${j.status}` as 'fo.lock.status.done')} tone={TONE[j.status] ?? 'neutral'} /> },
        { id: 'message', label: t('fo.lock.col.message'), value: (j) => j.message ?? '', cell: (j) => j.message ?? '' },
        {
            id: 'actions', label: '', value: () => '', sortable: false,
            cell: (j) => (['pending', 'sent', 'failed'].includes(j.status)
                ? <span className="flex gap-2">
                    {j.status !== 'pending' ? <Button disabled={action.busy} onClick={() => void action.run(`/front-office/door-locks/jobs/${j.id}/retry`, { body: {}, reload })} size="sm" type="button" variant="outline">{t('fo.lock.retry')}</Button> : null}
                    <Button onClick={() => { action.clear(); setSettle({ id: j.id, reason: '' }); }} size="sm" type="button" variant="outline">{t('fo.lock.settle')}</Button>
                </span>
                : null),
        },
    ];

    return (
        <FrontOfficeShell description={t('fo.lock.description')} title={t('fo.lock.title')} wide>
            <Alert title={t('fo.lock.howTitle')} tone="info">{t('fo.lock.how')}</Alert>
            {action.error !== null && settle === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {token !== null ? <Alert title={t('fo.lock.tokenTitle')} tone="warning"><code className="break-all select-all" data-testid="door-lock-token">{token}</code><span className="mt-1 block">{t('fo.lock.tokenOnce')}</span></Alert> : null}

            <section aria-labelledby="dl-settings" className="flex flex-col gap-3 border border-border bg-surface p-4">
                <h2 className="font-semibold" id="dl-settings">{t('fo.lock.settings')}</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                    <FormField error={action.fieldError('vendor')} field="vendor" label={t('fo.lock.vendor')}>
                        <Select onChange={(e) => setForm({ ...form, vendor: e.target.value })} value={form.vendor}>
                            {overview.vendors.map((v) => <option key={v} value={v}>{t(`fo.lock.vendor.${v}` as 'fo.lock.vendor.other')}</option>)}
                        </Select>
                    </FormField>
                    <FormField error={action.fieldError('key_count')} field="key_count" hint={t('fo.lock.keysHint')} label={t('fo.lock.keys')}>
                        <Input inputMode="numeric" maxLength={1} onChange={(e) => setForm({ ...form, keys: e.target.value })} value={form.keys} />
                    </FormField>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm"><input checked={form.enabled} onChange={(e) => setForm({ ...form, enabled: e.target.checked })} type="checkbox" />{t('fo.lock.enabled')}</label>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('fo.lock.save')}</Button>
                    <Button disabled={action.busy} onClick={() => void makeToken()} type="button" variant="outline">{overview.settings.token_hint === null ? t('fo.lock.makeToken') : t('fo.lock.newToken')}</Button>
                    <span className="text-sm text-muted-foreground">{overview.settings.token_hint === null ? t('fo.lock.noToken') : t('fo.lock.tokenEnding', { hint: overview.settings.token_hint })}</span>
                </div>
            </section>

            <DataGrid caption={t('fo.lock.title')} columns={columns} empty={<EmptyState title={t('fo.lock.none')} />} getRowId={(j) => j.id} id="fo.door-locks" rows={overview.jobs} testId="door-lock-jobs" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setSettle(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={settle?.reason.trim() === ''} loading={action.busy} onClick={() => void doSettle()} type="button">{t('fo.lock.settle')}</Button></>}
                onClose={() => setSettle(null)}
                open={settle !== null}
                title={t('fo.lock.settleTitle')}
            >
                {settle !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('reason')} field="reason" hint={t('fo.lock.settleHint')} label={t('fo.lock.reason')}><Input maxLength={200} onChange={(e) => setSettle({ ...settle, reason: e.target.value })} value={settle.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </FrontOfficeShell>
    );
}
