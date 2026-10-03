import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { RoutineShell } from '@/modules/routines/components/routine-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Template = { id: string; name: string; version: number; frequency: string; items: { id: string; text: string }[]; is_active: boolean };
type Props = { overview: { templates: Template[]; frequencies: string[] }; department: string };

/** Management writes the checklists of the department; every change is a new version (FR-KIT-008, FR-FBS-032). */
export default function RoutineTemplatesPage({ department, overview }: Props) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ name: '', frequency: 'daily', items: '', active: true });

    async function save() {
        const done = await action.run(`/${department}/routines/templates`, { body: { name: form.name, frequency: form.frequency, items: form.items.split('\n').map((l) => l.trim()).filter((l) => l !== ''), active: form.active }, reload: ['overview'] });
        if (done !== null) setForm({ name: '', frequency: 'daily', items: '', active: true });
    }

    return (
        <RoutineShell department={department} description={t('rtn.tpl.description')} title={t('rtn.tpl.title')}>
            <div><Button asChild size="sm" variant="outline"><Link href={`/${department}/routines`}>{t('rtn.nav')}</Link></Button></div>
            {overview.templates.length === 0 ? <EmptyState title={t('rtn.empty')} /> : (
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="templates">
                    {overview.templates.map((tp) => (
                        <li className="flex flex-col gap-1 py-2" key={tp.id}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium">{t('rtn.tpl.row', { name: tp.name, frequency: t(`rtn.frequency.${tp.frequency}` as 'rtn.frequency.daily'), items: tp.items.length })} · {t('rtn.tpl.version', { n: tp.version })}</span>
                                <span className="flex items-center gap-2">
                                    {!tp.is_active ? <StatusBadge label={t('rtn.tpl.retired')} tone="neutral" /> : null}
                                    <Button onClick={() => setForm({ name: tp.name, frequency: tp.frequency, items: tp.items.map((i) => i.text).join('\n'), active: tp.is_active })} size="sm" type="button" variant="outline">{t('rtn.tpl.edit')}</Button>
                                </span>
                            </div>
                            <ol className="list-decimal pl-5 text-xs text-muted-foreground">{tp.items.map((i) => <li key={i.id}>{i.text}</li>)}</ol>
                        </li>
                    ))}
                </ul>
            )}

            <section aria-labelledby="tpl-h" className="flex max-w-xl flex-col gap-3">
                <h2 className="text-lg font-semibold" id="tpl-h">{t('rtn.tpl.new')}</h2>
                {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                <FormField error={action.fieldError('name')} field="name" hint={t('rtn.tpl.nameHint')} label={t('rtn.tpl.name')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                <FormField error={action.fieldError('frequency')} field="frequency" label={t('rtn.tpl.frequency')}>
                    <Select onChange={(e) => setForm({ ...form, frequency: e.target.value })} value={form.frequency}>{overview.frequencies.map((f) => <option key={f} value={f}>{t(`rtn.frequency.${f}` as 'rtn.frequency.daily')}</option>)}</Select>
                </FormField>
                <FormField error={action.fieldError('items')} field="items" label={t('rtn.tpl.items')}><Textarea onChange={(e) => setForm({ ...form, items: e.target.value })} rows={8} value={form.items} /></FormField>
                <label className="flex items-center gap-2 text-sm"><input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />{t('rtn.tpl.active')}</label>
                <div><Button loading={action.busy} onClick={() => void save()} type="button">{t('rtn.tpl.save')}</Button></div>
            </section>
        </RoutineShell>
    );
}
