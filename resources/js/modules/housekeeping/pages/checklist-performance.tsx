import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { StatusBadge } from '@/components/ui/status-badge';
import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type Props = {
    report: {
        from: string; to: string; percent: number;
        runs: { run_id: string; template: string; frequency: string; period_key: string; target: string; items: number; completed: number; percent: number }[];
        people: { user_id: string; name: string | null; items: number }[];
    };
};

type Run = Props['report']['runs'][number];

/** How much of each checklist was done, in which room or area, and by whom (FR-HK-005). */
export default function ChecklistPerformancePage({ report: r }: Props) {
    const { t } = useTranslation();
    const [range, setRange] = useState({ from: r.from, to: r.to });

    const columns: DataGridColumn<Run>[] = [
        { id: 'run', label: t('hk.cl.perf.runs'), value: (x) => t('hk.cl.perf.run', { name: x.template, target: x.target, period: x.period_key }), rowHeader: true },
        { id: 'frequency', label: t('hk.cl.tpl.frequency'), value: (x) => x.frequency, filter: 'select', filterLabel: (v) => t(`hk.cl.frequency.${v}` as 'hk.cl.frequency.daily'), cell: (x) => t(`hk.cl.frequency.${x.frequency}` as 'hk.cl.frequency.daily') },
        { id: 'progress', label: t('hk.cl.perf.progress'), align: 'right', value: (x) => x.percent, searchText: (x) => t('hk.cl.progress', { done: x.completed, total: x.items, percent: x.percent }), cell: (x) => t('hk.cl.progress', { done: x.completed, total: x.items, percent: x.percent }) },
    ];

    return (
        <HousekeepingShell description={t('hk.cl.perf.description')} title={t('hk.cl.perf.title')} wide>
            <div><Button asChild size="sm" variant="outline"><Link href="/housekeeping/checklists">{t('hk.cl.nav')}</Link></Button></div>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); router.get('/housekeeping/checklists/performance', range); }}>
                <FormField label={t('hk.cl.perf.from')}><DatePicker onChange={(e) => setRange({ ...range, from: e.target.value })} required value={range.from} /></FormField>
                <FormField label={t('hk.cl.perf.to')}><DatePicker onChange={(e) => setRange({ ...range, to: e.target.value })} required value={range.to} /></FormField>
                <Button size="sm" type="submit" variant="outline">{t('hk.cl.perf.apply')}</Button>
            </form>
            <p className="text-sm">{t('hk.cl.perf.overall')} <StatusBadge label={`${r.percent}%`} tone={r.percent === 100 ? 'success' : 'info'} /></p>

            <section aria-labelledby="hk-runs-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="hk-runs-h">{t('hk.cl.perf.runs')}</h2>
                <DataGrid
                    caption={t('hk.cl.perf.runs')}
                    columns={columns}
                    empty={<EmptyState title={t('hk.cl.perf.noRuns')} />}
                    getRowId={(x) => x.run_id}
                    id="hk.checklist.runs"
                    rows={r.runs}
                    testId="runs"
                />
            </section>

            <section aria-labelledby="hk-people-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="hk-people-h">{t('hk.cl.perf.people')}</h2>
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="people">{r.people.map((p) => <li className="flex justify-between py-1" key={p.user_id}><span>{p.name ?? '—'}</span><span>{p.items}</span></li>)}</ul>
            </section>
        </HousekeepingShell>
    );
}
