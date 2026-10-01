import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status-badge';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Step = { permission: string; approvals_required: number };
type Policy = { id: string; band_min_amount_minor: number; version: number; steps: Step[] };
type Subject = { subject: string; mandatory: boolean; policies: Policy[] };

export default function ApprovalPoliciesPage({ subjects }: { subjects: Subject[] }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ subject: string; band: string; steps: { permission: string; count: string }[]; reason: string } | null>(null);

    async function save() {
        if (form === null) return;
        const done = await action.run('/approvals/policies', {
            body: {
                subject_type: form.subject,
                band_min_amount_minor: Number(form.band || 0),
                steps: form.steps.map((s) => ({ permission: s.permission, approvals_required: Number(s.count || 1) })),
                reason: form.reason,
            },
            reload: ['subjects'],
        });
        if (done !== null) {
            setForm(null);
        }
    }

    return (
        <>
            <Head title={t('identity.approvalPolicies.title')} />
            <main className="min-h-screen bg-surface-muted px-4 py-10">
                <section className="mx-auto flex max-w-3xl flex-col gap-6 border border-border bg-surface p-6 shadow-panel sm:p-8">
                    <PageHeader
                        actions={<><LanguageSwitcher /><Button asChild variant="outline"><Link href="/approvals">{t('identity.approvalPolicies.inbox')}</Link></Button></>}
                        description={t('identity.approvalPolicies.description')}
                        title={t('identity.approvalPolicies.title')}
                    />
                    {form === null && action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    {subjects.map((s) => (
                        <section aria-labelledby={`s-${s.subject}`} className="flex flex-col gap-2" key={s.subject}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-base font-semibold" id={`s-${s.subject}`}>{s.subject}</h2>
                                <Button onClick={() => { action.clear(); setForm({ subject: s.subject, band: '0', steps: [{ permission: '', count: '1' }], reason: '' }); }} size="sm" type="button">{t('identity.approvalPolicies.add')}</Button>
                            </div>
                            {s.mandatory ? <StatusBadge label={t('identity.approvalPolicies.mandatory')} tone="warning" /> : null}
                            {s.policies.length === 0 ? <p className="text-sm text-muted-foreground">{t('identity.approvalPolicies.none')}</p> : (
                                <ul className="divide-y divide-border border-y border-border">
                                    {s.policies.map((p) => (
                                        <li className="py-3 text-sm" key={p.id}>
                                            <p className="font-medium">{t('identity.approvalPolicies.row', { band: p.band_min_amount_minor, version: p.version })}</p>
                                            <ul className="mt-1 list-disc pl-5 text-xs text-muted-foreground">
                                                {p.steps.map((st, i) => <li key={i}>{t('identity.approvalPolicies.step', { n: i + 1, count: st.approvals_required, permission: st.permission })}</li>)}
                                            </ul>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    ))}
                </section>
            </main>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('property.action.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={form === null ? '' : `${t('identity.approvalPolicies.add')}: ${form.subject}`}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('band_min_amount_minor')} hint={t('identity.approvalPolicies.bandHint')} label={t('identity.approvalPolicies.band')}>
                            <Input inputMode="numeric" onChange={(e) => setForm({ ...form, band: e.target.value })} value={form.band} />
                        </FormField>
                        {form.steps.map((step, i) => (
                            <div className="grid grid-cols-[1fr_6rem_auto] items-end gap-2" key={i}>
                                <FormField label={`${t('identity.approvalPolicies.permission')} (${i + 1})`}>
                                    <Input onChange={(e) => setForm({ ...form, steps: form.steps.map((s, j) => (j === i ? { ...s, permission: e.target.value } : s)) })} value={step.permission} />
                                </FormField>
                                <FormField label={t('identity.approvalPolicies.count')}>
                                    <Input inputMode="numeric" onChange={(e) => setForm({ ...form, steps: form.steps.map((s, j) => (j === i ? { ...s, count: e.target.value } : s)) })} value={step.count} />
                                </FormField>
                                {form.steps.length > 1 ? <Button onClick={() => setForm({ ...form, steps: form.steps.filter((_, j) => j !== i) })} size="sm" type="button" variant="outline">{t('identity.approvalPolicies.removeStep')}</Button> : <span />}
                            </div>
                        ))}
                        {form.steps.length < 5 ? <div><Button onClick={() => setForm({ ...form, steps: [...form.steps, { permission: '', count: '1' }] })} size="sm" type="button" variant="outline">{t('identity.approvalPolicies.addStep')}</Button></div> : null}
                        <FormField error={action.fieldError('reason')} hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                            <Input maxLength={500} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </>
    );
}
