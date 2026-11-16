import { BellRing, Share, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { apiRequest } from '@/shared/api/http';
import { useTranslation } from '@/shared/i18n/i18n';
import { isAppleMobile, nudgeDue, subscribeDevice } from '@/shared/pwa/push';

const STORE = 'innsync.pushNudge';

type Config = { enabled: boolean; public_key: string | null; subscribed: boolean };
type Shown = { kind: 'turn-on'; key: string } | { kind: 'home-screen' } | { kind: 'done' };

function stored(): string | null {
    try {
        return window.localStorage.getItem(STORE);
    } catch {
        return null;
    }
}

/**
 * On a phone, invites a person once to turn notices on, so work reaches them when the app is closed. On an iPhone opened in the browser it first says how to add the app to the home screen, the only
 * place iOS shows notices. Shown only where the server sends notices and the device has none yet; "Not now" keeps it away for a week.
 */
export function PushNudge() {
    const { t } = useTranslation();
    const [shown, setShown] = useState<Shown | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let alive = true;

        async function look() {
            const phone = window.matchMedia('(pointer: coarse)').matches;
            if (!phone || !nudgeDue(stored(), Date.now())) return;

            const apple = isAppleMobile(navigator.userAgent, navigator.maxTouchPoints);
            const standalone = window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
            const capable = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

            try {
                if (apple && !standalone) {
                    const config = await apiRequest<Config>('/push/config', { query: { endpoint: '' } });
                    if (alive && config.enabled) setShown({ kind: 'home-screen' });

                    return;
                }

                if (!capable || Notification.permission === 'denied') return;
                const registration = await navigator.serviceWorker.getRegistration();
                if (registration === undefined) return;
                const existing = await registration.pushManager.getSubscription();
                const config = await apiRequest<Config>('/push/config', { query: { endpoint: existing?.endpoint ?? '' } });
                if (alive && config.enabled && config.public_key !== null && !(existing !== null && config.subscribed)) setShown({ kind: 'turn-on', key: config.public_key });
            } catch {
                // An invitation is never worth an error on the screen.
            }
        }

        void look();

        return () => { alive = false; };
    }, []);

    function later() {
        try {
            window.localStorage.setItem(STORE, String(Date.now()));
        } catch {
            // Without storage it simply asks again next time.
        }
        setShown(null);
    }

    async function turnOn(key: string) {
        setBusy(true);

        try {
            const answer = await subscribeDevice(key, (endpoint, label) => apiRequest('/push/devices', { method: 'POST', body: { endpoint, label } }));
            if (answer === 'on') setShown({ kind: 'done' });
            else later();
        } catch {
            later();
        } finally {
            setBusy(false);
        }
    }

    if (shown === null) return null;

    return (
        <section aria-labelledby="push-nudge-h" className="relative flex gap-3 border border-brand/40 bg-brand/5 p-4 print:hidden" data-testid="push-nudge">
            <BellRing aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-accent" />
            <div className="flex min-w-0 flex-1 flex-col gap-2 pr-6">
                <h2 className="text-sm font-semibold" id="push-nudge-h">{t(shown.kind === 'done' ? 'acc.push.nudge.doneTitle' : 'acc.push.nudge.title')}</h2>
                {shown.kind === 'turn-on' ? (
                    <>
                        <p className="text-sm text-muted-foreground">{t('acc.push.nudge.body')}</p>
                        <div className="flex flex-wrap gap-2">
                            <Button loading={busy} onClick={() => void turnOn(shown.key)} size="sm" type="button">{t('acc.push.turnOn')}</Button>
                            <Button onClick={later} size="sm" type="button" variant="outline">{t('acc.push.nudge.later')}</Button>
                        </div>
                    </>
                ) : null}
                {shown.kind === 'home-screen' ? (
                    <>
                        <p className="text-sm text-muted-foreground">{t('acc.push.nudge.iosBody')}</p>
                        <ol className="list-decimal space-y-1 pl-5 text-sm">
                            <li>{t('acc.push.nudge.ios1')} <Share aria-hidden="true" className="inline size-4 align-text-bottom" /></li>
                            <li>{t('acc.push.nudge.ios2')}</li>
                            <li>{t('acc.push.nudge.ios3')}</li>
                        </ol>
                        <div><Button onClick={later} size="sm" type="button" variant="outline">{t('acc.push.nudge.later')}</Button></div>
                    </>
                ) : null}
                {shown.kind === 'done' ? <p className="text-sm text-muted-foreground">{t('acc.push.nudge.doneBody')}</p> : null}
            </div>
            <button aria-label={t('acc.push.nudge.later')} className="absolute right-2 top-2 grid size-8 place-items-center text-muted-foreground hover:text-foreground" onClick={later} type="button"><X aria-hidden="true" className="size-4" /></button>
        </section>
    );
}
