import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

export type Meta = { report: string; generated_at: string; business_date: string; period: { from: string; to: string }; filters: Record<string, string>; sources: string[] };

/** What makes a report reproducible (FR-RPT-009): when it was made, which dates, which filters and which data. */
export function ReportMeta({ meta }: { meta: Meta }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const filters = Object.entries(meta.filters).map(([k, v]) => `${k}: ${v}`).join(', ');

    return (
        <dl className="grid gap-x-6 gap-y-1 border border-border p-3 text-xs sm:grid-cols-[max-content_1fr]" data-testid="report-meta">
            <dt className="text-muted-foreground">{t('rpt.meta.generated')}</dt><dd>{format.instant(meta.generated_at)}</dd>
            <dt className="text-muted-foreground">{t('rpt.meta.businessDate')}</dt><dd>{format.date(meta.business_date, 'long')}</dd>
            <dt className="text-muted-foreground">{t('rpt.meta.period')}</dt><dd>{t('rpt.period.shown', { from: format.date(meta.period.from), to: format.date(meta.period.to) })}</dd>
            <dt className="text-muted-foreground">{t('rpt.meta.filters')}</dt><dd>{filters === '' ? t('rpt.meta.noFilters') : filters}</dd>
            <dt className="text-muted-foreground">{t('rpt.meta.sources')}</dt><dd>{meta.sources.join('; ')}</dd>
        </dl>
    );
}
