import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { HousekeepingShell } from '@/modules/housekeeping/components/housekeeping-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Target = { ref: string; label: string; completed: number; total: number; percent: number };
type Checklist = { template_id: string; name: string; frequency: string; scope: string; period_key: string; period_start: string; period_end: string; targets: Target[] };
type Item = { id: string; text: string; photo_required: boolean; done: boolean; note: string | null; by: string | null; at: string | null; completion_id: string | null; has_photo: boolean };
type Detail = { template_id: string; target: string; label: string; items: Item[]; completed: number; total: number; percent: number };
type Props = { board: { business_date: string; checklists: Checklist[]; may_perform: boolean } };

/** Housekeeping routines for the current period, per room and per public area (FR-HK-005). */
export default function ChecklistsPage({ board }: Props) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [open, setOpen] = useState<Detail | null>(null);
    const [photos, setPhotos] = useState<Record<string, File | null>>({});

    async function show(list: Checklist, target: Target) {
        const done = await action.run<{ checklist: Detail }>(`/housekeeping/checklists/${list.template_id}/detail?target=${encodeURIComponent(target.ref)}`, { method: 'GET' });
        if (done !== null) setOpen(done.checklist);
    }

    async function tick(item: Item) {
        if (open === null) return;
        const body = new FormData();
        body.set('target', open.target);
        body.set('item_id', item.id);
        const photo = photos[item.id];
        if (photo !== undefined && photo !== null) body.set('photo', photo);
        const done = await action.run<{ checklist: Detail }>(`/housekeeping/checklists/${open.template_id}/complete`, { body, reload: ['board'] });
        if (done !== null) { setOpen(done.checklist); setPhotos({ ...photos, [item.id]: null }); }
    }

    return (
        <HousekeepingShell description={t('hk.cl.description')} title={t('hk.cl.title')} wide>
            <div className="flex flex-wrap gap-2">
                <Button asChild size="sm" variant="outline"><Link href="/housekeeping/checklists/templates">{t('hk.cl.manage')}</Link></Button>
                <Button asChild size="sm" variant="outline"><Link href="/housekeeping/checklists/performance">{t('hk.cl.performance')}</Link></Button>
            </div>
            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            {board.checklists.length === 0 ? <EmptyState title={t('hk.cl.empty')} /> : board.checklists.map((c) => (
                <section aria-label={c.name} className="flex flex-col gap-2 border border-border p-4" data-testid={`checklist-${c.name}`} key={c.template_id}>
                    <h2 className="text-lg font-semibold">{t('hk.cl.period', { name: c.name, frequency: t(`hk.cl.frequency.${c.frequency}` as 'hk.cl.frequency.daily'), from: format.date(c.period_start), to: format.date(c.period_end) })}</h2>
                    <p className="text-xs text-muted-foreground">{t(`hk.cl.scope.${c.scope}` as 'hk.cl.scope.room')}</p>
                    <ul className="flex flex-wrap gap-2">
                        {c.targets.map((x) => (
                            <li key={x.ref}>
                                <Button aria-pressed={open?.template_id === c.template_id && open.target === x.ref} onClick={() => void show(c, x)} size="sm" type="button" variant="outline">
                                    {x.label} <StatusBadge label={`${x.percent}%`} tone={x.percent === 100 ? 'success' : x.completed > 0 ? 'info' : 'neutral'} />
                                </Button>
                            </li>
                        ))}
                    </ul>
                    {open !== null && open.template_id === c.template_id && (
                        <div className="flex flex-col gap-2 border-t border-border pt-2" data-testid="checklist-detail">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="font-medium">{open.label}</h3>
                                <StatusBadge label={t('hk.cl.progress', { done: open.completed, total: open.total, percent: open.percent })} tone={open.percent === 100 ? 'success' : 'info'} />
                            </div>
                            <ul className="divide-y divide-border text-sm">
                                {open.items.map((i) => (
                                    <li className="flex flex-wrap items-center justify-between gap-2 py-2" data-testid={`item-${i.id}`} key={i.id}>
                                        <span className={i.done ? 'text-muted-foreground line-through' : undefined}>{i.text}</span>
                                        {i.done ? <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">{t('hk.cl.tickedBy', { name: i.by ?? '—', time: i.at === null ? '' : format.instant(i.at) })}{i.note !== null ? ` · ${i.note}` : ''}{i.has_photo && i.completion_id !== null ? <a className="underline" href={`/housekeeping/checklists/photo/${i.completion_id}`} rel="noreferrer" target="_blank">{t('hk.cl.photo')}</a> : null}</span>
                                            : board.may_perform ? (
                                                <span className="flex flex-wrap items-center gap-2">
                                                    {i.photo_required ? <Input accept="image/jpeg,image/png" aria-label={`${t('hk.cl.photoFor')} ${i.text}`} capture="environment" className="max-w-56" onChange={(e) => setPhotos({ ...photos, [i.id]: e.target.files?.[0] ?? null })} type="file" /> : null}
                                                    <Button disabled={action.busy || (i.photo_required && (photos[i.id] ?? null) === null)} onClick={() => void tick(i)} size="sm" type="button" variant="outline">{t('hk.cl.tick')}</Button>
                                                </span>
                                            ) : null}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>
            ))}
        </HousekeepingShell>
    );
}
