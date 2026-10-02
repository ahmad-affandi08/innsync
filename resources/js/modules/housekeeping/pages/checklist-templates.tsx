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
import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Template = { id: string; name: string; version: number; frequency: string; scope: string; areas: string[]; items: { id: string; text: string; photo_required?: boolean }[]; is_active: boolean };
type ItemRow = { text: string; photo: boolean };
type Props = { catalogue: { templates: Template[]; frequencies: string[]; scopes: string[] } };
const BLANK = { name: '', frequency: 'daily', scope: 'room', areas: '', active: true };
const BLANK_ITEMS: ItemRow[] = [{ text: '', photo: false }];
const lines = (text: string) => text.split('\n').map((l) => l.trim()).filter((l) => l !== '');

/** Management writes the housekeeping checklists; every change is a new version (FR-HK-005). */
export default function ChecklistTemplatesPage({ catalogue }: Props) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState(BLANK);
    const [items, setItems] = useState<ItemRow[]>(BLANK_ITEMS);

    async function save() {
        const done = await action.run('/housekeeping/checklists/templates', { body: { name: form.name, frequency: form.frequency, scope: form.scope, areas: lines(form.areas), items: items.filter((i) => i.text.trim() !== '').map((i) => ({ text: i.text.trim(), photo_required: i.photo })), active: form.active }, reload: ['catalogue'] });
        if (done !== null) { setForm(BLANK); setItems(BLANK_ITEMS); }
    }

    return (
        <HousekeepingShell description={t('hk.cl.tpl.description')} title={t('hk.cl.tpl.title')} wide>
            <div><Button asChild size="sm" variant="outline"><Link href="/housekeeping/checklists">{t('hk.cl.nav')}</Link></Button></div>
            {catalogue.templates.length === 0 ? <EmptyState title={t('hk.cl.empty')} /> : (
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="templates">
                    {catalogue.templates.map((tp) => (
                        <li className="flex flex-col gap-1 py-2" key={tp.id}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium">{t('hk.cl.tpl.row', { name: tp.name, frequency: t(`hk.cl.frequency.${tp.frequency}` as 'hk.cl.frequency.daily'), items: tp.items.length })} · {t(`hk.cl.scope.${tp.scope}` as 'hk.cl.scope.room')} · {t('hk.cl.tpl.version', { n: tp.version })}</span>
                                <span className="flex items-center gap-2">
                                    {!tp.is_active ? <StatusBadge label={t('hk.cl.tpl.retired')} tone="neutral" /> : null}
                                    <Button onClick={() => { setForm({ name: tp.name, frequency: tp.frequency, scope: tp.scope, areas: tp.areas.join('\n'), active: tp.is_active }); setItems(tp.items.map((i) => ({ text: i.text, photo: i.photo_required === true }))); }} size="sm" type="button" variant="outline">{t('hk.cl.tpl.edit')}</Button>
                                </span>
                            </div>
                            {tp.areas.length > 0 ? <p className="text-xs text-muted-foreground">{tp.areas.join(', ')}</p> : null}
                            <ol className="list-decimal pl-5 text-xs text-muted-foreground">{tp.items.map((i) => <li key={i.id}>{i.text}{i.photo_required === true ? ` (${t('hk.cl.tpl.photoTag')})` : ''}</li>)}</ol>
                        </li>
                    ))}
                </ul>
            )}

            <section aria-labelledby="hk-tpl-h" className="flex max-w-xl flex-col gap-3">
                <h2 className="text-lg font-semibold" id="hk-tpl-h">{t('hk.cl.tpl.new')}</h2>
                {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                <FormField error={action.fieldError('name')} hint={t('hk.cl.tpl.nameHint')} label={t('hk.cl.tpl.name')}><Input maxLength={80} onChange={(e) => setForm({ ...form, name: e.target.value })} value={form.name} /></FormField>
                <FormField error={action.fieldError('frequency')} label={t('hk.cl.tpl.frequency')}>
                    <Select onChange={(e) => setForm({ ...form, frequency: e.target.value })} value={form.frequency}>{catalogue.frequencies.map((f) => <option key={f} value={f}>{t(`hk.cl.frequency.${f}` as 'hk.cl.frequency.daily')}</option>)}</Select>
                </FormField>
                <FormField error={action.fieldError('scope')} label={t('hk.cl.tpl.scope')}>
                    <Select onChange={(e) => setForm({ ...form, scope: e.target.value })} value={form.scope}>{catalogue.scopes.map((s) => <option key={s} value={s}>{t(`hk.cl.scope.${s}` as 'hk.cl.scope.room')}</option>)}</Select>
                </FormField>
                {form.scope === 'area' ? <FormField error={action.fieldError('areas')} hint={t('hk.cl.tpl.areasHint')} label={t('hk.cl.tpl.areas')}><Textarea onChange={(e) => setForm({ ...form, areas: e.target.value })} rows={4} value={form.areas} /></FormField> : null}
                <fieldset className="flex flex-col gap-2">
                    <legend className="text-sm font-medium">{t('hk.cl.tpl.items')}</legend>
                    {items.map((row, i) => (
                        <div className="flex flex-wrap items-center gap-2" key={i}>
                            <Input aria-label={`${t('hk.cl.tpl.item')} ${i + 1}`} className="min-w-64 flex-1" maxLength={160} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)))} value={row.text} />
                            <label className="flex items-center gap-1 text-xs"><input checked={row.photo} onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, photo: e.target.checked } : x)))} type="checkbox" />{t('hk.cl.tpl.photoNeeded')}</label>
                            {items.length > 1 ? <Button onClick={() => setItems(items.filter((_, j) => j !== i))} size="sm" type="button" variant="outline">{t('hk.cl.tpl.removeItem')}</Button> : null}
                        </div>
                    ))}
                    {action.fieldError('items') ? <p className="text-sm text-danger">{action.fieldError('items')}</p> : null}
                    {items.length < 40 ? <div><Button onClick={() => setItems([...items, { text: '', photo: false }])} size="sm" type="button" variant="outline">{t('hk.cl.tpl.addItem')}</Button></div> : null}
                </fieldset>
                <label className="flex items-center gap-2 text-sm"><input checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} type="checkbox" />{t('hk.cl.tpl.active')}</label>
                <div><Button loading={action.busy} onClick={() => void save()} type="button">{t('hk.cl.tpl.save')}</Button></div>
            </section>
        </HousekeepingShell>
    );
}
