import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BedDouble,
    Bell,
    BookOpen,
    CalendarDays,
    ChartNoAxesCombined,
    ChefHat,
    ChevronDown,
    ChevronRight,
    ConciergeBell,
    Hotel,
    Landmark,
    Warehouse,
    LayoutDashboard,
    LogOut,
    Menu,
    MonitorSmartphone,
    PanelLeft,
    PanelLeftClose,
    PanelTop,
    Settings2,
    ShieldCheck,
    Shirt,
    UsersRound,
    UtensilsCrossed,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

import favicon from '@/assets/brand/Favicon.svg';
import logo from '@/assets/brand/LogoHorizontal.svg';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { PoweredBy } from '@/components/layout/powered-by';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { QuickFind } from '@/components/layout/quick-find';
import { useBrand } from '@/shared/lib/brand';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { MODULE_LINKS, type NavLink } from '@/components/layout/module-links';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { LAYOUT_MODES, useLayout, type LayoutMode } from '@/shared/lib/use-layout';
import { cn } from '@/shared/lib/utils';
import type { MessageKey } from '@/locales/en/index';

export type { NavLink };

type ModuleEntry = { key: string; href: string; icon: LucideIcon; label: MessageKey; prefixes: string[]; group: 'start' | 'operations' | 'insight' | 'control' };

/** The domains of the back office, as the design asks: one sidebar, grouped by what people do. */
const MODULES: ModuleEntry[] = [
    { key: 'home', href: '/', icon: Hotel, label: 'shell.home', prefixes: [], group: 'start' },
    { key: 'front-office', href: '/front-office/room-board', icon: ConciergeBell, label: 'fo.nav.label', prefixes: ['/front-office'], group: 'operations' },
    { key: 'housekeeping', href: '/housekeeping', icon: BedDouble, label: 'hk.nav.label', prefixes: ['/housekeeping'], group: 'operations' },
    { key: 'laundry', href: '/laundry', icon: Shirt, label: 'ldy.nav.label', prefixes: ['/laundry'], group: 'operations' },
    { key: 'fnb', href: '/fnb/pos', icon: UtensilsCrossed, label: 'fnb.nav.label', prefixes: ['/fnb'], group: 'operations' },
    { key: 'kitchen', href: '/kitchen', icon: ChefHat, label: 'kitchen.nav.label', prefixes: ['/kitchen'], group: 'operations' },
    { key: 'maintenance', href: '/maintenance', icon: Wrench, label: 'mtc.nav.label', prefixes: ['/maintenance'], group: 'operations' },
    { key: 'hr', href: '/hr/employees', icon: UsersRound, label: 'hr.nav.label', prefixes: ['/hr'], group: 'operations' },
    { key: 'inventory', href: '/inventory/stock', icon: Warehouse, label: 'inv.nav.label', prefixes: ['/inventory'], group: 'operations' },
    { key: 'finance', href: '/finance/payables', icon: Landmark, label: 'fin.nav.label', prefixes: ['/finance'], group: 'operations' },
    { key: 'dashboard', href: '/dashboard', icon: LayoutDashboard, label: 'rpt.nav.dashboard', prefixes: ['/dashboard'], group: 'insight' },
    { key: 'reports', href: '/reports', icon: ChartNoAxesCombined, label: 'rpt.nav.reports', prefixes: ['/reports'], group: 'insight' },
    { key: 'approvals', href: '/approvals', icon: ShieldCheck, label: 'identity.approvals.title', prefixes: ['/approvals'], group: 'control' },
    { key: 'property', href: '/property/settings', icon: Settings2, label: 'property.nav.label', prefixes: ['/property', '/sync', '/access', '/setup'], group: 'control' },
];

const GROUPS = ['start', 'operations', 'insight', 'control'] as const;

function activeModule(path: string): ModuleEntry {
    return MODULES.find((m) => m.prefixes.some((p) => path === p || path.startsWith(`${p}/`))) ?? MODULES[0]!;
}

