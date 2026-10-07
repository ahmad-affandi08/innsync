import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import type { MessageKey } from '@/locales/en/index';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Field = { name: string; kind: string; secret: boolean; required: boolean; options?: string[]; default?: string };
type Channel = {
    provider: string | null;
    enabled: boolean;
    values: Record<string, string>;
    saved_secrets: string[];
    last_test: { at: string; ok: boolean | null; error: string | null } | null;
    providers: Record<string, { official: boolean; fields: Field[] }>;
};

/** How the installation sends email and WhatsApp, chosen and filled in on screen. A saved key is shown only as "saved". */
export default function MessagingPage({ channels }: { channels: { email: Channel; whatsapp: Channel } }) {
    const { t } = useTranslation();

    return (
        <PropertyShell description={t('msg.description')} title={t('msg.title')}>
            <ChannelCard channel="email" initial={channels.email} />
            <ChannelCard channel="whatsapp" initial={channels.whatsapp} />
        </PropertyShell>
    );
}

function ChannelCard({ channel, initial }: { channel: 'email' | 'whatsapp'; initial: Channel }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [state, setState] = useState(initial);
    const names = Object.keys(state.providers);
    const [provider, setProvider] = useState(initial.provider ?? names[0]);
    const [values, setValues] = useState<Record<string, string>>(initial.values);
    const [enabled, setEnabled] = useState(initial.enabled || initial.provider === null);
    const [destination, setDestination] = useState('');
    const [result, setResult] = useState<{ ok: boolean; error: string | null } | null>(null);
    const spec = state.providers[provider];
    const known = (key: string) => t(key as MessageKey) !== key;
    const sameProvider = state.provider === provider;
    const label = (key: string) => (known(key) ? t(key as MessageKey) : key);

    function adopt(next: Channel) {
        setState(next);
        setValues(next.values);
        setEnabled(next.enabled);
    }

    async function save() {
        setResult(null);
        const body = { provider, enabled, values };
        const done = await action.run<Channel>(`/property/messaging/${channel}`, { method: 'PUT', body });
        if (done !== null) adopt(done);
    }

    async function turnOff() {
        const done = await action.run<Channel>(`/property/messaging/${channel}/off`, {});
        if (done !== null) adopt(done);
    }

    async function test() {
        setResult(null);
        const done = await action.run<{ ok: boolean; error: string | null; channel: Channel }>(`/property/messaging/${channel}/test`, { body: { destination } });
        if (done !== null) {
            setResult({ ok: done.ok, error: done.error });
            setState(done.channel);
        }
    }

    const last = state.last_test;

    return (
        <section aria-labelledby={`msg-${channel}`} className="flex flex-col gap-4 border border-border bg-surface p-4 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-lg font-semibold" id={`msg-${channel}`}>{t(`msg.${channel}.title` as MessageKey)}</h2>
                    <p className="text-sm text-muted-foreground">{t(`msg.${channel}.hint` as MessageKey)}</p>
                </div>
                {state.provider === null ? <StatusBadge label={t('msg.state.none')} tone="neutral" /> : <StatusBadge label={t(state.enabled ? 'msg.state.on' : 'msg.state.off')} tone={state.enabled ? 'success' : 'warning'} />}
            </div>

            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <FormField label={t('msg.provider')}>
                <Select onChange={(e) => { setProvider(e.target.value); setResult(null); }} value={provider}>
                    {names.map((n) => <option key={n} value={n}>{label(`msg.provider.${n}`)} · {t(state.providers[n].official ? 'msg.official' : 'msg.unofficial')}</option>)}
                </Select>
            </FormField>

            {known(`msg.provider.${provider}.hint`) ? <p className="text-sm text-muted-foreground">{t(`msg.provider.${provider}.hint` as MessageKey)}</p> : null}
            {!spec.official ? <Alert title={t('msg.unofficial.title')} tone="warning">{t('msg.unofficial.body')}</Alert> : null}

            <div className="grid gap-4 sm:grid-cols-2">
                {spec.fields.map((f) => {
                    const saved = sameProvider && f.secret && state.saved_secrets.includes(f.name);
                    return (
                        <FormField error={action.fieldError(f.name)} key={`${provider}.${f.name}`} label={label(`msg.field.${f.name}`) + (f.required ? '' : ` (${t('msg.optional')})`)}>
                            {f.kind === 'select' ? (
                                <Select onChange={(e) => setValues({ ...values, [f.name]: e.target.value })} value={values[f.name] ?? f.default ?? f.options?.[0] ?? ''}>
                                    {f.options?.map((o) => <option key={o} value={o}>{label(`msg.option.${o}`)}</option>)}
                                </Select>
                            ) : (
                                <Input
                                    autoComplete="off"
                                    inputMode={f.kind === 'number' ? 'numeric' : undefined}
                                    onChange={(e) => setValues({ ...values, [f.name]: e.target.value })}
                                    placeholder={saved ? t('msg.saved') : f.default ?? ''}
                                    type={f.secret ? 'password' : f.kind === 'email' ? 'email' : f.kind === 'url' ? 'url' : 'text'}
                                    value={values[f.name] ?? ''}
                                />
                            )}
                        </FormField>
                    );
                })}
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <label className="flex items-center gap-2 text-sm"><Switch checked={enabled} onCheckedChange={setEnabled} />{t('msg.enabled')}</label>
                <Button loading={action.busy} onClick={() => void save()} type="button">{t('msg.save')}</Button>
                {state.provider !== null && state.enabled ? <Button disabled={action.busy} onClick={() => void turnOff()} type="button" variant="outline">{t('msg.turnOff')}</Button> : null}
            </div>
            {channel === 'email' && state.provider === null ? <p className="text-sm text-muted-foreground">{t('msg.email.fallback')}</p> : null}

            {state.provider !== null ? (
                <div className="flex flex-col gap-3 border-t border-border pt-4">
                    <h3 className="text-sm font-semibold">{t('msg.test.title')}</h3>
                    <p className="text-sm text-muted-foreground">{t(`msg.${channel}.testHint` as MessageKey)}</p>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="min-w-0 flex-1 basis-56">
                            <FormField error={action.fieldError('destination')} label={t(`msg.${channel}.destination` as MessageKey)}>
                                <Input onChange={(e) => setDestination(e.target.value)} type={channel === 'email' ? 'email' : 'tel'} value={destination} />
                            </FormField>
                        </div>
                        <Button disabled={destination.trim() === '' || action.busy} onClick={() => void test()} type="button" variant="outline">{t('msg.test.send')}</Button>
                    </div>
                    {result !== null ? (
                        <Alert title={t(result.ok ? 'msg.test.ok' : 'msg.test.failed')} tone={result.ok ? 'success' : 'danger'}>
                            {result.ok ? t('msg.test.okHint') : label(`msg.error.${result.error}`)}
                        </Alert>
                    ) : last !== null ? (
                        <p className="text-sm text-muted-foreground">{t(last.ok ? 'msg.test.lastOk' : 'msg.test.lastFailed', { when: format.instant(last.at) })}</p>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}
