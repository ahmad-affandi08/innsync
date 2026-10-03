import { KitchenShell } from '@/modules/kitchen/components/kitchen-shell';
import { DamageReportPanel, type DamageForm, type DamageOverview } from '@/modules/maintenance/components/damage-report-panel';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';

/** Faults of kitchen and bar equipment: which equipment, what is wrong and a photo; each becomes a work order of Maintenance (FR-KIT-010). */
export default function DamageReportsPage({ overview }: { overview: DamageOverview }) {
    const { t } = useTranslation();
    const action = useServerAction();

    async function send(form: DamageForm, photo: File | null) {
        const body = new FormData();
        if (form.area.trim() !== '') body.set('area', form.area.trim());
        body.set('category', form.category);
        body.set('title', form.title.trim());
        if (form.detail.trim() !== '') body.set('detail', form.detail.trim());
        if (form.urgent) body.set('urgent', '1');
        if (photo !== null) body.set('photo', photo);
        return action.run<{ report: { number: string } }>('/kitchen/damage-reports', { body, reload: ['overview'] });
    }

    return (
        <KitchenShell description={t('kitchen.damage.description')} title={t('kitchen.damage.title')}>
            <DamageReportPanel action={action} onSend={send} overview={overview} />
        </KitchenShell>
    );
}
