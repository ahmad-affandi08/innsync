import { Alert } from '@/components/ui/alert';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { HrShell } from '@/modules/hr/components/hr-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Entry = { id: string; type_name: string | null; earned_on: string; amount_minor: number; note: string | null; reverses_id: string | null };
type Mine = { linked: boolean; currency: string; total_minor: number; by_type: { type_id: string; name: string; total_minor: number }[]; entries: Entry[] };

/** Everything the person earned in commissions since they began, from their own account. */
export default function MyCommissionsPage({ commissions }: { commissions: Mine }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const money = (minor: number) => format.money(minor, commissions.currency);
    const columns: DataGridColumn<Entry>[] = [
        { id: 'day', label: t('hr.com.col.day'), value: (e) => e.earned_on, rowHeader: true, cell: (e) => format.date(e.earned_on) },
        { id: 'type', label: t('hr.com.col.type'), value: (e) => e.type_name ?? '', filter: 'select' },
        { id: 'amount', label: t('hr.com.col.amount'), value: (e) => e.amount_minor, align: 'right', cell: (e) => money(e.amount_minor) },
        { id: 'note', label: t('hr.com.col.note'), value: (e) => e.note ?? '' },
    ];

    return (
        <HrShell description={t('hr.my.description')} title={t('hr.my.title')}>
            {!commissions.linked ? <Alert title={t('hr.my.notLinked')} tone="warning" /> : (
                <>
                    <p className="text-sm" data-testid="my-commission-total">{t('hr.my.total')}: <strong>{money(commissions.total_minor)}</strong></p>
                    {commissions.by_type.length > 0 ? <p className="text-sm text-muted-foreground">{t('hr.my.byType')}: {commissions.by_type.map((x) => `${x.name} ${money(x.total_minor)}`).join(' · ')}</p> : null}
                    <h2 className="text-base font-semibold">{t('hr.my.history')}</h2>
                    <DataGrid caption={t('hr.my.history')} columns={columns} empty={<EmptyState illustration="bell" title={t('hr.com.empty')} />} getRowId={(e) => e.id} id="hr.my-commissions" rows={commissions.entries} testId="hr-my-commissions" />
                </>
            )}
        </HrShell>
    );
}
