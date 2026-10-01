import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { ReportMeta, type Meta } from '@/modules/reporting/components/report-meta';
import { ReportingShell } from '@/modules/reporting/components/reporting-shell';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

type Row = { id: string; occurred_at: string; action: string; aggregate_type: string; aggregate_id: string; reason: string | null; actor_name: string | null };
type Report = { meta: Meta & { filters: Record<string, string> }; rows: Row[]; total: number; page: number; page_size: number; staff: { id: string; name: string }[] };

export default function AuditPage({ report: r }: { report: Report }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const [f, setF] = useState({ from: r.meta.filters.from ?? '', to: r.meta.filters.to ?? '', user: r.meta.filters.user ?? '', module: r.meta.filters.module ?? '' });
    const pages = Math.max(1, Math.ceil(r.total / r.page_size));
    const search = (page = 1) => router.get('/reports/audit', Object.fromEntries(Object.entries({ ...f, page: String(page) }).filter(([, v]) => v !== '')), { preserveScroll: true });

    return (
        <ReportingShell description={t('rpt.audit.description')} title={t('rpt.audit.title')} wide>
            <form className="grid gap-3 sm:grid-cols-4 print:hidden" onSubmit={(e) => { e.preventDefault(); search(); }}>
                <FormField label={t('rpt.period.from')}><Input onChange={(e) => setF({ ...f, from: e.target.value })} type="date" value={f.from} /></FormField>
                <FormField label={t('rpt.period.to')}><Input onChange={(e) => setF({ ...f, to: e.target.value })} type="date" value={f.to} /></FormField>
                <FormField label={t('rpt.audit.user')}>
                    <Select onChange={(e) => setF({ ...f, user: e.target.value })} value={f.user}><option value="">{t('rpt.audit.anyone')}</option>{r.staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select>
                </FormField>
                <FormField label={t('rpt.audit.module')}><Input maxLength={40} onChange={(e) => setF({ ...f, module: e.target.value.toLowerCase() })} value={f.module} /></FormField>
                <div className="sm:col-span-4"><Button size="sm" type="submit">{t('rpt.audit.search')}</Button></div>
            </form>
            <ReportMeta meta={r.meta} />
            <p className="text-sm text-muted-foreground" data-testid="total">{t('rpt.audit.total', { total: r.total })}</p>
            {r.rows.length === 0 ? <EmptyState title={t('rpt.audit.empty')} /> : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('rpt.audit.when')}</th><th scope="col">{t('rpt.audit.who')}</th><th scope="col">{t('rpt.audit.action')}</th><th scope="col">{t('rpt.audit.reason')}</th></tr></thead>
                        <tbody>{r.rows.map((row) => (
                            <tr className="border-t border-border align-top" key={row.id}>
                                <td className="py-1">{format.instant(row.occurred_at.replace(' ', 'T') + 'Z')}</td>
                                <td>{row.actor_name ?? '—'}</td>
                                <td><span className="font-mono text-xs">{row.action}</span><span className="block text-xs text-muted-foreground">{row.aggregate_type} {row.aggregate_id}</span></td>
                                <td>{row.reason ?? ''}</td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            )}
            <nav aria-label={t('ui.pagination.navigation')} className="flex items-center gap-3 print:hidden">
                <Button disabled={r.page <= 1} onClick={() => search(r.page - 1)} size="sm" type="button" variant="outline">{t('rpt.audit.prev')}</Button>
                <span className="text-xs text-muted-foreground">{t('rpt.audit.page', { page: r.page, pages })}</span>
                <Button disabled={r.page >= pages} onClick={() => search(r.page + 1)} size="sm" type="button" variant="outline">{t('rpt.audit.next')}</Button>
            </nav>
        </ReportingShell>
    );
}
