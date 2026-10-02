import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { ConfirmDialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Scheme = { id: string; effective_from: string; service_charge_bp: number; tax_bp: number; tax_on_service_charge: boolean };

const percent = (bp: number) => (bp / 100).toString();

export default function ChargeSchemesPage({ schemes, scope, scopes }: { schemes: Scheme[]; scope: string; scopes: string[] }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ from: string; sc: string; tax: string; onSc: boolean; reason: string } | null>(null);

    async function save() {
        if (form === null) return;
        const done = await action.run('/property/tax', { body: { scope, effective_from: form.from, service_charge_rate: form.sc, tax_rate: form.tax, tax_on_service_charge: form.onSc, reason: form.reason }, reload: ['schemes'] });
        if (done !== null) {
            setForm(null);
        }
    }

    return (
        <PropertyShell description={t('tax.description')} title={t('tax.title')}>
            {form === null && action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <nav aria-label={t('tax.scope')} className="flex flex-wrap gap-2 text-sm">
                {scopes.map((s) => (
                    <Link aria-current={s === scope ? 'page' : undefined} className="border border-border px-3 py-1.5 hover:bg-surface-muted aria-[current=page]:bg-surface-muted aria-[current=page]:font-medium" href={`/property/tax?scope=${s}`} key={s}>{t(`tax.scope.${s}` as 'tax.scope.rooms')}</Link>
                ))}
            </nav>
            <section aria-labelledby="tax-h" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-semibold" id="tax-h">{t('tax.history')}</h2>
                    <Button onClick={() => { action.clear(); setForm({ from: '', sc: '', tax: '', onSc: true, reason: '' }); }} size="sm" type="button">{t('tax.add')}</Button>
                </div>
                {schemes.length === 0 ? <EmptyState title={t('tax.empty')} /> : (
                    <ul className="divide-y divide-border border-y border-border">
                        {schemes.map((s) => (
                            <li className="py-3 text-sm" key={s.id}>
                                {t('tax.row', { date: format.date(s.effective_from, 'long'), sc: percent(s.service_charge_bp), tax: percent(s.tax_bp), onSc: s.tax_on_service_charge ? t('tax.row.onSc') : '' })}
                            </li>
                        ))}
                    </ul>
                )}
            </section>
            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={t('property.action.save')}
                consequence={t('tax.description')}
                onCancel={() => { action.clear(); setForm(null); }}
                onConfirm={() => void save()}
                open={form !== null}
                pending={action.busy}
                title={t('tax.add')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField field="effective_from" error={action.fieldError('effective_from')} label={t('tax.effectiveFrom')}><DatePicker onChange={(e) => setForm({ ...form, from: e.target.value })} value={form.from} /></FormField>
                        <div className="grid grid-cols-2 gap-3">
                            <FormField field="service_charge_rate" error={action.fieldError('service_charge_rate')} hint={t('tax.rateHint')} label={t('tax.serviceCharge')}><Input inputMode="decimal" onChange={(e) => setForm({ ...form, sc: e.target.value })} value={form.sc} /></FormField>
                            <FormField field="tax_rate" error={action.fieldError('tax_rate')} hint={t('tax.rateHint')} label={t('tax.rate')}><Input inputMode="decimal" onChange={(e) => setForm({ ...form, tax: e.target.value })} value={form.tax} /></FormField>
                        </div>
                        <label className="flex items-center gap-2 text-sm"><input checked={form.onSc} onChange={(e) => setForm({ ...form, onSc: e.target.checked })} type="checkbox" />{t('tax.onServiceCharge')}</label>
                        <FormField field="reason" error={action.fieldError('reason')} hint={t('property.field.reasonHint')} label={t('property.field.reason')}><Input maxLength={500} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} /></FormField>
                    </div>
                )}
            </ConfirmDialog>
        </PropertyShell>
    );
}
