import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { RoutineShell } from '@/modules/routines/components/routine-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type Run = { run_id: string; template: string; frequency: string; period_key: string; items: number; completed: number; percent: number };
type Props = { report: { from: string; to: string; runs: Run[]; people: { user_id: string; name: string | null; items: number }[] }; department: string };

/** How much of each checklist was done and by whom (FR-KIT-008, FR-FBS-032). */
export default function RoutinePerformancePage({ department, report: r }: Props) {
    const { t } = useTranslation();
    const [range, setRange] = useState({ from: r.from, to: r.to });

    const columns: DataGridColumn<Run>[] = [
        { id: 'run', label: t('rtn.perf.runs'), value: (x) => `${x.template} · ${x.period_key}`, rowHeader: true, cell: (x) => t('rtn.perf.run', { name: x.template, period: x.period_key }) },
        { id: 'frequency', label: t('rtn.tpl.frequency'), value: (x) => x.frequency, filter: 'select', filterLabel: (v) => t(`rtn.frequency.${v}` as 'rtn.frequency.daily'), cell: (x) => t(`rtn.frequency.${x.frequency}` as 'rtn.frequency.daily'), hidden: true },
        { id: 'items', label: t('rtn.perf.items'), align: 'right', value: (x) => x.items },
        { id: 'progress', label: t('rtn.perf.progress'), align: 'right', value: (x) => x.percent, searchText: (x) => t('rtn.progress', { done: x.completed, total: x.items, percent: x.percent }), cell: (x) => t('rtn.progress', { done: x.completed, total: x.items, percent: x.percent }) },
    ];

    return (
        <RoutineShell department={department} description={t('rtn.perf.description')} title={t('rtn.perf.title')}>
            <div><Button asChild size="sm" variant="outline"><Link href={`/${department}/routines`}>{t('rtn.nav')}</Link></Button></div>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); router.get(`/${department}/routines/performance`, range); }}>
                <FormField label={t('rtn.perf.from')}><DatePicker onChange={(e) => setRange({ ...range, from: e.target.value })} required value={range.from} /></FormField>
                <FormField label={t('rtn.perf.to')}><DatePicker onChange={(e) => setRange({ ...range, to: e.target.value })} required value={range.to} /></FormField>
                <Button type="submit" variant="outline">{t('rtn.perf.apply')}</Button>
            </form>
            <DataGrid caption={t('rtn.perf.runs')} columns={columns} empty={<EmptyState title={t('rtn.perf.noRuns')} />} getRowId={(x) => x.run_id} id="rtn.runs" rows={r.runs} testId="runs" />
            <section aria-labelledby="people-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="people-h">{t('rtn.perf.people')}</h2>
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="people">{r.people.map((p) => <li className="flex justify-between py-1" key={p.user_id}><span>{p.name ?? '—'}</span><span>{p.items}</span></li>)}</ul>
            </section>
        </RoutineShell>
    );
}
