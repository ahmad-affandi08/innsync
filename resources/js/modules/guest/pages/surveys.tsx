import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { EmptyState } from '@/components/ui/empty-state';
import { Metric } from '@/components/ui/metric';
import { StatusBadge } from '@/components/ui/status-badge';
import { GuestStaffShell } from '@/modules/guest/components/guest-staff-shell';
import type { SurveyOverview, SurveyRow } from '@/modules/guest/lib/guest';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

const avg = (hundredths: number | null) => (hundredths === null ? '—' : (hundredths / 100).toFixed(2));

/** The answers of the guest survey and the average of each rating. */
export default function SurveysPage({ overview }: { overview: SurveyOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const cell = (n: number | null) => (n === null ? '—' : String(n));
    const columns: DataGridColumn<SurveyRow>[] = [
        { id: 'at', label: t('guest.surveys.colWhen'), value: (s) => s.at, rowHeader: true, cell: (s) => format.instant(s.at) },
        { id: 'overall', label: t('guest.survey.overall'), align: 'right', value: (s) => s.overall },
        { id: 'room', label: t('guest.survey.room_rating'), align: 'right', value: (s) => s.room ?? 0, cell: (s) => cell(s.room) },
        { id: 'service', label: t('guest.survey.service_rating'), align: 'right', value: (s) => s.service ?? 0, cell: (s) => cell(s.service) },
        { id: 'food', label: t('guest.survey.food_rating'), align: 'right', value: (s) => s.food ?? 0, cell: (s) => cell(s.food) },
        { id: 'value', label: t('guest.survey.value_rating'), align: 'right', value: (s) => s.value ?? 0, cell: (s) => cell(s.value) },
        { id: 'comment', label: t('guest.survey.comment'), value: (s) => s.comment ?? '', cell: (s) => <span>{s.comment ?? '—'}{s.complaint_opened ? <span className="ml-2"><StatusBadge label={t('guest.surveys.complaint')} tone="warning" /></span> : null}</span> },
    ];

    return (
        <GuestStaffShell description={t('guest.surveys.description', { days: overview.days })} title={t('guest.surveys.title')} wide>
            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <Metric label={t('guest.survey.overall')} value={avg(overview.averages.overall)} />
                <Metric label={t('guest.survey.room_rating')} value={avg(overview.averages.room_rating)} />
                <Metric label={t('guest.survey.service_rating')} value={avg(overview.averages.service_rating)} />
                <Metric label={t('guest.survey.food_rating')} value={avg(overview.averages.food_rating)} />
                <Metric label={t('guest.survey.value_rating')} value={avg(overview.averages.value_rating)} />
            </div>
            <DataGrid caption={t('guest.surveys.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('guest.surveys.none')} />} getRowId={(s) => s.id} id="guest.surveys" rows={overview.surveys} testId="guest-surveys" />
        </GuestStaffShell>
    );
}
