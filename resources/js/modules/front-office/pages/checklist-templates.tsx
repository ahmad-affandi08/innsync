import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Template = { id: string; name: string; version: number; frequency: string; items: { id: string; text: string }[]; is_active: boolean };
type Props = { catalogue: { templates: Template[]; frequencies: string[] } };

/** Management writes the front desk checklists; every change is a new version (FR-FO-032). */
export default function ChecklistTemplatesPage({ catalogue }: Props) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ name: string; frequency: string; items: string; active: boolean } | null>(null);

    async function save() {
        if (form === null) return;
        const done = await action.run('/front-office/checklists/templates', { body: { name: form.name, frequency: form.frequency, items: form.items.split('\n').map((l) => l.trim()).filter((l) => l !== ''), active: form.active }, reload: ['catalogue'] });
        if (done !== null) setForm(null);
    }

    return (
        <FrontOfficeShell description={t('fo.sop.tpl.description')} title={t('fo.sop.tpl.title')} wide>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button asChild size="sm" variant="outline"><Link href="/front-office/checklists">{t('fo.sop.nav')}</Link></Button>
                <Button onClick={() => { action.clear(); setForm({ name: '', frequency: 'daily', items: '', active: true }); }} size="sm" type="button">{t('fo.sop.tpl.new')}</Button>
            </div>
            {action.error !== null && form === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {catalogue.templates.length === 0 ? <EmptyState title={t('fo.sop.empty')} /> : (
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="templates">
                    {catalogue.templates.map((tp) => (
                        <li className="flex flex-col gap-1 py-2" key={tp.id}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium">{t('fo.sop.tpl.row', { name: tp.name, frequency: t(`fo.sop.frequency.${tp.frequency}` as 'fo.sop.frequency.daily'), items: tp.items.length })} · {t('fo.sop.tpl.version', { n: tp.version })}</span>
                                <span className="flex items-center gap-2">
                                    {!tp.is_active ? <StatusBadge label={t('fo.sop.tpl.retired')} tone="neutral" /> : null}
                                    <Button onClick={() => { action.clear(); setForm({ name: tp.name, frequency: tp.frequency, items: tp.items.map((i) => i.text).join('\n'), active: tp.is_active }); }} size="sm" type="button" variant="outline">{t('fo.sop.tpl.edit')}</Button>
                                </span>
                            </div>
                            <ol className="list-decimal pl-5 text-xs text-muted-foreground">{tp.items.map((i) => <li key={i.id}>{i.text}</li>)}</ol>
                        </li>
                    ))}
                </ul>
            )}

            {form !== null && (
                <Dialog footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button loading={action.busy} onClick={() => void save()} type="button">{t('fo.sop.tpl.save')}</Button></>} onClose={() => setForm(null)} open title={t('fo.sop.tpl.new')}>
                    <div className="flex flex-col gap-3" data-testid="template-form">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField field="name" error={action.fieldError('name')} hint={t('fo.sop.tpl.nameHint')} label={t('fo.sop.tpl.name')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                        <FormField field="frequency" error={action.fieldError('frequency')} label={t('fo.sop.tpl.frequency')}>
                            <Select onChange={(e) => setForm({ ...form, frequency: e.target.value })} value={form.frequency}>{catalogue.frequencies.map((f) => <option key={f} value={f}>{t(`fo.sop.frequency.${f}` as 'fo.sop.frequency.daily')}</option>)}</Select>
                        </FormField>
                        <FormField field="items" error={action.fieldError('items')} label={t('fo.sop.tpl.items')}><Textarea onChange={(e) => setForm({ ...form, items: e.target.value })} rows={8} value={form.items} /></FormField>
                        <label className="flex items-center gap-2 text-sm"><input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />{t('fo.sop.tpl.active')}</label>
                    </div>
                </Dialog>
            )}
        </FrontOfficeShell>
    );
}
