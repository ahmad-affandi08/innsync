import { Link } from '@inertiajs/react';

import { AppFrame } from '@/components/layout/app-frame';
import type { MessageKey } from '@/locales/en/index';
import { useTranslation } from '@/shared/i18n/i18n';

const AREAS = [
    ['front-office', '/front-office/room-board'], ['housekeeping', '/housekeeping'], ['laundry', '/laundry'], ['fnb', '/fnb/pos'], ['kitchen', '/kitchen'], ['maintenance', '/maintenance'],
    ['inventory', '/inventory/stock'], ['hr', '/hr/me'], ['finance', '/finance/payables'], ['reports', '/dashboard'], ['admin', '/property/settings'],
] as const;

const STEPS = [1, 2, 3, 4, 5] as const;
const BASICS = [1, 2, 3, 4] as const;

/** The guide of the product by role, inside the product (NFR-15): what each part is for and the steps of the day, in the language the person chose. */
export default function GuidePage() {
    const { t } = useTranslation();
    const key = (area: string, part: string) => `guide.${area}.${part}` as MessageKey;

    return (
        <AppFrame description={t('guide.description')} title={t('guide.title')} wide={false}>
            <nav aria-label={t('guide.title')} className="flex flex-wrap gap-2 text-sm">
                {AREAS.map(([area]) => <a className="border border-border bg-surface px-3 py-1.5 hover:bg-surface-muted" href={`#guide-${area}`} key={area}>{t(key(area, 'title'))}</a>)}
            </nav>

            <section aria-labelledby="guide-basics" className="flex flex-col gap-2 border border-border bg-surface p-4" data-testid="guide-basics">
                <h2 className="text-lg font-semibold" id="guide-basics">{t('guide.basics')}</h2>
                <ul className="flex list-disc flex-col gap-1.5 pl-5 text-sm leading-6">{BASICS.map((n) => <li key={n}>{t(`guide.basic${n}` as MessageKey)}</li>)}</ul>
            </section>

            {AREAS.map(([area, href]) => (
                <section aria-labelledby={`guide-${area}-h`} className="flex flex-col gap-2 border border-border bg-surface p-4" data-testid={`guide-${area}`} id={`guide-${area}`} key={area}>
                    <h2 className="text-lg font-semibold" id={`guide-${area}-h`}>{t(key(area, 'title'))}</h2>
                    <p className="text-sm text-muted-foreground">{t(key(area, 'intro'))}</p>
                    <p className="text-xs text-muted-foreground">{t('guide.who', { who: t(key(area, 'who')) })}</p>
                    <ol className="flex list-decimal flex-col gap-1.5 pl-5 text-sm leading-6">{STEPS.map((n) => <li key={n}>{t(key(area, `step${n}`))}</li>)}</ol>
                    <p><Link className="text-sm underline underline-offset-2" href={href}>{t('guide.open', { area: t(key(area, 'title')) })}</Link></p>
                </section>
            ))}
        </AppFrame>
    );
}