/** The page of the module the person is on: the link with the longest address that matches, so `/kitchen` is not lit while `/kitchen/waste` is open. */
function isHere(links: readonly NavLink[], href: string, path: string): boolean {
    const match = (h: string) => path === h || path.startsWith(`${h}/`);
    const best = links.filter((l) => match(l.href)).sort((a, b) => b.href.length - a.href.length)[0];

    return match(href) && best?.href === href;
}

type Shell = { propertyName: string | null; businessDate: string | null; userName: string; disabledModules?: string[]; accessibleModules?: string[] | null; attention?: { key: 'approvals' | 'attendance'; count: number }[] } | null;

type FrameProps = {
    title: string;
    description?: string;
    /** The pages of the active domain, shown under it in the sidebar. */
    links?: readonly NavLink[];
    actions?: ReactNode;
    children: ReactNode;
    /** Pages that are mostly tables take the whole width; forms stay readable. */
    wide?: boolean;
    /** A class for the page when it is printed, for a page that is a receipt (`receipt-page` prints on 80 mm paper). */
    printClass?: string;
    /** False for a page that prints something other than itself (the tickets of the kitchen): the title and the description stay out of the printout. */
    printHead?: boolean;
};

function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    return ((parts[0]?.[0] ?? '?') + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '')).toUpperCase();
}

