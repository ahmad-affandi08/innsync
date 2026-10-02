import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { ConfirmDialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';

type Kind = { kind: string; value: number };
type Policy = {
    id: string; rate_plan_id: string | null; plan_code: string | null; source: string | null; effective_from: string; guarantee_required: boolean; reason: string;
    deposit: { basis: string; value: number; due_days_before_arrival: number }; cancellation: { free_days_before_arrival: number; penalty: Kind }; no_show: { penalty: Kind };
};
type Props = { policies: Policy[]; plans: { id: string; code: string; name: string }[]; sources: string[]; business_date: string; currency: string };

const BASES = ['none', 'first_night', 'percent', 'fixed'] as const;
const PENALTIES = ['none', 'first_night', 'all_nights', 'percent', 'fixed'] as const;

const blank = (today: string) => ({
    plan: '', source: '', from: today, guarantee: false, basis: 'none', depositValue: '', dueDays: '0', freeDays: '0', cancelKind: 'none', cancelValue: '', noshowKind: 'none', noshowValue: '', reason: '',
});

export default function BookingPoliciesPage({ business_date, currency, plans, policies, sources }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<ReturnType<typeof blank> | null>(null);
    const [invalid, setInvalid] = useState(false);

    // A percentage is typed as 0 to 100 and sent as basis points; an amount is typed in whole currency units and sent in minor units.
    const toValue = (kind: string, text: string): number | null => {
        if (kind === 'percent') {
            const pct = parseMajorToMinor(text === '' ? '0' : text, 'IDR');
            return pct === null || pct < 0 || pct > 10_000 ? null : pct;
        }
        return kind === 'fixed' ? parseMajorToMinor(text === '' ? '0' : text, currency) : 0;
    };

    const fee = (k: Kind) => (k.kind === 'percent' ? t('fo.res.fee.percent', { value: (k.value / 100).toString() }) : k.kind === 'fixed' ? t('fo.res.fee.fixed', { amount: format.money(k.value, currency) }) : t(`fo.res.fee.${k.kind}` as 'fo.res.fee.none'));

    async function save() {
        if (form === null) return;
        const deposit = toValue(form.basis, form.depositValue);
        const cancel = toValue(form.cancelKind, form.cancelValue);
        const noshow = toValue(form.noshowKind, form.noshowValue);
        setInvalid(deposit === null || cancel === null || noshow === null);
        if (deposit === null || cancel === null || noshow === null) return;
        const done = await action.run('/property/policies', {
            body: {
                rate_plan_id: form.plan || null, source: form.source || null, effective_from: form.from, guarantee_required: form.guarantee, deposit_basis: form.basis, deposit_value: deposit,
                deposit_due_days: Number(form.dueDays), cancel_free_days: Number(form.freeDays), cancel_penalty_kind: form.cancelKind, cancel_penalty_value: cancel,
                noshow_penalty_kind: form.noshowKind, noshow_penalty_value: noshow, reason: form.reason,
            },
            reload: ['policies'],
        });
        if (done !== null) setForm(null);
    }

    const valueField = (kind: string, value: string, onChange: (v: string) => void, label: string, error?: string) =>
        kind === 'percent' || kind === 'fixed'
            ? <FormField error={error} hint={kind === 'percent' ? t('policy.hint.percent') : undefined} label={`${label}: ${kind === 'percent' ? t('policy.value.percent') : t('policy.value.fixed')}`}><Input inputMode="decimal" onChange={(e) => onChange(e.target.value)} value={value} /></FormField>
            : null;

    return (
        <PropertyShell description={t('policy.description')} title={t('policy.title')}>
            {form === null && action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <div className="flex justify-end"><Button onClick={() => { action.clear(); setInvalid(false); setForm(blank(business_date)); }} size="sm" type="button">{t('policy.add')}</Button></div>
            {policies.length === 0 ? <EmptyState title={t('policy.empty')} /> : (
                <ul className="divide-y divide-border border-y border-border">
                    {policies.map((p) => (
                        <li className="flex flex-col gap-1 py-3 text-sm" key={p.id}>
                            <span className="font-medium">{t('policy.row', { date: format.date(p.effective_from, 'long'), scope: `${p.plan_code ?? t('policy.scope.all')} · ${p.source === null ? t('policy.scope.all') : t(`fo.source.${p.source}` as 'fo.source.direct')}` })}</span>
                            {p.guarantee_required ? <span>{t('policy.row.guarantee')}</span> : null}
                            <span>{t('policy.row.deposit', { deposit: fee(p.deposit.basis === 'none' ? { kind: 'none', value: 0 } : { kind: p.deposit.basis, value: p.deposit.value }), days: p.deposit.due_days_before_arrival })}</span>
                            <span>{t('policy.row.cancel', { days: p.cancellation.free_days_before_arrival, fee: fee(p.cancellation.penalty) })}</span>
                            <span>{t('policy.row.noshow', { fee: fee(p.no_show.penalty) })}</span>
                            <span className="text-xs text-muted-foreground">{p.reason}</span>
                        </li>
                    ))}
                </ul>
            )}
            <p className="text-xs text-muted-foreground">{t('policy.hint.penalty')}</p>

            <ConfirmDialog
                cancelLabel={t('ui.dialog.cancel')}
                confirmLabel={t('policy.save')}
                consequence={t('policy.description')}
                onCancel={() => { action.clear(); setForm(null); }}
                onConfirm={() => void save()}
                open={form !== null}
                pending={action.busy}
                title={t('policy.add')}
            >
                {form !== null && (
                    <div className="flex max-h-[60vh] flex-col gap-3 overflow-y-auto">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        {invalid ? <p className="text-sm text-danger">{t('policy.invalid')}</p> : null}
                        <div className="grid grid-cols-2 gap-3">
                            <FormField field="rate_plan_id" error={action.fieldError('rate_plan_id')} label={t('policy.scope.plan')}>
                                <Select onChange={(e) => setForm({ ...form, plan: e.target.value })} value={form.plan}><option value="">{t('policy.scope.all')}</option>{plans.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}</Select>
                            </FormField>
                            <FormField field="source" error={action.fieldError('source')} label={t('policy.scope.source')}>
                                <Select onChange={(e) => setForm({ ...form, source: e.target.value })} value={form.source}><option value="">{t('policy.scope.all')}</option>{sources.map((s) => <option key={s} value={s}>{t(`fo.source.${s}` as 'fo.source.direct')}</option>)}</Select>
                            </FormField>
                        </div>
                        <FormField field="effective_from" error={action.fieldError('effective_from')} label={t('policy.effectiveFrom')}><DatePicker min={business_date} onChange={(e) => setForm({ ...form, from: e.target.value })} value={form.from} /></FormField>
                        <label className="flex items-center gap-2 text-sm"><input checked={form.guarantee} onChange={(e) => setForm({ ...form, guarantee: e.target.checked })} type="checkbox" />{t('policy.guarantee')}</label>
                        <FormField field="deposit_basis" error={action.fieldError('deposit_basis')} label={t('policy.depositBasis')}>
                            <Select onChange={(e) => setForm({ ...form, basis: e.target.value })} value={form.basis}>{BASES.map((b) => <option key={b} value={b}>{t(`policy.basis.${b}` as 'policy.basis.none')}</option>)}</Select>
                        </FormField>
                        {valueField(form.basis, form.depositValue, (v) => setForm({ ...form, depositValue: v }), t('policy.depositBasis'), action.fieldError('deposit_value'))}
                        {form.basis !== 'none' ? <FormField label={t('policy.depositDue')}><Input min={0} onChange={(e) => setForm({ ...form, dueDays: e.target.value })} type="number" value={form.dueDays} /></FormField> : null}
                        <FormField label={t('policy.freeDays')}><Input min={0} onChange={(e) => setForm({ ...form, freeDays: e.target.value })} type="number" value={form.freeDays} /></FormField>
                        <FormField field="cancel_penalty_kind" error={action.fieldError('cancel_penalty_kind')} label={t('policy.cancelPenalty')}>
                            <Select onChange={(e) => setForm({ ...form, cancelKind: e.target.value })} value={form.cancelKind}>{PENALTIES.map((b) => <option key={b} value={b}>{t(`policy.basis.${b}` as 'policy.basis.none')}</option>)}</Select>
                        </FormField>
                        {valueField(form.cancelKind, form.cancelValue, (v) => setForm({ ...form, cancelValue: v }), t('policy.cancelPenalty'), action.fieldError('cancel_penalty_value'))}
                        <FormField field="noshow_penalty_kind" error={action.fieldError('noshow_penalty_kind')} label={t('policy.noshowPenalty')}>
                            <Select onChange={(e) => setForm({ ...form, noshowKind: e.target.value })} value={form.noshowKind}>{PENALTIES.map((b) => <option key={b} value={b}>{t(`policy.basis.${b}` as 'policy.basis.none')}</option>)}</Select>
                        </FormField>
                        {valueField(form.noshowKind, form.noshowValue, (v) => setForm({ ...form, noshowValue: v }), t('policy.noshowPenalty'), action.fieldError('noshow_penalty_value'))}
                        <FormField field="reason" error={action.fieldError('reason')} label={t('policy.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} /></FormField>
                    </div>
                )}
            </ConfirmDialog>
        </PropertyShell>
    );
}
