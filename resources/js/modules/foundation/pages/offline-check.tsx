import { Head } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { SyncPanel, SyncStatus } from '@/components/ui/sync-status';
import { useTranslation } from '@/shared/i18n/i18n';
import { offlineSupport } from '@/shared/offline/idb-store';
import { useOfflineQueue } from '@/shared/offline/offline-provider';

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-4 border-b border-border py-1.5 text-sm last:border-b-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

/**
 * Field test for the offline queue (TASK-FND-017). It records harmless
 * `system.echo` changes, so staff and IT can prove on a real device and network
 * that the encrypted queue, retry and synchronization work, and report which
 * devices cannot (PRD Q-14).
 */
export default function OfflineCheckPage() {
    const { t } = useTranslation();
    const queue = useOfflineQueue();
    const support = offlineSupport();
    const [text, setText] = useState('');

    const yesNo = (value: boolean) => (value ? t('offline.check.support.yes') : t('offline.check.support.no'));
    const persistent =
        queue.persistentStorage === null
            ? t('offline.check.support.unknown')
            : yesNo(queue.persistentStorage);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const message = text.trim();

        if (message === '') {
            return;
        }

        // Clear first: anything typed while the change is being saved belongs to the next one.
        setText('');

        try {
            await queue.enqueue({ type: 'system.echo', payload: { text: message } });
        } catch (error) {
            setText(message);

            throw error;
        }
    }

    return (
        <>
            <Head title={t('offline.check.title')} />
            <main className="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-8">
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-semibold">{t('offline.check.title')}</h1>
                        <p className="mt-1 max-w-prose text-sm text-muted-foreground">{t('offline.check.description')}</p>
                    </div>
                    <LanguageSwitcher />
                </header>

                <SyncStatus />

                <section aria-label={t('offline.check.support')}>
                    <h2 className="mb-1 text-base font-semibold">{t('offline.check.support')}</h2>
                    <dl className="border border-border bg-surface px-3">
                        <Row label={t('offline.check.support.indexedDb')} value={yesNo(support.indexedDb)} />
                        <Row label={t('offline.check.support.webCrypto')} value={yesNo(support.webCrypto)} />
                        <Row label={t('offline.check.support.secureContext')} value={yesNo(support.secureContext)} />
                        <Row label={t('offline.check.support.persistent')} value={persistent} />
                    </dl>
                </section>

                <form className="flex flex-col gap-3" onSubmit={(event) => void submit(event)}>
                    <FormField hint={t('offline.check.hint')} label={t('offline.check.text')}>
                        <Input maxLength={200} onChange={(event) => setText(event.target.value)} value={text} />
                    </FormField>
                    <div>
                        <Button disabled={!queue.ready} type="submit">
                            {t('offline.check.queue')}
                        </Button>
                        {!queue.ready ? <p className="mt-1 text-xs text-muted-foreground">{t('offline.check.notReady')}</p> : null}
                    </div>
                </form>

                <SyncPanel />
            </main>
        </>
    );
}
