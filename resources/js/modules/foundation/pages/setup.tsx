import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { StatusBadge } from '@/components/ui/status-badge';
import type { MessageKey } from '@/locales/en/index';
import { PropertyShell } from '@/modules/property/components/property-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Step = { key: string; group: 'property' | 'people' | 'operations'; required: boolean; href: string; done: boolean; counts: Record<string, number> };
type Profile = { profile: string | null; disabled: string[]; presets: Record<string, string[]>; modules: string[] };

const GROUPS: Step['group'][] = ['property', 'people', 'operations'];
const PROFILES = ['hotel', 'small_resort', 'villa'] as const;

/** The first-time setup of the property: what is done, what is missing, and where to do it. The list is read from the data, so it is always current. */
export default function SetupPage({ steps, progress, profile }: { steps: Step[]; progress: { done: number; total: number }; profile: Profile }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ profile: string; disabled: string[]; reason: string } | null>(null);
    const [outcome, setOutcome] = useState<{ created: string[]; deactivated: string[] } | null>(null);
    const key = (step: Step, part: 'title' | 'why' | 'counts') => `setup.step.${step.key}.${part}` as MessageKey;
    const percent = progress.total === 0 ? 100 : Math.round((progress.done / progress.total) * 100);
    const complete = progress.done === progress.total;

    function openProfile() {
        action.clear();
        setForm({ profile: profile.profile ?? 'hotel', disabled: profile.profile === null ? profile.presets.hotel ?? [] : profile.disabled, reason: '' });
    }

    function choose(value: string) {
        if (form !== null) setForm({ ...form, profile: value, disabled: profile.presets[value] ?? [] });
    }

    function toggleModule(module: string, used: boolean) {
        if (form === null) return;
        setForm({ ...form, disabled: used ? form.disabled.filter((m) => m !== module) : [...new Set([...form.disabled, module])] });
    }

    async function save() {
        if (form === null) return;
        const result = await action.run<{ roles: { created: string[]; deactivated: string[] } }>('/property/profile', {
            method: 'PUT',
            body: { profile: form.profile, disabled: form.disabled, reason: form.reason },
        });
        if (result !== null) {
            setOutcome(result.roles);
            setForm(null);
            window.setTimeout(() => window.location.reload(), 1800);
        }
    }

    return (
        <>
            <PropertyShell description={t('setup.description')} title={t('setup.title')}>
                <section aria-label={t('setup.title')} className="flex flex-col gap-2">
                    <p className="text-sm font-medium">{t('setup.progress', { done: progress.done, total: progress.total })}</p>
                    <Progress aria-label={t('setup.progress', { done: progress.done, total: progress.total })} value={percent} />
                </section>
                {complete ? <Alert title={t('setup.allDone')} tone="success" /> : null}
                {outcome !== null ? <Alert title={t('setup.profile.saved')} tone="success">{t('setup.profile.savedRoles', { created: outcome.created.length, deactivated: outcome.deactivated.length })}</Alert> : null}

                {GROUPS.map((group) => (
                    <section aria-labelledby={`setup-${group}`} className="flex flex-col gap-1" key={group}>
                        <h2 className="text-base font-semibold" id={`setup-${group}`}>{t(`setup.group.${group}` as MessageKey)}</h2>
                        <ul className="divide-y divide-border border-y border-border">
                            {steps.filter((s) => s.group === group).map((s) => (
                                <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" data-testid={`setup-${s.key}`} key={s.key}>
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-sm font-semibold">{t(key(s, 'title'))}</p>
                                            <StatusBadge label={t(s.done ? 'setup.status.done' : s.required ? 'setup.status.todo' : 'setup.status.optional')} tone={s.done ? 'success' : s.required ? 'warning' : 'neutral'} />
                                        </div>
                                        <p className="mt-1 text-sm text-muted-foreground">{t(key(s, 'why'))}</p>
                                        {Object.keys(s.counts).length > 0 ? <p className="mt-0.5 text-xs text-muted-foreground">{t(key(s, 'counts'), s.counts)}</p> : null}
                                    </div>
                                    {s.key === 'profile' ? (
                                        <Button onClick={openProfile} size="sm" type="button" variant={s.done ? 'outline' : 'default'}>{t(s.done ? 'setup.open' : 'setup.fix')}</Button>
                                    ) : (
                                        <Button asChild size="sm" variant={s.done || !s.required ? 'outline' : 'default'}><a href={s.href}>{t(s.done ? 'setup.open' : 'setup.fix')}</a></Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </PropertyShell>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void save()} type="button">{t('property.action.save')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('setup.profile.title')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-4">
                        <p className="text-sm text-muted-foreground">{t('setup.profile.hint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <RadioGroup aria-label={t('setup.profile.title')} className="flex flex-col gap-3" onValueChange={choose} value={form.profile}>
                            {PROFILES.map((p) => (
                                <div className="flex items-start gap-2" key={p}>
                                    <RadioGroupItem id={`profile-${p}`} value={p} />
                                    <Label className="flex flex-col gap-0.5 font-normal" htmlFor={`profile-${p}`}>
                                        <span className="text-sm font-medium">{t(`setup.profile.${p}` as MessageKey)}</span>
                                        <span className="text-xs text-muted-foreground">{t(`setup.profile.${p}.hint` as MessageKey)}</span>
                                    </Label>
                                </div>
                            ))}
                        </RadioGroup>
                        <fieldset className="flex flex-col gap-2">
                            <legend className="text-sm font-medium">{t('setup.profile.modules')}</legend>
                            <p className="text-xs text-muted-foreground">{t('setup.profile.modulesHint')}</p>
                            {profile.modules.map((m) => (
                                <div className="flex items-center gap-2" key={m}>
                                    <Checkbox checked={!form.disabled.includes(m)} id={`module-${m}`} onCheckedChange={(v) => toggleModule(m, v === true)} />
                                    <Label className="text-sm font-normal" htmlFor={`module-${m}`}>{t(`setup.module.${m}` as MessageKey)}</Label>
                                </div>
                            ))}
                        </fieldset>
                        <FormField error={action.fieldError('reason')} field="reason" hint={t('property.field.reasonHint')} label={t('property.field.reason')}>
                            <Input maxLength={500} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </>
    );
}
