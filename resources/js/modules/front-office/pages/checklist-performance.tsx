import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type Run = { run_id: string; template: string; frequency: string; period_key: string; items: number; completed: number; percent: number };
type Props = {
    report: {
        from: string; to: string; runs: Run[];
        people: { user_id: string; name: string | null; items: number }[];
    };
};

/** How much of each checklist was done and by whom (FR-FO-033). */
export default function ChecklistPerformancePage({ report: r }: Props) {
    const { t } = useTranslation();
    const [range, setRange] = useState({ from: r.from, to: r.to });

    const columns: DataGridColumn<Run>[] = [
        { id: 'run', label: t('fo.sop.perf.runs'), value: (x) => `${x.template} · ${x.period_key}`, rowHeader: true, cell: (x) => t('fo.sop.perf.run', { name: x.template, period: x.period_key }) },
        { id: 'frequency', label: t('fo.sop.tpl.frequency'), value: (x) => x.frequency, filter: 'select', filterLabel: (v) => t(`fo.sop.frequency.${v}` as 'fo.sop.frequency.daily'), cell: (x) => t(`fo.sop.frequency.${x.frequency}` as 'fo.sop.frequency.daily'), hidden: true },
        { id: 'items', label: t('fo.sop.perf.col.items'), align: 'right', value: (x) => x.items, cell: (x) => t('fo.sop.perf.items', { count: x.items }) },
        { id: 'progress', label: t('fo.sop.perf.col.progress'), align: 'right', value: (x) => x.percent, searchText: (x) => t('fo.sop.progress', { done: x.completed, total: x.items, percent: x.percent }), cell: (x) => t('fo.sop.progress', { done: x.completed, total: x.items, percent: x.percent }) },
    ];

    return (
        <FrontOfficeShell description={t('fo.sop.perf.description')} title={t('fo.sop.perf.title')} wide>
            <div><Button asChild size="sm" variant="outline"><Link href="/front-office/checklists">{t('fo.sop.nav')}</Link></Button></div>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); router.get('/front-office/checklists/performance', range); }}>
                <FormField label={t('fo.sop.perf.from')}><DatePicker onChange={(e) => setRange({ ...range, from: e.target.value })} required value={range.from} /></FormField>
                <FormField label={t('fo.sop.perf.to')}><DatePicker onChange={(e) => setRange({ ...range, to: e.target.value })} required value={range.to} /></FormField>
                <Button type="submit" variant="outline">{t('fo.sop.perf.apply')}</Button>
            </form>

            <section aria-labelledby="runs-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="runs-h">{t('fo.sop.perf.runs')}</h2>
                <DataGrid
                    caption={t('fo.sop.perf.runs')}
                    columns={columns}
                    empty={<EmptyState title={t('fo.sop.perf.noRuns')} />}
                    getRowId={(x) => x.run_id}
                    id="fo.checklists.runs"
                    rows={r.runs}
                    testId="runs"
                />
            </section>

            <section aria-labelledby="people-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="people-h">{t('fo.sop.perf.people')}</h2>
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="people">{r.people.map((p) => <li className="flex justify-between py-1" key={p.user_id}><span>{p.name ?? '—'}</span><span>{p.items}</span></li>)}</ul>
            </section>
        </FrontOfficeShell>
    );
}
