import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
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

/** How much of each checklist was done, in which room or area, and by whom (FR-HK-005). */
export default function ChecklistPerformancePage({ report: r }: Props) {
    const { t } = useTranslation();
    const [range, setRange] = useState({ from: r.from, to: r.to });

    return (
        <HousekeepingShell description={t('hk.cl.perf.description')} title={t('hk.cl.perf.title')} wide>
            <div><Button asChild size="sm" variant="outline"><Link href="/housekeeping/checklists">{t('hk.cl.nav')}</Link></Button></div>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); router.get('/housekeeping/checklists/performance', range); }}>
                <FormField label={t('hk.cl.perf.from')}><Input onChange={(e) => setRange({ ...range, from: e.target.value })} required type="date" value={range.from} /></FormField>
                <FormField label={t('hk.cl.perf.to')}><Input onChange={(e) => setRange({ ...range, to: e.target.value })} required type="date" value={range.to} /></FormField>
                <Button size="sm" type="submit" variant="outline">{t('hk.cl.perf.apply')}</Button>
            </form>
            <p className="text-sm">{t('hk.cl.perf.overall')} <StatusBadge label={`${r.percent}%`} tone={r.percent === 100 ? 'success' : 'info'} /></p>

            <section aria-labelledby="hk-runs-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="hk-runs-h">{t('hk.cl.perf.runs')}</h2>
                {r.runs.length === 0 ? <EmptyState title={t('hk.cl.perf.noRuns')} /> : (
                    <table className="w-full text-left text-sm" data-testid="runs">
                        <tbody>{r.runs.map((x) => (
                            <tr className="border-t border-border" key={x.run_id}><th className="py-1 font-medium" scope="row">{t('hk.cl.perf.run', { name: x.template, target: x.target, period: x.period_key })}</th><td>{t('hk.cl.progress', { done: x.completed, total: x.items, percent: x.percent })}</td></tr>
                        ))}</tbody>
                    </table>
                )}
            </section>

            <section aria-labelledby="hk-people-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="hk-people-h">{t('hk.cl.perf.people')}</h2>
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="people">{r.people.map((p) => <li className="flex justify-between py-1" key={p.user_id}><span>{p.name ?? '—'}</span><span>{p.items}</span></li>)}</ul>
            </section>
        </HousekeepingShell>
    );
}
