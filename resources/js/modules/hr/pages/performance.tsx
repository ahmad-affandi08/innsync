import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Select } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { HrShell } from '@/modules/hr/components/hr-shell';
import type { PerformanceOverview, PerformanceRow, PerformanceSource } from '@/modules/hr/lib/hr';
import { useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

/** How people are doing: attendance, punctuality, the routines each one did, the complaints they own, and how far each source's checklists got. It counts; it does not judge. */
export default function PerformancePage({ overview }: { overview: PerformanceOverview }) {
    const { t } = useTranslation();
    const [from, setFrom] = useState(overview.from);
    const [to, setTo] = useState(overview.to);
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const percent = (value: number | null) => (value === null ? '—' : `${value}%`);
    const hm = (minutes: number) => `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
    const show = (department: string | null) => router.get('/hr/performance', { from, to, ...(department === null || department === '' ? {} : { department }) });

    const boardColumns: DataGridColumn<PerformanceRow>[] = [
        { id: 'name', label: t('hr.col.name'), value: (r) => r.employee.name, rowHeader: true, cell: (r) => <span>{r.employee.name}<span className="block text-xs text-muted-foreground">{r.employee.number} · {label('hr.department', r.employee.department)}</span></span> },
        { id: 'attendance', label: t('hr.perf.attendance'), align: 'right', value: (r) => r.attendance ?? -1, cell: (r) => <span>{percent(r.attendance)}<span className="block text-xs text-muted-foreground">{t('hr.perf.presentOf', { present: r.present, scheduled: r.scheduled })}</span></span> },
        { id: 'punctuality', label: t('hr.perf.punctuality'), align: 'right', value: (r) => r.punctuality ?? -1, cell: (r) => <span>{percent(r.punctuality)}<span className="block text-xs text-muted-foreground">{t('hr.perf.lateOf', { days: r.late_days, time: hm(r.late_minutes) })}</span></span> },
        { id: 'absent', label: t('hr.att.absent'), align: 'right', value: (r) => r.absent, cell: (r) => (r.absent > 0 ? <span className="text-danger">{r.absent}</span> : '0') },
        { id: 'sop', label: t('hr.perf.sopItems'), align: 'right', value: (r) => r.sop_items, cell: (r) => (r.linked ? <span>{r.sop_items}<span className="block text-xs text-muted-foreground">{Object.entries(r.sop_by).map(([k, n]) => `${label('hr.perf.source', k)} ${n}`).join(' · ')}</span></span> : '—') },
        { id: 'complaints', label: t('hr.perf.complaints'), align: 'right', value: (r) => r.complaints.total, cell: (r) => (r.linked ? <span>{r.complaints.total}<span className="block text-xs text-muted-foreground">{t('hr.perf.complaintsDetail', { resolved: r.complaints.resolved, serious: r.complaints.serious })}</span></span> : '—') },
        { id: 'overtime', label: t('hr.att.overtimeHours'), align: 'right', value: (r) => r.overtime_minutes, cell: (r) => <span>{hm(r.overtime_minutes)}{r.unapproved_minutes > 0 ? <span className="block text-xs text-warning">{t('hr.att.unapproved', { n: r.unapproved_minutes })}</span> : null}</span> },
        { id: 'linked', label: t('hr.perf.account'), value: (r) => (r.linked ? 1 : 0), cell: (r) => (r.linked ? t('hr.perf.linked') : t('hr.perf.notLinked')) },
    ];
    const sourceColumns: DataGridColumn<PerformanceSource>[] = [
        { id: 'source', label: t('hr.perf.sourceCol'), value: (r) => label('hr.perf.source', r.source), rowHeader: true },
        { id: 'runs', label: t('hr.perf.runs'), align: 'right', value: (r) => r.runs },
        { id: 'complete', label: t('hr.perf.complete'), align: 'right', value: (r) => r.complete },
        { id: 'items', label: t('hr.perf.items'), align: 'right', value: (r) => r.done, cell: (r) => `${r.done} / ${r.items}` },
        { id: 'percent', label: t('hr.perf.percent'), align: 'right', value: (r) => r.percent, cell: (r) => `${r.percent}%` },
    ];

    return (
        <HrShell description={t('hr.perf.description')} title={t('hr.perf.title')}>
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <FormField label={t('mtc.rep.from')}><DatePicker onChange={(e) => setFrom(e.target.value)} value={from} /></FormField>
                <FormField label={t('mtc.rep.to')}><DatePicker onChange={(e) => setTo(e.target.value)} value={to} /></FormField>
                <FormField label={t('hr.col.department')}><Select onChange={(e) => show(e.target.value)} value={overview.department ?? ''}><option value="">{t('hr.roster.allDepartments')}</option>{overview.departments.map((d) => <option key={d} value={d}>{label('hr.department', d)}</option>)}</Select></FormField>
                <Button disabled={from === '' || to === ''} onClick={() => show(overview.department)} type="button">{t('mtc.rep.show')}</Button>
            </div>
            <Tabs defaultValue="board">
                <TabsList>
                    <TabsTrigger value="board">{t('hr.perf.boardTab')}</TabsTrigger>
                    <TabsTrigger value="sop">{t('hr.perf.sopTab')}</TabsTrigger>
                </TabsList>
                <TabsContent value="board">
                    <DataGrid caption={t('hr.perf.boardTab')} columns={boardColumns} empty={<EmptyState illustration="checklist" title={t('hr.perf.noPeople')} />} getRowId={(r) => r.employee.id} id="hr.perf.board" rows={overview.board} testId="hr-perf-board" />
                </TabsContent>
                <TabsContent value="sop">
                    <p className="mb-3 text-sm text-muted-foreground">{t('hr.perf.sopHint')}</p>
                    <DataGrid caption={t('hr.perf.sopTab')} columns={sourceColumns} empty={<EmptyState illustration="checklist" title={t('hr.perf.noRuns')} />} getRowId={(r) => r.source} id="hr.perf.sources" rows={overview.sources} testId="hr-perf-sources" />
                </TabsContent>
            </Tabs>
        </HrShell>
    );
}