/** Persistent sidebar, a header with the property, the business date and the person, and the page itself (docs/DESIGN/03-LAYOUT-NAVIGATION.md). */
export function AppFrame({ actions, children, description, links = [], printClass, printHead = true, title, wide = true }: FrameProps) {
    const { t } = useTranslation();
    const format = useFormatters();
    const page = usePage();
    const path = new URL(page.url, 'http://x').pathname;
    const shell = (page.props as { shell?: Shell }).shell ?? null;
    const version = (page.props as { app?: { version?: string } }).app?.version ?? '';
    const current = activeModule(path);
    // A department the property does not use is left out of the menu (never the one the person is on, so a link still shows where they are).
    // The menu offers a person the departments their roles give them something to do in; the page the person is on always stays. This is what is shown, not what is allowed: every page checks the permission on the server.
    const canFind = shell !== null && (shell.accessibleModules == null || shell.accessibleModules.includes('front-office')) && !(shell.disabledModules ?? []).includes('front-office');
    const attention = shell?.attention ?? [];
    const attentionTotal = attention.reduce((sum, a) => sum + a.count, 0);
    const ATTENTION_HREF = { approvals: '/approvals', attendance: '/hr/attendance' } as const;
    const bell = attentionTotal > 0 ? (
        <Popover>
            <PopoverTrigger asChild>
                <button aria-label={t('attention.title')} className="relative inline-flex h-9 items-center gap-1.5 border border-border bg-surface px-2.5 text-sm font-medium hover:bg-surface-muted" title={t('attention.title')} type="button">
                    <Bell aria-hidden="true" className="size-4" />
                    <span className="bg-accent px-1.5 text-xs font-semibold text-accent-foreground" data-testid="waiting-approvals">{attentionTotal > 98 ? '99+' : attentionTotal}</span>
                </button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-72 p-0">
                <p className="border-b border-border px-4 py-3 text-sm font-semibold">{t('attention.title')}</p>
                <ul className="divide-y divide-border">
                    {attention.map((a) => (
                        <li key={a.key}><Link className="block px-4 py-3 text-sm hover:bg-surface-muted" data-testid={`attention-${a.key}`} href={ATTENTION_HREF[a.key]}>{t(`attention.${a.key}` as MessageKey, { count: a.count })}</Link></li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    ) : null;
    const modules = MODULES.filter((m) => m.key === current.key || (!(shell?.disabledModules ?? []).includes(m.key) && (shell?.accessibleModules == null || shell.accessibleModules.includes(m.key))));
    const [open, setOpen] = useState(false);
    // On a phone the menu is a list of modules: touching one opens its pages and does not leave the page. The module the person is in starts open.
    const [expanded, setExpanded] = useState<ReadonlySet<string>>(() => new Set([current.key]));
    const toggle = (key: string) => setExpanded((now) => {
        const next = new Set(now);

        if (!next.delete(key)) next.add(key);

        return next;
    });
    const [layout, setLayout] = useLayout();
    const hasLinks = links.length > 0;

    const menu = (
        <nav aria-label={t('shell.menu')} className="flex flex-col gap-5 px-3 py-4">
            {GROUPS.map((group) => (
                <div className="flex flex-col gap-0.5" key={group}>
                    {group !== 'start' ? <p className="px-3 pb-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">{t(`shell.group.${group}`as MessageKey)}</p> : null}
                    {modules.filter((m) => m.group === group).map((m) => {
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
                                            const here = isHere(links, l.href, path);

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

    // The menu of a phone (and of the tablet): every module is a row that opens the list of its pages. A page is the only thing that takes the person away and closes the menu.
    const drawerMenu = (
        <nav aria-label={t('shell.menu')} className="flex flex-col gap-5 px-3 py-4">
            {GROUPS.map((group) => (
                <div className="flex flex-col gap-0.5" key={group}>
                    {group !== 'start' ? <p className="px-3 pb-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">{t(`shell.group.${group}` as MessageKey)}</p> : null}
                    {modules.filter((m) => m.group === group).map((m) => {
                        const Icon = m.icon;
                        const isActive = m.key === current.key;
                        const pages = isActive && hasLinks ? links : (MODULE_LINKS[m.key] ?? []);
                        const row = cn(
                            'group flex min-h-11 w-full items-center gap-3 border-l-2 px-3 py-2 text-left text-sm transition-colors',
                            isActive ? 'border-brand bg-surface-muted font-semibold text-foreground' : 'border-transparent text-muted-foreground hover:bg-surface-muted hover:text-foreground',
                        );
                        const icon = <Icon aria-hidden="true" className={cn('size-[1.125rem] shrink-0', isActive ? 'text-brand' : 'text-muted-foreground group-hover:text-foreground')} strokeWidth={1.75} />;

                        if (pages.length === 0) {
                            return (
                                <Link aria-current={isActive ? 'page' : undefined} className={row} href={m.href} key={m.key} onClick={() => setOpen(false)}>
                                    {icon}
                                    <span className="flex-1 truncate">{t(m.label)}</span>
                                </Link>
                            );
                        }

                        const isOpen = expanded.has(m.key);

                        return (
                            <Collapsible key={m.key} onOpenChange={() => toggle(m.key)} open={isOpen}>
                                <CollapsibleTrigger asChild>
                                    <button className={row} data-testid={`menu-${m.key}`} type="button">
                                        {icon}
                                        <span className="flex-1 truncate">{t(m.label)}</span>
                                        <ChevronRight aria-hidden="true" className={cn('size-4 shrink-0 text-muted-foreground transition-transform', isOpen && 'rotate-90')} />
                                    </button>
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <ul className="ml-[1.4rem] flex flex-col border-l border-border py-1">
                                        {pages.map((l) => {
                                            const here = isActive && isHere(pages, l.href, path);

                                            return (
                                                <li key={l.href}>
                                                    <Link
                                                        aria-current={here ? 'page' : undefined}
                                                        className={cn('-ml-px flex min-h-10 items-center border-l-2 px-4 py-2 text-[0.8125rem] transition-colors', here ? 'border-brand font-semibold text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}
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

    // A property that uploaded its logo shows it; the InnSYnc credit is the "Powered by" line at the bottom of the page.
    const own = useBrand().logoUrl;
    const brand = (
        <Link className="flex h-14 items-center gap-3 px-6" href="/">
            {own !== null ? <img alt={shell?.propertyName ?? ''} className="max-h-10 max-w-[10rem] w-auto object-contain" src={own} /> : <img alt="InnSYnc" className="h-8 w-auto" height={32} src={logo} width={116} />}
        </Link>
    );

    const crumbs = (
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
    );

    const dateChip = shell?.businessDate ? (
        <span className="hidden items-center gap-2 whitespace-nowrap text-sm text-muted-foreground md:inline-flex" title={t('shell.businessDate')}>
            <CalendarDays aria-hidden="true" className="size-4 text-brand" strokeWidth={1.75} />
            <span className="sr-only">{t('shell.businessDate')}: </span>
            {format.date(shell.businessDate, 'long')}
        </span>
    ) : null;

    const userMenu = shell !== null ? (
        <DropdownMenu>
            <DropdownMenuTrigger className="flex items-center gap-2 p-1 hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" type="button">
                <Avatar className="size-8"><AvatarFallback>{initials(shell.userName)}</AvatarFallback></Avatar>
                <span className={cn('hidden max-w-[10rem] truncate text-sm font-medium', layout === 'topbar' ? '2xl:block' : 'sm:block')}>{shell.userName}</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-60">
                <DropdownMenuLabel className="truncate">{shell.userName}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href="/help"><BookOpen aria-hidden="true" />{t('shell.help')}</Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href="/account/sessions"><MonitorSmartphone aria-hidden="true" />{t('shell.sessions')}</Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.post('/logout')}>
                    <LogOut aria-hidden="true" />{t('common.action.signOut')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    ) : null;

    const layoutIcons: Record<LayoutMode, LucideIcon> = { rail: PanelLeftClose, sidebar: PanelLeft, topbar: PanelTop };
    const LayoutIcon = layoutIcons[layout];

    const layoutSwitcher = (
        <DropdownMenu>
            <DropdownMenuTrigger aria-label={t('shell.layout')} className="grid size-10 place-items-center border border-input bg-surface text-muted-foreground hover:bg-surface-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" title={t('shell.layout')} type="button">
                <LayoutIcon aria-hidden="true" className="size-[1.125rem]" strokeWidth={1.75} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuLabel>{t('shell.layout')}</DropdownMenuLabel>
                <DropdownMenuRadioGroup onValueChange={(v) => setLayout(v as LayoutMode)} value={layout}>
                    {LAYOUT_MODES.map((mode) => {
                        const Icon = layoutIcons[mode];

                        return <DropdownMenuRadioItem key={mode} value={mode}><Icon aria-hidden="true" className="size-4" strokeWidth={1.75} />{t(`shell.layout.${mode}` as MessageKey)}</DropdownMenuRadioItem>;
                    })}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );

    const menuButton = (
        <button aria-label={t('shell.openMenu')} className="p-2 text-foreground hover:bg-surface-muted lg:hidden" onClick={() => setOpen(true)} type="button">
            <Menu aria-hidden="true" className="size-5" />
        </button>
    );

    const pageHead = (
        <div className={cn('flex flex-wrap items-start justify-between gap-4', !printHead && 'print:hidden')}>
            <div className="min-w-0">
                <h1 className="text-2xl font-semibold tracking-tight text-foreground">{title}</h1>
                {description ? <p className="mt-1.5 max-w-3xl text-sm leading-6 text-muted-foreground">{description}</p> : null}
            </div>
            {actions ? <div className="flex flex-wrap items-center gap-2 print:hidden">{actions}</div> : null}
        </div>
    );

    const body = (
        <main className={cn('mx-auto flex w-full flex-1 flex-col gap-8 px-4 py-8 lg:px-8', wide ? 'max-w-[90rem]' : 'max-w-5xl', printClass)} id="content" tabIndex={-1}>
            {pageHead}
            <div className="flex flex-col gap-8">{children}</div>
        </main>
    );

    const here = (href: string) => isHere(links, href, path);

    // Icon rail: modules as icons with a short label, the pages of the active module in a panel beside it.
    const rail = (
        <>
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-20 flex-col items-center border-r border-border bg-sidebar lg:flex print:hidden">
                <Link aria-label="InnSYnc" className="grid h-14 w-full place-items-center border-b border-border" href="/">
                    <img alt="" className="size-8" height={32} src={favicon} width={32} />
                </Link>
                <ScrollArea className="w-full flex-1">
                    <nav aria-label={t('shell.menu')} className="flex flex-col gap-1 py-3">
                        {modules.map((m) => {
                            const Icon = m.icon;
                            const isActive = m.key === current.key;

                            return (
                                <Link
                                    aria-current={isActive && !hasLinks ? 'page' : undefined}
                                    aria-label={t(m.label)}
                                    className={cn('flex flex-col items-center gap-1 border-l-2 px-1 py-2 text-[0.625rem] leading-tight tracking-tight transition-colors', isActive ? 'border-brand bg-surface-muted font-medium text-foreground' : 'border-transparent text-muted-foreground hover:bg-surface-muted hover:text-foreground')}
                                    href={m.href}
                                    key={m.key}
                                    title={t(m.label)}
                                >
                                    <Icon aria-hidden="true" className={cn('size-5', isActive ? 'text-brand' : '')} strokeWidth={1.75} />
                                    <span className="line-clamp-2 w-full break-words text-center">{t(m.label)}</span>
                                </Link>
                            );
                        })}
                    </nav>
                </ScrollArea>
            </aside>
            {hasLinks ? (
                <aside aria-label={t(current.label)} className="fixed inset-y-0 left-20 z-20 hidden w-56 flex-col border-r border-border bg-surface lg:flex print:hidden">
                    <p className="flex h-14 items-center border-b border-border px-5 text-sm font-semibold">{t(current.label)}</p>
                    <ScrollArea className="flex-1">
                        <ul className="flex flex-col gap-0.5 p-3">
                            {links.map((l) => (
                                <li key={l.href}>
                                    <Link
                                        aria-current={here(l.href) ? 'page' : undefined}
                                        className={cn('block border-l-2 px-3 py-2 text-sm transition-colors', here(l.href) ? 'border-brand bg-surface-muted font-semibold text-foreground' : 'border-transparent text-muted-foreground hover:bg-surface-muted hover:text-foreground')}
                                        href={l.href}
                                    >
                                        {t(l.label as MessageKey)}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </ScrollArea>
                </aside>
            ) : null}
        </>
    );

    // Top bar: modules across the top, the pages of the active module as tabs under them, the whole width for the page.
    const topbar = (
        <header className="sticky top-0 z-30 border-b border-border bg-surface print:hidden">
            <div className="flex h-14 items-center gap-3 px-4 lg:px-6">
                {menuButton}
                <Link className="flex shrink-0 items-center" href="/">{own !== null ? <img alt={shell?.propertyName ?? ''} className="max-h-8 max-w-[9rem] w-auto object-contain" src={own} /> : <img alt="InnSYnc" className="h-8 w-auto" height={32} src={logo} width={116} />}</Link>
                <nav aria-label={t('shell.menu')} className="ml-4 hidden min-w-0 flex-1 items-stretch gap-0.5 self-stretch overflow-x-auto no-scrollbar lg:flex">
                    {modules.filter((m) => m.key !== 'home').map((m) => {
                        const Icon = m.icon;
                        const isActive = m.key === current.key;

                        return (
                            <Link
                                aria-current={isActive && !hasLinks ? 'page' : undefined}
                                className={cn('flex items-center gap-2 whitespace-nowrap border-b-2 px-3 text-sm transition-colors', isActive ? 'border-brand font-semibold text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}
                                href={m.href}
                                key={m.key}
                                title={t(m.label)}
                            >
                                <Icon aria-hidden="true" className={cn('size-[1.125rem]', isActive ? 'text-brand' : '')} strokeWidth={1.75} />
                                <span className="hidden xl:inline">{t(m.label)}</span>
                                <span className="sr-only xl:hidden">{t(m.label)}</span>
                            </Link>
                        );
                    })}
                </nav>
                <div className="ml-auto flex items-center gap-2 lg:ml-0">
                    {canFind ? <QuickFind /> : null}
                    {bell}
                    <LanguageSwitcher />
                    {layoutSwitcher}
                    {userMenu}
                </div>
            </div>
            <div className="hidden h-10 items-center gap-4 border-t border-border bg-surface-muted px-6 lg:flex">
                {hasLinks ? (
                    <ul className="flex min-w-0 flex-1 items-stretch gap-1 self-stretch overflow-x-auto no-scrollbar">
                        {links.map((l) => (
                            <li className="flex" key={l.href}>
                                <Link
                                    aria-current={here(l.href) ? 'page' : undefined}
                                    className={cn('flex items-center whitespace-nowrap border-b-2 px-3 text-[0.8125rem] transition-colors', here(l.href) ? 'border-brand font-semibold text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}
                                    href={l.href}
                                >
                                    {t(l.label as MessageKey)}
                                </Link>
                            </li>
                        ))}
                    </ul>
                ) : <span className="flex-1" />}
                {hasLinks ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger className="flex items-center gap-1 whitespace-nowrap px-2 py-1 text-[0.8125rem] text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" type="button">
                            {t('shell.allPages')}<ChevronDown aria-hidden="true" className="size-3.5" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-60">
                            {links.map((l) => (
                                <DropdownMenuItem asChild key={l.href}><Link href={l.href}>{t(l.label as MessageKey)}</Link></DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
                {shell?.propertyName ? <span className="max-w-[14rem] truncate text-sm font-medium">{shell.propertyName}</span> : null}
                {dateChip}
            </div>
        </header>
    );

    return (
        <>
            <Head title={title} />
            <a className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:border focus:border-border focus:bg-surface focus:px-3 focus:py-2" href="#content">
                {t('shell.skip')}
            </a>

            <div className="flex min-h-screen">
                {layout === 'sidebar' ? (
                    <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-border bg-sidebar lg:flex print:hidden">
                        {brand}
                        <Separator />
                        <ScrollArea className="flex-1">{menu}</ScrollArea>
                    </aside>
                ) : null}
                {layout === 'rail' ? rail : null}

                <Sheet onOpenChange={setOpen} open={open}>
                    <SheetContent className="flex w-72 flex-col gap-0 p-0" side="left">
                        <SheetTitle className="sr-only">{t('shell.menu')}</SheetTitle>
                        <SheetDescription className="sr-only">{t('shell.menu')}</SheetDescription>
                        {brand}
                        <Separator />
                        <ScrollArea className="flex-1">{drawerMenu}</ScrollArea>
                    </SheetContent>
                </Sheet>

                <div className={cn('flex min-w-0 flex-1 flex-col bg-surface-muted', layout === 'sidebar' && 'lg:pl-64', layout === 'rail' && (hasLinks ? 'lg:pl-[19rem]' : 'lg:pl-20'))}>
                    {layout === 'topbar' ? topbar : (
                        <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-border bg-surface px-4 lg:px-8 print:hidden">
                            {menuButton}
                            {own !== null && layout === 'rail' ? <Link className="hidden shrink-0 xl:block" href="/"><img alt={shell?.propertyName ?? ''} className="max-h-8 max-w-[7rem] w-auto object-contain" src={own} /></Link> : null}
                            {crumbs}
                            {dateChip}
                            {canFind ? <QuickFind /> : null}
                            {bell}
                            <LanguageSwitcher />
                            {layoutSwitcher}
                            {userMenu}
                        </header>
                    )}
                    {layout === 'topbar' ? (
                        <main className={cn('mx-auto flex w-full flex-1 flex-col gap-8 px-4 py-8 lg:px-8', wide ? 'max-w-[100rem]' : 'max-w-5xl', printClass)} id="content" tabIndex={-1}>
                            {pageHead}
                            <div className="flex flex-col gap-8">{children}</div>
                        </main>
                    ) : body}
                    <footer className="px-4 pb-6 text-xs text-muted-foreground lg:px-8 print:hidden" data-testid="app-version">
                        {version !== '' ? t('shell.version', { version }) : null}{version !== '' ? ' · ' : null}<Link className="underline underline-offset-2" href="/help">{t('shell.help')}</Link>
                        <PoweredBy className="ml-3" />
                    </footer>
                </div>
            </div>
        </>
    );
}
