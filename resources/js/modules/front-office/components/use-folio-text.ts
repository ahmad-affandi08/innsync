import { useMemo } from 'react';

import { folioLabel, postingText, type FolioTextLabels } from '@/modules/front-office/lib/folio-text';
import { useTranslation } from '@/shared/i18n/i18n';

const METHODS = ['cash', 'qris', 'card', 'bank_transfer', 'gift_card', 'online'] as const;

/** The folio's own wording (a folio called `Guest`, `Payment by cash`) in the language of the screen. */
export function useFolioText() {
    const { t } = useTranslation();

    return useMemo(() => {
        const method = (m: string): string => ((METHODS as readonly string[]).includes(m) ? t(`fo.folio.method.${m}` as 'fo.folio.method.cash') : m.replace(/_/g, ' '));
        const labels: FolioTextLabels = {
            guest: t('fo.folio.labelGuest'),
            paymentBy: (m) => t('fo.folio.paymentBy', { method: method(m) }),
            refundBy: (m) => t('fo.folio.refundBy', { method: method(m) }),
        };

        return { label: (label: string) => folioLabel(label, labels), posting: (description: string) => postingText(description, labels) };
    }, [t]);
}
