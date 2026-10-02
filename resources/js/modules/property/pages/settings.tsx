import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { ConfirmDialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Settings = {
    business_date: string | null;
    check_in_time: string;
    check_out_time: string;
    night_audit_earliest_time: string;
    rounding_increment_minor: number;
    rounding_mode: 'half_up' | 'half_even' | 'down' | 'up';
    availability_horizon_days: number;
    lock_version: number;
};

const MODES = ['half_up', 'half_even', 'down', 'up'] as const;

export default function SettingsPage({ settings }: { settings: Settings }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({
        checkIn: settings.check_in_time,
        checkOut: settings.check_out_time,
        nightAudit: settings.night_audit_earliest_time,
        increment: String(settings.rounding_increment_minor),
        mode: settings.rounding_mode,
        horizon: String(settings.availability_horizon_days),
        reason: '',
    });
    const [saved, setSaved] = useState(false);
    const [goLive, setGoLive] = useState(false);
    const [date, setDate] = useState('');
    const [dateReason, setDateReason] = useState('');

    async function save() {
        setSaved(false);
        const done = await action.run('/property/settings', {
            method: 'PUT',
            body: {
                check_in_time: form.checkIn,
                check_out_time: form.checkOut,
                night_audit_earliest_time: form.nightAudit,
                rounding_increment_minor: Number(form.increment),
                rounding_mode: form.mode,
                availability_horizon_days: Number(form.horizon),
                lock_version: settings.lock_version,
                reason: form.reason,
            },
            reload: ['settings'],
        });
        if (done !== null) {
            setSaved(true);
            setForm((f) => ({ ...f, reason: '' }));
        }
    }

    async function setBusinessDate() {
        const done = await action.run('/property/settings/business-date', { body: { business_date: date, lock_version: settings.lock_version, reason: dateReason }, reload: ['settings'] });
        if (done !== null) setGoLive(false);
    }

    return (
        <PropertyShell description={t('property.settings.description')} title={t('property.settings.title')}>
            {action.error !== null && !goLive ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {saved ? <Alert title={t('property.settings.saved')} tone="success" /> : null}

            <section aria-labelledby="bd-h" className="flex flex-col gap-2">
                <h2 className="text-lg font-semibold" id="bd-h">{t('property.settings.businessDate')}</h2>
                {settings.business_date === null ? (
                    <>
                        <p className="text-sm text-muted-foreground">{t('property.settings.businessDateNotSet')}</p>
                        <div><Button onClick={() => { action.clear(); setGoLive(true); }} size="sm" type="button">{t('property.settings.setBusinessDate')}</Button></div>
                    </>
                ) : (
                    <p className="text-sm">{t('property.settings.businessDateValue', { date: format.date(settings.business_date, 'long') })}</p>
                )}
            </section>

            <form className="flex flex-col gap-4" onSubmit={(e) => { e.preventDefault(); void save(); }}>
                <h2 className="text-lg font-semibold">{t('property.settings.times')}</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                    <FormField error={action.fieldError('check_in_time')} label={t('property.settings.checkIn')}>
                        <Input onChange={(e) => setForm({ ...form, checkIn: e.target.value })} type="time" value={form.checkIn} />
                    </FormField>
                    <FormField error={action.fieldError('check_out_time')} label={t('property.settings.checkOut')}>
                        <Input onChange={(e) => setForm({ ...form, checkOut: e.target.value })} type="time" value={form.checkOut} />
                    </FormField>
                    <FormField error={action.fieldError('night_audit_earliest_time')} hint={t('property.settings.nightAuditHint')} label={t('property.settings.nightAudit')}>
                        <Input onChange={(e) => setForm({ ...form, nightAudit: e.target.value })} type="time" value={form.nightAudit} />
                    </FormField>
                </div>
                <h2 className="text-lg font-semibold">{t('property.settings.rounding')}</h2>
                <div className="grid gap-3 sm:grid-cols-3">
                    <FormField error={action.fieldError('rounding_increment_minor')} hint={t('property.settings.roundingIncrementHint')} label={t('property.settings.roundingIncrement')}>
                        <Input inputMode="numeric" onChange={(e) => setForm({ ...form, increment: e.target.value })} value={form.increment} />
                    </FormField>
                    <FormField error={action.fieldError('rounding_mode')} label={t('property.settings.roundingMode')}>
                        <Select onChange={(e) => setForm({ ...form, mode: e.target.value as Settings['rounding_mode'] })} value={form.mode}>
                            {MODES.map((m) => <option key={m} value={m}>{t(`property.settings.mode.${m}`)}</option>)}
                        </Select>
                    </FormField>
                    <FormField error={action.fieldError('availability_horizon_days')} hint={t('property.settings.horizonHint')} label={t('property.settings.horizon')}>
                        <Input inputMode="numeric" onChange={(e) => setForm({ ...form, horizon: e.target.value })} value={form.horizon} />
                    </FormField>
                </div>
                <FormField error={action.fieldError('reason')} hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                    <Input maxLength={500} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} />
                </FormField>
                <div><Button loading={action.busy} type="submit">{t('property.action.save')}</Button></div>
            </form>

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={t('property.settings.setBusinessDate')}
                consequence={t('property.settings.setBusinessDateConsequence')}
                onCancel={() => setGoLive(false)}
                onConfirm={() => void setBusinessDate()}
                open={goLive}
                pending={action.busy}
                title={t('property.settings.setBusinessDateTitle', { date: date || '…' })}
            >
                <div className="flex flex-col gap-3">
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={action.fieldError('business_date')} label={t('property.settings.businessDate')}>
                        <DatePicker onChange={(e) => setDate(e.target.value)} value={date} />
                    </FormField>
                    <FormField error={action.fieldError('reason')} hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                        <Input maxLength={500} onChange={(e) => setDateReason(e.target.value)} value={dateReason} />
                    </FormField>
                </div>
            </ConfirmDialog>
        </PropertyShell>
    );
}
