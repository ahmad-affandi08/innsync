import { Head } from '@inertiajs/react';

import { EmptyState } from '@/components/ui/empty-state';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { useTranslation } from '@/shared/i18n/i18n';

/** Shown when a code does not work or a session has ended: the way on is to scan the code again. */
export default function EndedPage({ reason }: { reason: 'code' | 'session' | 'link' | 'booking' }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('guest.ended.title')} />
            <main className="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-4 px-4">
                <div className="flex justify-end"><LanguageSwitcher /></div>
                <h1 className="sr-only">{t('guest.ended.title')}</h1>
                <EmptyState illustration="reception" title={reason === 'booking' ? t('guest.booking.off') : reason === 'code' ? t('guest.ended.code') : reason === 'link' ? t('guest.checkin.linkEnded') : t('guest.ended.session')} />
                <p className="text-center text-sm text-muted-foreground">{reason === 'booking' ? t('guest.booking.offHint') : reason === 'link' ? t('guest.checkin.linkEndedHint') : t('guest.ended.hint')}</p>
            </main>
        </>
    );
}
