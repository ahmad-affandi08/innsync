import { usePage } from '@inertiajs/react';

import { Alert } from '@/components/ui/alert';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

/** Shown above a page that was opened from the device with no network: when the picture of it was taken, and that what is done now waits in the queue. */
export function OfflineSnapshotNotice() {
    const { t } = useTranslation();
    const format = useFormatters();
    const kept = usePage().props.offlineSnapshot as { savedAt: number } | undefined;

    if (kept === undefined) {
        return null;
    }

    return <Alert title={t('offline.snapshot.title')} tone="warning">{t('offline.snapshot.body', { at: format.instant(kept.savedAt) })}</Alert>;
}
