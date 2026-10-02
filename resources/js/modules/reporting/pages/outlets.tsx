import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Outlet = { id: string; code: string; name: string; lock_version: number; sources: string[] };
type Overview = { outlets: Outlet[]; unmapped: string[]; built_in: { code: string; sources: string[] }[] };

/** The outlets beyond rooms and laundry, each owning posting sources (FR-DSH-005). */
export default function OutletsPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState({ code: '', name: '', reason: '' });
    const [source, setSource] = useState<Record<string, { value: string; reason: string }>>({});
    const [rename, setRename] = useState<Record<string, { name: string; reason: string }>>({});
    const reload = ['overview'];

    async function create() {
        const done = await action.run('/reports/outlets', { body: { code: form.code, name: form.name, reason: form.reason.trim() }, reload });
        if (done !== null) setForm({ code: '', name: '', reason: '' });
    }

    return (
        <ReportingShell description={t('rpt.outlets.description')} title={t('rpt.outlets.title')} wide>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <Alert title={t('rpt.outlets.note')} tone="info" />
            <p className="text-sm text-muted-foreground">{t('rpt.outlets.builtInNote')}</p>

            {overview.outlets.length === 0 ? <EmptyState title={t('rpt.outlets.empty')} /> : (
                <ul className="flex flex-col gap-4" data-testid="outlets">{overview.outlets.map((o) => {
                    const s = source[o.id] ?? { value: '', reason: '' };
                    const r = rename[o.id] ?? { name: o.name, reason: '' };
                    return (
                        <li className="flex flex-col gap-2 border border-border p-3" key={o.id}>
                            <p className="font-medium">{o.name} <span className="text-xs text-muted-foreground">({o.code})</span></p>
                            {o.sources.length === 0 ? <p className="text-sm text-muted-foreground">{t('rpt.outlets.noSources')}</p> : (
                                <ul className="flex flex-wrap gap-2 text-sm">{o.sources.map((x) => (
                                    <li className="flex items-center gap-1 border border-border px-2 py-1" key={x}>
                                        <code>{x}</code>
                                        <Button aria-label={`${t('rpt.outlets.removeSource')} ${x}`} disabled={action.busy || s.reason.trim() === ''} onClick={() => void action.run(`/reports/outlets/${o.id}/sources/remove`, { body: { source: x, reason: s.reason.trim() }, reload })} size="sm" type="button" variant="outline">{t('rpt.outlets.removeSource')}</Button>
                                    </li>
                                ))}</ul>
                            )}
                            <div className="flex flex-wrap items-end gap-2">
                                <FormField hint={t('rpt.outlets.sourceHint')} label={`${t('rpt.outlets.source')} (${o.code})`}><Input maxLength={40} onChange={(e) => setSource({ ...source, [o.id]: { ...s, value: e.target.value } })} value={s.value} /></FormField>
                                <FormField label={`${t('rpt.outlets.reason')} (${o.code})`}><Input maxLength={300} onChange={(e) => setSource({ ...source, [o.id]: { ...s, reason: e.target.value } })} value={s.reason} /></FormField>
                                <Button disabled={action.busy || s.value.trim() === '' || s.reason.trim() === ''} onClick={() => void action.run(`/reports/outlets/${o.id}/sources`, { body: { source: s.value.trim(), reason: s.reason.trim() }, reload }).then((d) => { if (d !== null) setSource({ ...source, [o.id]: { value: '', reason: '' } }); })} type="button" variant="outline">{t('rpt.outlets.addSource')}</Button>
                            </div>
                            <div className="flex flex-wrap items-end gap-2">
                                <FormField label={`${t('rpt.outlets.name')} (${o.code})`}><Input maxLength={60} onChange={(e) => setRename({ ...rename, [o.id]: { ...r, name: e.target.value } })} value={r.name} /></FormField>
                                <FormField label={`${t('rpt.outlets.rename')} – ${t('rpt.outlets.reason')} (${o.code})`}><Input maxLength={300} onChange={(e) => setRename({ ...rename, [o.id]: { ...r, reason: e.target.value } })} value={r.reason} /></FormField>
                                <Button disabled={action.busy || r.name.trim() === '' || r.reason.trim() === ''} onClick={() => void action.run(`/reports/outlets/${o.id}`, { body: { name: r.name.trim(), lock_version: o.lock_version, reason: r.reason.trim() }, reload })} type="button" variant="outline">{t('rpt.outlets.rename')}</Button>
                            </div>
                        </li>
                    );
                })}</ul>
            )}

            <section aria-labelledby="outlet-un-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="outlet-un-h">{t('rpt.outlets.unmapped')}</h2>
                {overview.unmapped.length === 0 ? <p className="text-sm text-muted-foreground">{t('rpt.outlets.noneUnmapped')}</p> : <ul className="flex flex-wrap gap-2 text-sm" data-testid="unmapped">{overview.unmapped.map((x) => <li key={x}><code>{x}</code></li>)}</ul>}
            </section>

            <section aria-labelledby="outlet-add-h" className="flex flex-col gap-3 border-t border-border pt-4">
                <h2 className="text-lg font-semibold" id="outlet-add-h">{t('rpt.outlets.add')}</h2>
                <form className="grid gap-3 sm:grid-cols-4" onSubmit={(e) => { e.preventDefault(); void create(); }}>
                    <FormField field="code" error={action.fieldError('code')} label={t('rpt.outlets.code')}><Input maxLength={20} onChange={(e) => setForm({ ...form, code: e.target.value })} required value={form.code} /></FormField>
                    <FormField field="name" error={action.fieldError('name')} label={t('rpt.outlets.name')}><Input maxLength={60} onChange={(e) => setForm({ ...form, name: e.target.value })} required value={form.name} /></FormField>
                    <FormField field="reason" error={action.fieldError('reason')} label={t('rpt.outlets.reason')}><Input maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} required value={form.reason} /></FormField>
                    <div className="flex items-end"><Button loading={action.busy} type="submit">{t('rpt.outlets.add')}</Button></div>
                </form>
            </section>
        </ReportingShell>
    );
}
