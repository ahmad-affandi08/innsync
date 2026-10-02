import { Link } from '@inertiajs/react';
import { ArrowUpRight, BedDouble, ChartNoAxesCombined, ConciergeBell, LayoutDashboard, Settings2, ShieldCheck, Shirt, Warehouse, type LucideIcon } from 'lucide-react';

import { AppFrame } from '@/components/layout/app-frame';
import { Card } from '@/components/ui/card';
import type { MessageKey } from '@/locales/en/index';
import { TodaySummary } from '@/modules/foundation/components/today-summary';
import { useTranslation } from '@/shared/i18n/i18n';

type Module = { href: string; icon: LucideIcon; label: MessageKey; about: MessageKey };

const CARDS: readonly Module[] = [
    { href: '/dashboard', icon: LayoutDashboard, label: 'rpt.nav.dashboard', about: 'home.about.dashboard' },
    { href: '/front-office/room-board', icon: ConciergeBell, label: 'fo.nav.label', about: 'home.about.frontOffice' },
    { href: '/housekeeping', icon: BedDouble, label: 'hk.nav.label', about: 'home.about.housekeeping' },
    { href: '/laundry', icon: Shirt, label: 'ldy.nav.label', about: 'home.about.laundry' },
    { href: '/inventory/stock', icon: Warehouse, label: 'inv.nav.label', about: 'home.about.inventory' },
    { href: '/reports', icon: ChartNoAxesCombined, label: 'rpt.nav.reports', about: 'home.about.reports' },
    { href: '/approvals', icon: ShieldCheck, label: 'identity.approvals.title', about: 'home.about.approvals' },
    { href: '/property/settings', icon: Settings2, label: 'property.nav.label', about: 'home.about.property' },
];

const QUICK = [
    { href: '/front-office/reservations', label: 'home.quick.reservation' },
    { href: '/front-office/stays', label: 'home.quick.stays' },
    { href: '/front-office/night-audit', label: 'home.quick.audit' },
    { href: '/housekeeping/my-rooms', label: 'home.quick.myRooms' },
] as const;

type WelcomePageProps = { appVersion: string; userName: string; activePropertyId: string };

/** Where people land: what they can open, and the few things done every day. */
export default function WelcomePage({ appVersion, userName }: WelcomePageProps) {
    const { t } = useTranslation();

    return (
        <AppFrame description={t('home.description')} title={t('foundation.welcome.heading', { name: userName.split(' ')[0] ?? userName })}>
            <TodaySummary />

            <section aria-labelledby="quick-h" className="flex flex-col gap-3">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground" id="quick-h">{t('home.quick')}</h2>
                <div className="flex flex-wrap gap-2">
                    {QUICK.map((q) => (
                        <Link className="inline-flex items-center gap-2 border border-border bg-surface px-4 py-2 text-sm font-medium transition hover:border-brand hover:text-accent" href={q.href} key={q.href}>
                            {t(q.label)}
                            <ArrowUpRight aria-hidden="true" className="size-3.5 text-brand" />
                        </Link>
                    ))}
                </div>
            </section>

            <section aria-labelledby="mods-h" className="flex flex-col gap-3">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground" id="mods-h">{t('foundation.welcome.modules')}</h2>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {CARDS.map((c) => {
                        const Icon = c.icon;

                        return (
                            <Link className="group block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" href={c.href} key={c.href}>
                                <Card className="flex h-full gap-4 p-5 transition-colors group-hover:border-brand">
                                <span className="grid size-11 shrink-0 place-items-center bg-brand/10 text-accent transition group-hover:bg-brand group-hover:text-white">
                                    <Icon aria-hidden="true" className="size-5" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block font-semibold">{t(c.label)}</span>
                                    <span className="mt-0.5 block text-sm leading-5 text-muted-foreground">{t(c.about)}</span>
                                </span>
                                </Card>
                            </Link>
                        );
                    })}
                </div>
            </section>

            <p className="text-xs text-muted-foreground">InnSYnc {appVersion}</p>
        </AppFrame>
    );
}
