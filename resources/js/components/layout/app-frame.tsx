import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BedDouble,
    CalendarDays,
    ChartNoAxesCombined,
    ChevronRight,
    ConciergeBell,
    Hotel,
    LayoutDashboard,
    LogOut,
    Menu,
    MonitorSmartphone,
    Settings2,
    ShieldCheck,
    Shirt,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

import logo from '@/assets/brand/LogoHorizontal.svg';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';
import type { MessageKey } from '@/locales/en/index';

export type NavLink = { href: string; label: string };

type ModuleEntry = { key: string; href: string; icon: LucideIcon; label: MessageKey; prefixes: string[]; group: 'start' | 'operations' | 'insight' | 'control' };

/** The domains of the back office, as the design asks: one sidebar, grouped by what people do. */
const MODULES: ModuleEntry[] = [
    { key: 'home', href: '/', icon: Hotel, label: 'shell.home', prefixes: [], group: 'start' },
    { key: 'front-office', href: '/front-office/room-board', icon: ConciergeBell, label: 'fo.nav.label', prefixes: ['/front-office'], group: 'operations' },
    { key: 'housekeeping', href: '/housekeeping', icon: BedDouble, label: 'hk.nav.label', prefixes: ['/housekeeping'], group: 'operations' },
    { key: 'laundry', href: '/laundry', icon: Shirt, label: 'ldy.nav.label', prefixes: ['/laundry'], group: 'operations' },
    { key: 'dashboard', href: '/dashboard', icon: LayoutDashboard, label: 'rpt.nav.dashboard', prefixes: ['/dashboard'], group: 'insight' },
    { key: 'reports', href: '/reports', icon: ChartNoAxesCombined, label: 'rpt.nav.reports', prefixes: ['/reports'], group: 'insight' },
    { key: 'approvals', href: '/approvals', icon: ShieldCheck, label: 'identity.approvals.title', prefixes: ['/approvals'], group: 'control' },
    { key: 'property', href: '/property/settings', icon: Settings2, label: 'property.nav.label', prefixes: ['/property'], group: 'control' },
];

const GROUPS = ['start', 'operations', 'insight', 'control'] as const;

function activeModule(path: string): ModuleEntry {
    return MODULES.find((m) => m.prefixes.some((p) => path === p || path.startsWith(`${p}/`))) ?? MODULES[0]!;
}

type Shell = { propertyName: string | null; businessDate: string | null; userName: string } | null;

type FrameProps = {
    title: string;
    description?: string;
    /** The pages of the active domain, shown under it in the sidebar. */
    links?: readonly NavLink[];
    actions?: ReactNode;
    children: ReactNode;
    /** Pages that are mostly tables take the whole width; forms stay readable. */
    wide?: boolean;
};

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    return ((parts[0]?.[0] ?? '?') + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '')).toUpperCase();
}

