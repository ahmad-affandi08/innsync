import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Metric } from '@/components/ui/metric';
import { MaintenanceShell } from '@/modules/maintenance/components/maintenance-shell';
import type { MaintenanceReport } from '@/modules/maintenance/lib/maintenance';
import { useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

/** How the work went in a period: what was reported, how long it took, the rooms that broke again, and the days rooms could not be sold. */
export default function MaintenanceReportsPage({ report }: { report: MaintenanceReport }) {
    const { t } = useTranslation();
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const hours = (h: number | null) => (h === null ? '—' : t('mtc.rep.hours', { n: h }));
    const statuses = Object.entries(report.by_status);
    const departments = Object.entries(report.by_department);

    const repeat: DataGridColumn<MaintenanceReport['repeat_rooms'][number]>[] = [
        { id: 'room', label: t('mtc.rep.room'), value: (r) => r.room, rowHeader: true },
        { id: 'count', label: t('mtc.rep.count'), align: 'right', value: (r) => r.count },
        { id: 'cats', label: t('mtc.col.category'), value: (r) => r.categories.join(', '), cell: (r) => r.categories.map((c) => label('mtc.category', c)).join(', ') },
    ];
    const unsellable: DataGridColumn<MaintenanceReport['unsellable']['rooms'][number]>[] = [
        { id: 'room', label: t('mtc.rep.room'), value: (r) => r.room, rowHeader: true },
        { id: 'days', label: t('mtc.rep.days'), align: 'right', value: (r) => r.days },
    ];

    return (
        <MaintenanceShell description={t('mtc.rep.description')} title={t('mtc.rep.title')}>
            <div className="flex flex-wrap items-end gap-2 print:hidden">
                <FormField label={t('mtc.rep.from')}><DatePicker onChange={(e) => setFrom(e.target.value)} value={from} /></FormField>
                <FormField label={t('mtc.rep.to')}><DatePicker onChange={(e) => setTo(e.target.value)} value={to} /></FormField>
                <Button disabled={from === '' || to === ''} onClick={() => router.get('/maintenance/reports', { from, to })} type="button">{t('mtc.rep.show')}</Button>
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="mtc-rep-kpis">
                <Metric label={t('mtc.rep.reported')} value={String(report.reported)} />
                <Metric detail={t('mtc.rep.doneCount', { n: report.completion.done })} label={t('mtc.rep.average')} value={hours(report.completion.average_hours)} />
                <Metric detail={t('mtc.rep.onTimeOf', { on: report.completion.on_time, of: report.completion.done })} label={t('mtc.rep.onTime')} value={report.completion.on_time_percent === null ? '—' : `${report.completion.on_time_percent}%`} />
                <Metric label={t('mtc.rep.unsellable')} value={t('mtc.rep.nights', { n: report.unsellable.total_days })} />
            </div>
            <div className="grid gap-4 lg:grid-cols-2">
                <section aria-labelledby="mtc-rep-status" className="flex flex-col gap-2 border border-border bg-surface p-3">
                    <h2 className="font-semibold" id="mtc-rep-status">{t('mtc.rep.byStatus')}</h2>
                    {statuses.length === 0 ? <p className="text-sm text-muted-foreground">{t('mtc.rep.none')}</p> : <ul className="text-sm" data-testid="mtc-rep-status">{statuses.map(([k, n]) => <li className="flex justify-between" key={k}><span>{label('mtc.status', k)}</span><span className="tabular-nums">{n}</span></li>)}</ul>}
                </section>
                <section aria-labelledby="mtc-rep-dept" className="flex flex-col gap-2 border border-border bg-surface p-3">
                    <h2 className="font-semibold" id="mtc-rep-dept">{t('mtc.rep.byDepartment')}</h2>
                    {departments.length === 0 ? <p className="text-sm text-muted-foreground">{t('mtc.rep.none')}</p> : <ul className="text-sm" data-testid="mtc-rep-dept">{departments.map(([k, n]) => <li className="flex justify-between" key={k}><span>{label('mtc.department', k)}</span><span className="tabular-nums">{n}</span></li>)}</ul>}
                </section>
                <section aria-labelledby="mtc-rep-prio" className="flex flex-col gap-2 border border-border bg-surface p-3">
                    <h2 className="font-semibold" id="mtc-rep-prio">{t('mtc.rep.byPriority')}</h2>
                    <ul className="text-sm">{report.completion.by_priority.map((p) => <li className="flex justify-between" key={p.priority}><span>{label('mtc.priority', p.priority)} ({p.done})</span><span className="tabular-nums">{hours(p.average_hours)}</span></li>)}</ul>
                </section>
            </div>
            <section aria-labelledby="mtc-rep-repeat" className="flex flex-col gap-2">
                <h2 className="font-semibold" id="mtc-rep-repeat">{t('mtc.rep.repeat')}</h2>
                <DataGrid caption={t('mtc.rep.repeat')} columns={repeat} empty={<EmptyState illustration="checklist" title={t('mtc.rep.noRepeat')} />} getRowId={(r) => r.room} id="mtc.rep.repeat" rows={report.repeat_rooms} testId="mtc-rep-repeat-grid" />
            </section>
            <section aria-labelledby="mtc-rep-unsellable" className="flex flex-col gap-2">
                <h2 className="font-semibold" id="mtc-rep-unsellable">{t('mtc.rep.unsellable')}</h2>
                <p className="text-sm text-muted-foreground">{t('mtc.rep.unsellableHint')}</p>
                <DataGrid caption={t('mtc.rep.unsellable')} columns={unsellable} empty={<EmptyState illustration="checklist" title={t('mtc.rep.noUnsellable')} />} getRowId={(r) => r.room} id="mtc.rep.unsellable" rows={report.unsellable.rooms} testId="mtc-rep-unsellable-grid" />
            </section>
        </MaintenanceShell>
    );
}
