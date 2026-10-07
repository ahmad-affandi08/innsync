import { PropertyShell } from '@/modules/property/components/property-shell';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { StatusBadge } from '@/components/ui/status-badge';
import { useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Step = { key: string; group: 'property' | 'people' | 'operations'; required: boolean; href: string; done: boolean; counts: Record<string, number> };

const GROUPS: Step['group'][] = ['property', 'people', 'operations'];

/** The first-time setup of the property: what is done, what is missing, and where to do it. The list is read from the data, so it is always current. */
export default function SetupPage({ steps, progress }: { steps: Step[]; progress: { done: number; total: number } }) {
    const { t } = useTranslation();
    const key = (step: Step, part: 'title' | 'why' | 'counts') => `setup.step.${step.key}.${part}` as MessageKey;
    const percent = progress.total === 0 ? 100 : Math.round((progress.done / progress.total) * 100);
    const complete = progress.done === progress.total;

    return (
        <PropertyShell description={t('setup.description')} title={t('setup.title')}>
            <section aria-label={t('setup.title')} className="flex flex-col gap-2">
                <p className="text-sm font-medium">{t('setup.progress', { done: progress.done, total: progress.total })}</p>
                <Progress aria-label={t('setup.progress', { done: progress.done, total: progress.total })} value={percent} />
            </section>
            {complete ? <Alert title={t('setup.allDone')} tone="success" /> : null}

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
                                <Button asChild size="sm" variant={s.done || !s.required ? 'outline' : 'default'}><a href={s.href}>{t(s.done ? 'setup.open' : 'setup.fix')}</a></Button>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </PropertyShell>
    );
}
