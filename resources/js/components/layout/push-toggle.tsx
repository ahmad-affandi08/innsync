import { useEffect, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { apiRequest } from '@/shared/api/http';
import { useTranslation } from '@/shared/i18n/i18n';
import { deviceLabel, keyToBytes } from '@/shared/pwa/push';

type Config = { enabled: boolean; public_key: string | null; subscribed: boolean; kinds: string[]; muted: string[] };
type State = 'checking' | 'unsupported' | 'off-server' | 'blocked' | 'off' | 'on';

/** Notices on this device (web push): shown only where the browser can and the server is set up. Turning it on asks the browser for permission; the push itself carries no words. */
export function PushToggle() {
    const { t } = useTranslation();
    const [state, setState] = useState<State>('checking');
    const [key, setKey] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const [kinds, setKinds] = useState<string[]>([]);
    const [muted, setMuted] = useState<string[]>([]);

    useEffect(() => {
        let alive = true;

        async function look() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return alive && setState('unsupported');

            try {
                const registration = await navigator.serviceWorker.getRegistration();
                if (registration === undefined) return alive && setState('unsupported');
                const existing = await registration.pushManager.getSubscription();
                const config = await apiRequest<Config>('/push/config', { query: { endpoint: existing?.endpoint ?? '' } });
                if (!alive) return;
                setKey(config.public_key);
                setKinds(config.kinds);
                setMuted(config.muted);
                if (!config.enabled) return setState('off-server');
                if (Notification.permission === 'denied') return setState('blocked');
                setState(existing !== null && config.subscribed ? 'on' : 'off');
            } catch {
                if (alive) setState('unsupported');
            }
        }

        void look();

        return () => { alive = false; };
    }, []);

    async function turnOn() {
        if (key === null) return;
        setBusy(true);
        setFailed(false);

        try {
            if ((await Notification.requestPermission()) !== 'granted') return setState('blocked');
            const registration = await navigator.serviceWorker.ready;
            const subscription = (await registration.pushManager.getSubscription()) ?? (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(key) as BufferSource }));
            await apiRequest('/push/devices', { method: 'POST', body: { endpoint: subscription.endpoint, label: deviceLabel(navigator.userAgent) } });
            setState('on');
        } catch {
            setFailed(true);
        } finally {
            setBusy(false);
        }
    }

    async function toggleKind(kind: string, on: boolean) {
        setFailed(false);

        try {
            const answer = await apiRequest<{ muted: string[] }>('/push/preferences', { method: 'POST', body: { kind, enabled: on } });
            setMuted(answer.muted);
        } catch {
            setFailed(true);
        }
    }

    async function turnOff() {
        setBusy(true);
        setFailed(false);

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.getSubscription();
            if (subscription !== null) {
                await apiRequest('/push/devices', { method: 'DELETE', body: { endpoint: subscription.endpoint } });
                await subscription.unsubscribe();
            }
            setState('off');
        } catch {
            setFailed(true);
        } finally {
            setBusy(false);
        }
    }

    if (state === 'checking' || state === 'off-server') return null;

    return (
        <section aria-labelledby="push-heading" className="mt-10 max-w-md space-y-3 border-t border-border pt-6">
            <div>
                <h2 className="text-lg font-semibold" id="push-heading">{t('acc.push.heading')}</h2>
                <p className="mt-1 text-sm text-muted-foreground">{t('acc.push.description')}</p>
            </div>
            {state === 'unsupported' ? <p className="text-sm text-muted-foreground">{t('acc.push.unsupported')}</p> : null}
            {state === 'blocked' ? <Alert title={t('acc.push.blockedTitle')} tone="warning">{t('acc.push.blocked')}</Alert> : null}
            {failed ? <Alert title={t('acc.push.failed')} tone="danger" /> : null}
            {state === 'off' ? <Button loading={busy} onClick={() => void turnOn()} type="button">{t('acc.push.turnOn')}</Button> : null}
            {state === 'on' ? (
                <div className="flex flex-wrap items-center gap-3">
                    <span className="text-sm" data-testid="push-on">{t('acc.push.on')}</span>
                    <Button loading={busy} onClick={() => void turnOff()} type="button" variant="outline">{t('acc.push.turnOff')}</Button>
                </div>
            ) : null}
            {state === 'on' && kinds.length > 0 ? (
                <fieldset className="grid gap-2">
                    <legend className="text-sm font-medium">{t('acc.push.kinds')}</legend>
                    {kinds.map((kind) => (
                        <label className="flex items-center gap-2 text-sm" key={kind}><Checkbox checked={!muted.includes(kind)} onCheckedChange={(v) => void toggleKind(kind, v === true)} />{t(`acc.push.kind.${kind}` as 'acc.push.kind.approval')}</label>
                    ))}
                </fieldset>
            ) : null}
        </section>
    );
}
