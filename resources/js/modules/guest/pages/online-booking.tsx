import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Settings = { enabled: boolean; rate_plan_id: string | null; max_nights: number; notify_email: string | null; notice: string | null; remind_before_arrival: boolean; thank_after_stay: boolean; plans: { id: string; code: string; name: string }[]; url: string; awaiting: number };

/** How the hotel takes bookings from its own web page. Off until the hotel switches it on; every request is tentative until staff confirm it, and the guest pays at the hotel. */
export default function OnlineBookingPage({ settings: initial }: { settings: Settings }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [state, setState] = useState(initial);
    const [form, setForm] = useState({ enabled: initial.enabled, rate_plan_id: initial.rate_plan_id ?? '', max_nights: String(initial.max_nights), notify_email: initial.notify_email ?? '', notice: initial.notice ?? '', remind_before_arrival: initial.remind_before_arrival, thank_after_stay: initial.thank_after_stay });
    const [copied, setCopied] = useState(false);

    async function save() {
        const done = await action.run<Settings>('/property/online-booking', { method: 'PUT', body: { ...form, max_nights: Number(form.max_nights), rate_plan_id: form.rate_plan_id === '' ? null : form.rate_plan_id } });
        if (done !== null) setState(done);
    }

    async function copy() {
        try {
            await navigator.clipboard.writeText(state.url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    }

    return (
        <PropertyShell description={t('ob.description')} title={t('ob.title')}>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <section aria-labelledby="ob-state" className="flex flex-col gap-4 border border-border bg-surface p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold" id="ob-state">{t('ob.status')}</h2>
                    <StatusBadge label={t(state.enabled ? 'ob.on' : 'ob.off')} tone={state.enabled ? 'success' : 'neutral'} />
                </div>
                <label className="flex items-center gap-3 text-sm font-medium"><Switch checked={form.enabled} onCheckedChange={(v) => setForm({ ...form, enabled: v })} />{t('ob.enable')}</label>
                <Alert title={t('ob.howTitle')} tone="info">{t('ob.how')}</Alert>

                <div className="grid gap-4 sm:grid-cols-2">
                    <FormField error={action.fieldError('rate_plan_id')} hint={t('ob.planHint')} label={t('ob.plan')}>
                        <Select onChange={(e) => setForm({ ...form, rate_plan_id: e.target.value })} value={form.rate_plan_id}>
                            <option value="">{t('ob.planNone')}</option>
                            {state.plans.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.name}</option>)}
                        </Select>
                    </FormField>
                    <FormField error={action.fieldError('max_nights')} label={t('ob.maxNights')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, max_nights: e.target.value })} value={form.max_nights} /></FormField>
                    <FormField error={action.fieldError('notify_email')} hint={t('ob.notifyHint')} label={t('ob.notify')}><Input onChange={(e) => setForm({ ...form, notify_email: e.target.value })} type="email" value={form.notify_email} /></FormField>
                </div>
                <FormField error={action.fieldError('notice')} hint={t('ob.noticeHint')} label={t('ob.notice')}><Textarea maxLength={500} onChange={(e) => setForm({ ...form, notice: e.target.value })} rows={3} value={form.notice} /></FormField>
                <div className="flex flex-col gap-2">
                    <label className="flex items-center gap-3 text-sm font-medium"><Switch checked={form.remind_before_arrival} onCheckedChange={(v) => setForm({ ...form, remind_before_arrival: v })} />{t('ob.remind')}</label>
                    <label className="flex items-center gap-3 text-sm font-medium"><Switch checked={form.thank_after_stay} onCheckedChange={(v) => setForm({ ...form, thank_after_stay: v })} />{t('ob.thank')}</label>
                    <p className="max-w-2xl text-sm text-muted-foreground">{t('ob.messagesHint')}</p>
                </div>
                <div><Button loading={action.busy} onClick={() => void save()} type="button">{t('ob.save')}</Button></div>
            </section>

            {state.enabled ? (
                <section aria-labelledby="ob-link" className="flex flex-col gap-3 border border-border bg-surface p-4 sm:p-6">
                    <h2 className="text-lg font-semibold" id="ob-link">{t('ob.link')}</h2>
                    <p className="text-sm text-muted-foreground">{t('ob.linkHint')}</p>
                    <div className="flex flex-wrap items-center gap-2">
                        <code className="min-w-0 flex-1 break-all border border-border bg-surface-muted px-2 py-2 text-sm" data-testid="booking-url">{state.url}</code>
                        <Button onClick={() => void copy()} size="sm" type="button" variant="outline">{t(copied ? 'ob.copied' : 'ob.copy')}</Button>
                        <Button asChild size="sm" variant="outline"><a href={state.url} rel="noreferrer" target="_blank">{t('ob.open')}</a></Button>
                    </div>
                    {state.awaiting > 0 ? <p className="text-sm font-medium"><Link className="underline underline-offset-2" href="/front-office/reservations?status=tentative">{t('ob.awaiting', { count: state.awaiting })}</Link></p> : <p className="text-sm text-muted-foreground">{t('ob.noneWaiting')}</p>}
                </section>
            ) : null}
        </PropertyShell>
    );
}
