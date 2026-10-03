import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { FormField } from '@/components/ui/form-field';
import { Select } from '@/components/ui/select';
import { DamageReportPanel, type DamageForm, type DamageOverview } from '@/modules/maintenance/components/damage-report-panel';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';

/** Faults found by housekeeping: the room or the place, what is wrong and a photo; each becomes a work order of Maintenance (FR-HK-008). */
export default function DamageReportsPage({ overview }: { overview: DamageOverview }) {
    const { t } = useTranslation();
    const action = useServerAction();

    async function send(form: DamageForm, photo: File | null) {
        const body = new FormData();
        if (form.roomId !== '') body.set('room_id', form.roomId);
        if (form.area.trim() !== '') body.set('area', form.area.trim());
        body.set('category', form.category);
        body.set('title', form.title.trim());
        if (form.detail.trim() !== '') body.set('detail', form.detail.trim());
        if (form.urgent) body.set('urgent', '1');
        if (photo !== null) body.set('photo', photo);
        return action.run<{ report: { number: string } }>('/housekeeping/damage-reports', { body, reload: ['overview'] });
    }

    return (
        <HousekeepingShell description={t('hk.damage.description')} title={t('hk.damage.title')} wide>
            <DamageReportPanel
                action={action}
                onSend={send}
                overview={overview}
                roomField={(value, onChange) => <FormField error={action.fieldError('room_id')} field="room_id" label={t('mtc.damage.roomField')}><Select onChange={(e) => onChange(e.target.value)} value={value}><option value="">—</option>{overview.rooms.map((r) => <option key={r.id} value={r.id}>{r.number}</option>)}</Select></FormField>}
            />
        </HousekeepingShell>
    );
}
