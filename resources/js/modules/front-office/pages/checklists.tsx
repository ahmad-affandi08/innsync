import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { FrontOfficeShell } from '@/modules/front-office/components/front-office-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Item = { id: string; text: string; done: boolean; note: string | null; by: string | null; at: string | null };
type Checklist = { template_id: string; name: string; frequency: string; period_key: string; period_start: string; period_end: string; items: Item[]; completed: number; total: number; percent: number };
type Props = { board: { business_date: string; checklists: Checklist[]; may_perform: boolean } };

/** The front desk routines in force for the current period (FR-FO-032, FR-FO-033). */
export default function ChecklistsPage({ board }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();

    async function tick(list: Checklist, item: Item) {
        await action.run(`/front-office/checklists/${list.template_id}/items/${item.id}/complete`, { body: {}, reload: ['board'] });
    }

    return (
        <FrontOfficeShell description={t('fo.sop.description')} title={t('fo.sop.title')} wide>
            <div className="flex flex-wrap gap-2">
                <Button asChild size="sm" variant="outline"><Link href="/front-office/checklists/templates">{t('fo.sop.manage')}</Link></Button>
                <Button asChild size="sm" variant="outline"><Link href="/front-office/checklists/performance">{t('fo.sop.performance')}</Link></Button>
            </div>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            {board.checklists.length === 0 ? <EmptyState title={t('fo.sop.empty')} /> : board.checklists.map((c) => (
                <section aria-label={c.name} className="flex flex-col gap-2 border border-border p-4" data-testid={`checklist-${c.name}`} key={c.template_id}>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-semibold">{t('fo.sop.period', { name: c.name, frequency: t(`fo.sop.frequency.${c.frequency}` as 'fo.sop.frequency.daily'), from: format.date(c.period_start), to: format.date(c.period_end) })}</h2>
                        <StatusBadge label={t('fo.sop.progress', { done: c.completed, total: c.total, percent: c.percent })} tone={c.percent === 100 ? 'success' : 'info'} />
                    </div>
                    <ul className="divide-y divide-border text-sm">
                        {c.items.map((i) => (
                            <li className="flex flex-wrap items-center justify-between gap-2 py-2" data-testid={`item-${i.id}`} key={i.id}>
                                <span className={i.done ? 'text-muted-foreground line-through' : undefined}>{i.text}</span>
                                {i.done ? <span className="text-xs text-muted-foreground">{t('fo.sop.tickedBy', { name: i.by ?? '—', time: i.at === null ? '' : format.instant(i.at) })}{i.note !== null ? ` · ${i.note}` : ''}</span>
                                    : board.may_perform ? <Button disabled={action.busy} onClick={() => void tick(c, i)} size="sm" type="button" variant="outline">{t('fo.sop.tick')}</Button> : null}
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </FrontOfficeShell>
    );
}
