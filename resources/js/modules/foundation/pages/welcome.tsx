import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, BedDouble, ChefHat, CircleCheck, ConciergeBell, Landmark, ShieldCheck, Shirt, UsersRound, UtensilsCrossed, Warehouse, Wrench, type LucideIcon } from 'lucide-react';

import { AppFrame } from '@/components/layout/app-frame';
import { visibleLinks, type NavLink } from '@/components/layout/module-links';
import { Card } from '@/components/ui/card';
import { Illustration } from '@/components/ui/illustration';
import type { MessageKey } from '@/locales/en/index';
import { TodaySummary } from '@/modules/foundation/components/today-summary';
import { cn } from '@/shared/lib/utils';
import { useTranslation } from '@/shared/i18n/i18n';

/** Work waiting for this person today, as the departments they work in report it (`TodayTasks`). `key` is `<module>.<what>`. */
type Task = { key: string; count: number; href: string; urgent: boolean };

const ICON: Readonly<Record<string, LucideIcon>> = {
    fo: ConciergeBell, hk: BedDouble, ldy: Shirt, fnb: UtensilsCrossed, kitchen: ChefHat, mtc: Wrench, hr: UsersRound, inv: Warehouse, fin: Landmark, approvals: ShieldCheck,
};

/** The few things done every day; a person is offered the ones they have work in (the same rule as the menu). */
const QUICK: readonly (NavLink & { label: MessageKey })[] = [
    { href: '/front-office/reservations', label: 'home.quick.reservation', needs: ['front-office.reservation'] },
    { href: '/front-office/stays', label: 'home.quick.stays', needs: ['front-office.stay'] },
    { href: '/front-office/night-audit', label: 'home.quick.audit', needs: ['front-office.night-audit'] },
    { href: '/housekeeping/my-rooms', label: 'home.quick.myRooms', needs: ['housekeeping.task'] },
    { href: '/fnb/pos', label: 'home.quick.pos', needs: ['fnb.pos'] },
    { href: '/laundry/new', label: 'home.quick.laundry', needs: ['laundry.order'] },
    { href: '/maintenance', label: 'home.quick.workOrders', needs: ['maintenance.work'] },
    { href: '/hr/me', label: 'home.quick.me', needs: ['hr.leave'] },
];

type WelcomePageProps = { appVersion: string; userName: string; activePropertyId: string; tasks: Task[] };

/** "Hari Ini": what waits for the person today, and the few things they do every day. The departments themselves are in the menu. */
export default function WelcomePage({ appVersion, tasks, userName }: WelcomePageProps) {
    const { t } = useTranslation();
    const areas = (usePage().props as { shell?: { permissionAreas?: string[] | null } }).shell?.permissionAreas;
    const quick = visibleLinks(QUICK, areas);

    return (
        <AppFrame description={t('home.description')} title={t('foundation.welcome.heading', { name: userName.split(' ')[0] ?? userName })}>
            <section aria-labelledby="tasks-h" className="flex flex-col gap-3" data-testid="today-tasks">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground" id="tasks-h">{t('today.tasks')}</h2>
                {tasks.length === 0 ? (
                    <Card className="flex items-center gap-3 p-5 text-sm text-muted-foreground">
                        <CircleCheck aria-hidden="true" className="size-5 shrink-0 text-success" />
                        {t('today.none')}
                    </Card>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {tasks.map((task) => {
                            const Icon = ICON[task.key.split('.')[0] ?? ''] ?? ShieldCheck;

                            return (
                                <Link className="group block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" data-testid={`task-${task.key}`} href={task.href} key={task.key}>
                                    <Card className={cn('flex h-full items-center gap-4 border-t-2 p-5 transition-colors group-hover:border-brand', task.urgent ? 'border-t-danger' : 'border-t-brand')}>
                                        <span className={cn('grid size-11 shrink-0 place-items-center', task.urgent ? 'bg-danger/10 text-danger' : 'bg-brand/10 text-accent')}>
                                            <Icon aria-hidden="true" className="size-5" />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-3xl font-semibold tabular-nums tracking-tight">{task.count}</span>
                                            <span className="mt-0.5 block text-sm leading-5 text-muted-foreground">{t(`today.task.${task.key}` as MessageKey, { count: task.count })}</span>
                                        </span>
                                    </Card>
                                </Link>
                            );
                        })}
                    </div>
                )}
            </section>

            <TodaySummary />

            {quick.length > 0 ? (
                <section aria-labelledby="quick-h" className="flex items-end justify-between gap-6">
                    <div className="flex flex-col gap-3">
                        <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground" id="quick-h">{t('home.quick')}</h2>
                        <div className="flex flex-wrap gap-2">
                            {quick.map((q) => (
                                <Link className="inline-flex items-center gap-2 border border-border bg-surface px-4 py-2 text-sm font-medium transition hover:border-brand hover:text-accent" href={q.href} key={q.href}>
                                    {t(q.label as MessageKey)}
                                    <ArrowUpRight aria-hidden="true" className="size-3.5 text-brand" />
                                </Link>
                            ))}
                        </div>
                    </div>
                    <Illustration className="hidden w-44 shrink-0 md:block" name="guest-service" />
                </section>
            ) : null}

            <p className="text-xs text-muted-foreground">InnSYnc {appVersion}</p>
        </AppFrame>
    );
}