/** Persistent sidebar, a header with the property, the business date and the person, and the page itself (docs/DESIGN/03-LAYOUT-NAVIGATION.md). */
export function AppFrame({ actions, children, description, links = [], title, wide = true }: FrameProps) {
    const { t } = useTranslation();
    const format = useFormatters();
    const page = usePage();
    const path = new URL(page.url, 'http://x').pathname;
    const shell = (page.props as { shell?: Shell }).shell ?? null;
    const current = activeModule(path);
    const [open, setOpen] = useState(false);

    const menu = (
        <nav aria-label={t('shell.menu')} className="flex flex-col gap-5 px-3 py-4">
            {GROUPS.map((group) => (
                <div className="flex flex-col gap-0.5" key={group}>
                    {group !== 'start' ? <p className="px-3 pb-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">{t(`shell.group.${group}`as MessageKey)}</p> : null}
                    {MODULES.filter((m) => m.group === group).map((m) => {
                        const Icon = m.icon;
                        const isActive = m.key === current.key;
                        const expandable = isActive && links.length > 0;

                        return (
                            <Collapsible key={m.key} open={expandable}>
                                <Link
                                    aria-current={isActive && !expandable ? 'page' : undefined}
                                    className={cn(
                                        'group flex items-center gap-3 border-l-2 px-3 py-2 text-sm transition-colors',
                                        isActive ? 'border-brand bg-surface-muted font-semibold text-foreground' : 'border-transparent text-muted-foreground hover:bg-surface-muted hover:text-foreground',
                                    )}
                                    href={m.href}
                                    onClick={() => setOpen(false)}
                                >
                                    <Icon aria-hidden="true" className={cn('size-[1.125rem] shrink-0', isActive ? 'text-brand' : 'text-muted-foreground group-hover:text-foreground')} strokeWidth={1.75} />
                                    <span className="flex-1 truncate">{t(m.label)}</span>
                                    {expandable ? <ChevronRight aria-hidden="true" className="size-3.5 rotate-90 text-muted-foreground" /> : null}
                                </Link>
                                <CollapsibleContent>
                                    <ul className="ml-[1.4rem] flex flex-col border-l border-border py-1">
                                        {links.map((l) => {
                                            const here = path === l.href || path.startsWith(`${l.href}/`);

                                            return (
                                                <li key={l.href}>
                                                    <Link
                                                        aria-current={here ? 'page' : undefined}
                                                        className={cn('-ml-px block border-l-2 px-4 py-1.5 text-[0.8125rem] transition-colors', here ? 'border-brand font-semibold text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}
                                                        href={l.href}
                                                        onClick={() => setOpen(false)}
                                                    >
                                                        {t(l.label as MessageKey)}
                                                    </Link>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </CollapsibleContent>
                            </Collapsible>
                        );
                    })}
                </div>
            ))}
        </nav>
    );

    const brand = (
        <Link className="flex h-14 items-center px-6" href="/">
            <img alt="InnSYnc" className="h-8 w-auto" height={32} src={logo} width={116} />
        </Link>
    );

    return (
        <>
            <Head title={title} />
            <a className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:border focus:border-border focus:bg-surface focus:px-3 focus:py-2" href="#content">
                {t('shell.skip')}
            </a>

            <div className="flex min-h-screen">
                <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-border bg-sidebar lg:flex print:hidden">
                    {brand}
                    <Separator />
                    <ScrollArea className="flex-1">{menu}</ScrollArea>
                </aside>

                <Sheet onOpenChange={setOpen} open={open}>
                    <SheetContent className="flex w-72 flex-col gap-0 p-0" side="left">
                        <SheetTitle className="sr-only">{t('shell.menu')}</SheetTitle>
                        <SheetDescription className="sr-only">{t('shell.menu')}</SheetDescription>
                        {brand}
                        <Separator />
                        <ScrollArea className="flex-1">{menu}</ScrollArea>
                    </SheetContent>
                </Sheet>

                <div className="flex min-w-0 flex-1 flex-col bg-surface-muted lg:pl-64">
                    <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-border bg-surface px-4 lg:px-8 print:hidden">
                        <button aria-label={t('shell.openMenu')} className="p-2 text-foreground hover:bg-surface-muted lg:hidden" onClick={() => setOpen(true)} type="button">
                            <Menu aria-hidden="true" className="size-5" />
                        </button>
                        <Breadcrumb className="min-w-0 flex-1">
                            <BreadcrumbList className="flex-nowrap">
                                {shell?.propertyName ? (
                                    <>
                                        <BreadcrumbItem className="hidden sm:inline-flex"><span className="max-w-[14rem] truncate font-medium text-foreground">{shell.propertyName}</span></BreadcrumbItem>
                                        <BreadcrumbSeparator className="hidden sm:inline-flex" />
                                    </>
                                ) : null}
                                <BreadcrumbItem><BreadcrumbLink asChild><Link href={current.href}>{t(current.label)}</Link></BreadcrumbLink></BreadcrumbItem>
                                {current.key !== 'home' && t(current.label) !== title ? (
                                    <>
                                        <BreadcrumbSeparator />
                                        <BreadcrumbItem className="min-w-0"><BreadcrumbPage className="truncate">{title}</BreadcrumbPage></BreadcrumbItem>
                                    </>
                                ) : null}
                            </BreadcrumbList>
                        </Breadcrumb>
                        {shell?.businessDate ? (
                            <span className="hidden items-center gap-2 text-sm text-muted-foreground md:inline-flex" title={t('shell.businessDate')}>
                                <CalendarDays aria-hidden="true" className="size-4 text-brand" strokeWidth={1.75} />
                                <span className="sr-only">{t('shell.businessDate')}: </span>
                                {format.date(shell.businessDate, 'long')}
                            </span>
                        ) : null}
                        <LanguageSwitcher />
                        {shell !== null ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger className="flex items-center gap-2 p-1 hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" type="button">
                                    <Avatar className="size-8"><AvatarFallback>{initials(shell.userName)}</AvatarFallback></Avatar>
                                    <span className="hidden max-w-[10rem] truncate text-sm font-medium sm:block">{shell.userName}</span>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-56">
                                    <DropdownMenuLabel className="truncate">{shell.userName}</DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem asChild>
                                        <Link href="/account/sessions"><MonitorSmartphone aria-hidden="true" />{t('shell.sessions')}</Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem onSelect={() => router.post('/logout')}>
                                        <LogOut aria-hidden="true" />{t('common.action.signOut')}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : null}
                    </header>

                    <main className={cn('mx-auto flex w-full flex-1 flex-col gap-8 px-4 py-8 lg:px-8', wide ? 'max-w-[90rem]' : 'max-w-5xl')} id="content" tabIndex={-1}>
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="min-w-0">
                                <h1 className="text-2xl font-semibold tracking-tight text-foreground">{title}</h1>
                                {description ? <p className="mt-1.5 max-w-3xl text-sm leading-6 text-muted-foreground">{description}</p> : null}
                            </div>
                            {actions ? <div className="flex flex-wrap items-center gap-2 print:hidden">{actions}</div> : null}
                        </div>
                        <div className="flex flex-col gap-8">{children}</div>
                    </main>
                </div>
            </div>
        </>
    );
}
