import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Policy = { id: string; kind: string; effective_from: string; grace_minutes: number; bands: { up_to_minutes: number; percent_bp: number }[]; beyond_bp: number; reason: string };
type Props = { catalogue: { policies: Policy[]; kinds: string[]; business_date: string; standard: { check_in: string; check_out: string } } };
type Band = { minutes: string; percent: string };

/** Early check-in and late check-out fee policies (FR-FO-037): grace, bands of minutes with a share of the night's price, and the share beyond. */
export default function StayFeesPage({ catalogue }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ kind: 'late_checkout', from: catalogue.business_date, grace: '60', beyond: '100', reason: '' });
    const [bands, setBands] = useState<Band[]>([{ minutes: '180', percent: '25' }, { minutes: '360', percent: '50' }]);
    const pct = (bp: number) => `${bp / 100}%`;

    async function save() {
        const done = await action.run('/front-office/stay-fees', {
            body: {
                kind: form.kind, effective_from: form.from, grace_minutes: Number(form.grace), beyond_bp: Math.round(Number(form.beyond) * 100), reason: form.reason.trim(),
                bands: bands.filter((b) => b.minutes !== '').map((b) => ({ up_to_minutes: Number(b.minutes), percent_bp: Math.round(Number(b.percent) * 100) })),
            },
            reload: ['catalogue'],
        });
        if (done !== null) setForm({ ...form, reason: '' });
    }

    return (
        <FrontOfficeShell description={t('fo.stayfee.description', { checkIn: catalogue.standard.check_in, checkOut: catalogue.standard.check_out })} title={t('fo.stayfee.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {catalogue.policies.length === 0 ? <EmptyState title={t('fo.stayfee.empty')} /> : (
                <ul className="flex flex-col gap-2 text-sm" data-testid="stay-fee-policies">
                    {catalogue.policies.map((p) => (
                        <li className="border border-border p-3" key={p.id}>
                            <p className="font-medium">{t(`fo.stayfee.kind.${p.kind}` as 'fo.stayfee.kind.late_checkout')} · {t('fo.stayfee.from', { date: format.date(p.effective_from) })}</p>
                            <p className="text-muted-foreground">{t('fo.stayfee.rule', { grace: p.grace_minutes })} {p.bands.map((b) => t('fo.stayfee.band', { minutes: b.up_to_minutes, percent: pct(b.percent_bp) })).join(' ')} {t('fo.stayfee.beyond', { percent: pct(p.beyond_bp) })}</p>
                            <p className="text-xs text-muted-foreground">{p.reason}</p>
                        </li>
                    ))}
                </ul>
            )}

            <section aria-labelledby="sf-new-h" className="flex max-w-xl flex-col gap-3 border-t border-border pt-4">
                <h2 className="text-lg font-semibold" id="sf-new-h">{t('fo.stayfee.new')}</h2>
                <p className="text-xs text-muted-foreground">{t('fo.stayfee.note')}</p>
                <FormField field="kind" error={action.fieldError('kind')} label={t('fo.stayfee.kind')}><Select onChange={(e) => setForm({ ...form, kind: e.target.value })} value={form.kind}>{catalogue.kinds.map((k) => <option key={k} value={k}>{t(`fo.stayfee.kind.${k}` as 'fo.stayfee.kind.late_checkout')}</option>)}</Select></FormField>
                <FormField field="effective_from" error={action.fieldError('effective_from')} label={t('fo.stayfee.effective')}><DatePicker min={catalogue.business_date} onChange={(e) => setForm({ ...form, from: e.target.value })} value={form.from} /></FormField>
                <FormField field="grace_minutes" error={action.fieldError('grace_minutes')} hint={t('fo.stayfee.graceHint')} label={t('fo.stayfee.grace')}><Input inputMode="numeric" onChange={(e) => setForm({ ...form, grace: e.target.value })} value={form.grace} /></FormField>
                <fieldset className="flex flex-col gap-2">
                    <legend className="text-sm font-medium">{t('fo.stayfee.bands')}</legend>
                    {bands.map((b, i) => (
                        <div className="flex flex-wrap items-end gap-2" key={i}>
                            <FormField field="bands.*.up_to_minutes" label={t('fo.stayfee.upTo')}><Input aria-label={`${t('fo.stayfee.upTo')} ${i + 1}`} className="w-28" inputMode="numeric" onChange={(e) => setBands(bands.map((x, j) => (j === i ? { ...x, minutes: e.target.value } : x)))} value={b.minutes} /></FormField>
                            <FormField field="bands.*.percent_bp" label={t('fo.stayfee.share')}><Input aria-label={`${t('fo.stayfee.share')} ${i + 1}`} className="w-28" inputMode="decimal" onChange={(e) => setBands(bands.map((x, j) => (j === i ? { ...x, percent: e.target.value } : x)))} value={b.percent} /></FormField>
                            <Button onClick={() => setBands(bands.filter((_, j) => j !== i))} size="sm" type="button" variant="outline">{t('fo.stayfee.removeBand')}</Button>
                        </div>
                    ))}
                    {action.fieldError('bands') ? <p className="text-sm text-danger">{action.fieldError('bands')}</p> : null}
                    {bands.length < 6 ? <div><Button onClick={() => setBands([...bands, { minutes: '', percent: '' }])} size="sm" type="button" variant="outline">{t('fo.stayfee.addBand')}</Button></div> : null}
                </fieldset>
                <FormField field="beyond_bp" error={action.fieldError('beyond_bp')} hint={t('fo.stayfee.beyondHint')} label={t('fo.stayfee.beyondLabel')}><Input inputMode="decimal" onChange={(e) => setForm({ ...form, beyond: e.target.value })} value={form.beyond} /></FormField>
                <FormField field="reason" error={action.fieldError('reason')} label={t('fo.stayfee.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} /></FormField>
                <div><Button disabled={form.reason.trim() === ''} loading={action.busy} onClick={() => void save()} type="button">{t('fo.stayfee.save')}</Button></div>
            </section>
        </FrontOfficeShell>
    );
}
