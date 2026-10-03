import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-picker';
import { FormField } from '@/components/ui/form-field';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { REPORT_MAX_DAYS, spanDays } from '@/modules/finance/lib/finance';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Overview = { datasets: string[]; today: string; from: string; max_rows: number };

/** Finance data as CSV files for a spreadsheet or the accounting software. The browser downloads each file straight from the server. */
export default function ExportPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [period, setPeriod] = useState({ from: overview.from, to: overview.today });
    const tooLong = spanDays(period.from, period.to) >= REPORT_MAX_DAYS;
    const query = new URLSearchParams({ from: period.from, to: period.to }).toString();

    return (
        <FinanceShell description={t('fin.exp.description')} title={t('fin.exp.title')} wide>
            <Alert title={t('fin.exp.noteTitle')} tone="info">
                <ul className="flex list-disc flex-col gap-1 pl-5">
                    <li>{t('fin.exp.noteFormat')}</li>
                    <li>{t('fin.exp.noteMoney')}</li>
                    <li>{t('fin.exp.noteRows', { max: format.number(overview.max_rows) })}</li>
                    <li>{t('fin.exp.noteAudit')}</li>
                </ul>
            </Alert>

            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 border border-border bg-surface p-4 print:hidden">
                <FormField error={tooLong ? t('fin.pnl.tooLong') : undefined} hint={t('fin.exp.periodHint')} label={t('fin.rev.period')}>
                    <DateRangePicker onChange={setPeriod} value={period} />
                </FormField>
            </div>

            <ul aria-label={t('fin.exp.title')} className="grid gap-3 lg:grid-cols-2" data-testid="export-datasets">
                {overview.datasets.map((dataset) => (
                    <li className="flex flex-wrap items-center justify-between gap-3 border border-border bg-surface p-4" data-testid={`export-${dataset}`} key={dataset}>
                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <h2 className="font-semibold">{t(`fin.exp.set.${dataset}` as MessageKey)}</h2>
                            <p className="text-sm text-muted-foreground">{t(`fin.exp.set.${dataset}.hint` as MessageKey)}</p>
                        </div>
                        {tooLong ? (
                            <Button disabled type="button" variant="outline">{t('fin.exp.download')}</Button>
                        ) : (
                            <Button asChild variant="outline"><a download href={`/finance/export/${dataset}?${query}`}>{t('fin.exp.download')}</a></Button>
                        )}
                    </li>
                ))}
            </ul>
        </FinanceShell>
    );
}
