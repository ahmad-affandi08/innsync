import { Alert } from '@/components/ui/alert';
import type { Standing } from '@/modules/inventory-purchasing/lib/purchasing';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

/** The warning handed back when a document is submitted over a department's monthly budget under the "warn" policy. */
export function BudgetWarning({ currency, standing }: { currency: string; standing: Standing }) {
    const { t } = useTranslation();
    const format = useFormatters();

    return (
        <Alert title={t('inv.po.bud.warning', { department: t(`inv.dept.${standing.department}` as MessageKey), period: standing.period })} tone="warning">
            {t('inv.po.bud.warningDetail', {
                budget: standing.budget_minor === null ? '—' : format.money(standing.budget_minor, currency),
                committed: format.money(standing.committed_minor, currency),
                extra: format.money(standing.extra_minor, currency),
                over: format.money(Math.abs(standing.remaining_minor ?? 0), currency),
            })}
        </Alert>
    );
}
