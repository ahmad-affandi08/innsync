import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useTranslation } from '@/shared/i18n/i18n';

type Props = {
    report: {
        from: string; to: string; runs: { run_id: string; template: string; frequency: string; period_key: string; items: number; completed: number; percent: number }[];
        people: { user_id: string; name: string | null; items: number }[];
    };
};

/** How much of each checklist was done and by whom (FR-FO-033). */
export default function ChecklistPerformancePage({ report: r }: Props) {
    const { t } = useTranslation();
    const [range, setRange] = useState({ from: r.from, to: r.to });

    return (
        <FrontOfficeShell description={t('fo.sop.perf.description')} title={t('fo.sop.perf.title')} wide>
            <div><Button asChild size="sm" variant="outline"><Link href="/front-office/checklists">{t('fo.sop.nav')}</Link></Button></div>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); router.get('/front-office/checklists/performance', range); }}>
                <FormField label={t('fo.sop.perf.from')}><Input onChange={(e) => setRange({ ...range, from: e.target.value })} required type="date" value={range.from} /></FormField>
                <FormField label={t('fo.sop.perf.to')}><Input onChange={(e) => setRange({ ...range, to: e.target.value })} required type="date" value={range.to} /></FormField>
                <Button size="sm" type="submit" variant="outline">{t('fo.sop.perf.apply')}</Button>
            </form>

            <section aria-labelledby="runs-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="runs-h">{t('fo.sop.perf.runs')}</h2>
                {r.runs.length === 0 ? <EmptyState title={t('fo.sop.perf.noRuns')} /> : (
                    <table className="w-full text-left text-sm" data-testid="runs">
                        <tbody>{r.runs.map((x) => (
                            <tr className="border-t border-border" key={x.run_id}><th className="py-1 font-medium" scope="row">{t('fo.sop.perf.run', { name: x.template, period: x.period_key })}</th><td>{t('fo.sop.perf.items', { count: x.items })}</td><td>{t('fo.sop.progress', { done: x.completed, total: x.items, percent: x.percent })}</td></tr>
                        ))}</tbody>
                    </table>
                )}
            </section>

            <section aria-labelledby="people-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="people-h">{t('fo.sop.perf.people')}</h2>
                <ul className="divide-y divide-border border-y border-border text-sm" data-testid="people">{r.people.map((p) => <li className="flex justify-between py-1" key={p.user_id}><span>{p.name ?? '—'}</span><span>{p.items}</span></li>)}</ul>
            </section>
        </FrontOfficeShell>
    );
}
