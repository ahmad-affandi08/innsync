import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BedDouble,
    BellRing,
    CalendarDays,
    ChartNoAxesCombined,
    ChevronDown,
    ConciergeBell,
    Hotel,
    LayoutDashboard,
    LogOut,
    Menu,
    Settings2,
    ShieldCheck,
    Shirt,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

import { LanguageSwitcher } from '@/components/ui/language-switcher';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';
import favicon from '@/assets/brand/Favicon.svg';

export type NavLink = { href: string; label: string };

type ModuleEntry = { key: string; href: string; icon: LucideIcon; label: string; prefixes: string[] };

/** The domains of the back office, as the design asks: one sidebar, grouped by what people do. */
const MODULES: ModuleEntry[] = [
    { key: 'home', href: '/', icon: Hotel, label: 'shell.home', prefixes: [] },
    { key: 'dashboard', href: '/dashboard', icon: LayoutDashboard, label: 'rpt.nav.dashboard', prefixes: ['/dashboard'] },
    { key: 'front-office', href: '/front-office/room-board', icon: ConciergeBell, label: 'fo.nav.label', prefixes: ['/front-office'] },
    { key: 'housekeeping', href: '/housekeeping', icon: BedDouble, label: 'hk.nav.label', prefixes: ['/housekeeping'] },
    { key: 'laundry', href: '/laundry', icon: Shirt, label: 'ldy.nav.label', prefixes: ['/laundry'] },
    { key: 'reports', href: '/reports', icon: ChartNoAxesCombined, label: 'rpt.nav.reports', prefixes: ['/reports'] },
    { key: 'approvals', href: '/approvals', icon: ShieldCheck, label: 'identity.approvals.title', prefixes: ['/approvals'] },
    { key: 'property', href: '/property/settings', icon: Settings2, label: 'property.nav.label', prefixes: ['/property'] },
];

function activeModule(path: string): string {
    if (path === '/') return 'home';

    return MODULES.find((m) => m.prefixes.some((p) => path === p || path.startsWith(`${p}/`)))?.key ?? 'home';
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

    useEffect(() => setOpen(false), [path]);

    const sidebar = (
        <nav aria-label={t('shell.menu')} className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-3 py-4">
            {MODULES.map((m) => {
                const Icon = m.icon;
                const isActive = m.key === current;

                return (
                    <div key={m.key}>
                        <Link
                            aria-current={isActive && links.length === 0 ? 'page' : undefined}
                            className={cn(
                                'group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
                                isActive ? 'bg-sidebar-active text-white' : 'text-sidebar-muted hover:bg-sidebar-active/60 hover:text-white',
                            )}
                            href={m.href}
                        >
                            <Icon aria-hidden="true" className={cn('size-[1.15rem] shrink-0', isActive ? 'text-brand' : 'text-sidebar-muted group-hover:text-white')} />
                            <span className="truncate">{t(m.label as 'shell.home')}</span>
                        </Link>
                        {isActive && links.length > 0 ? (
                            <ul className="my-1 ml-[1.35rem] flex flex-col gap-0.5 border-l border-sidebar-border pl-3">
                                {links.map((l) => {
                                    const here = path === l.href || path.startsWith(`${l.href}/`);

                                    return (
                                        <li key={l.href}>
                                            <Link
                                                aria-current={here ? 'page' : undefined}
                                                className={cn(
                                                    'block rounded-md px-2.5 py-1.5 text-[0.8125rem] transition-colors',
                                                    here ? 'bg-brand/15 font-semibold text-white' : 'text-sidebar-muted hover:text-white',
                                                )}
                                                href={l.href}
                                            >
                                                {t(l.label as 'shell.home')}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        ) : null}
                    </div>
                );
            })}
        </nav>
    );

    const brand = (
        <Link className="flex items-center gap-3 px-5 py-5" href="/">
            <img alt="" className="size-9" height={36} src={favicon} width={36} />
            <span className="text-lg font-bold tracking-tight text-white">
                Inn<span className="text-brand">SY</span>nc
            </span>
        </Link>
    );

    return (
        <>
            <Head title={title} />
            <a className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-md focus:bg-surface focus:px-3 focus:py-2 focus:shadow-overlay" href="#content">
                {t('shell.skip')}
            </a>

            <div className="flex min-h-screen">
                <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-sidebar lg:flex print:hidden">
                    {brand}
                    {sidebar}
                </aside>

                {open ? (
                    <div className="fixed inset-0 z-40 lg:hidden print:hidden">
                        <button aria-label={t('shell.closeMenu')} className="absolute inset-0 bg-overlay" onClick={() => setOpen(false)} type="button" />
                        <aside className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col bg-sidebar shadow-overlay">
                            <div className="flex items-center justify-between pr-3">
                                {brand}
                                <button aria-label={t('shell.closeMenu')} className="rounded-md p-2 text-sidebar-muted hover:text-white" onClick={() => setOpen(false)} type="button">
                                    <X aria-hidden="true" className="size-5" />
                                </button>
                            </div>
                            {sidebar}
                        </aside>
                    </div>
                ) : null}

                <div className="flex min-w-0 flex-1 flex-col lg:pl-64">
                    <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-border bg-surface/90 px-4 py-2.5 backdrop-blur lg:px-8 print:hidden">
                        <button aria-label={t('shell.openMenu')} className="rounded-md p-2 text-foreground hover:bg-surface-muted lg:hidden" onClick={() => setOpen(true)} type="button">
                            <Menu aria-hidden="true" className="size-5" />
                        </button>
                        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            {shell?.propertyName ? <span className="truncate font-semibold">{shell.propertyName}</span> : null}
                            {shell?.businessDate ? (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-1 text-xs font-medium text-muted-foreground" title={t('shell.businessDate')}>
                                    <CalendarDays aria-hidden="true" className="size-3.5 text-brand" />
                                    <span className="sr-only">{t('shell.businessDate')}: </span>
                                    {format.date(shell.businessDate, 'long')}
                                </span>
                            ) : null}
                        </div>
                        <LanguageSwitcher />
                        {shell !== null ? <UserMenu name={shell.userName} /> : null}
                    </header>

                    <main className={cn('mx-auto flex w-full flex-1 flex-col gap-6 px-4 py-6 lg:px-8 lg:py-8', wide ? 'max-w-[90rem]' : 'max-w-5xl')} id="content" tabIndex={-1}>
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                                <h1 className="text-2xl font-semibold tracking-tight text-foreground">{title}</h1>
                                {description ? <p className="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">{description}</p> : null}
                            </div>
                            {actions ? <div className="flex flex-wrap items-center gap-2 print:hidden">{actions}</div> : null}
                        </div>
                        <div className="flex flex-col gap-6 rounded-2xl border border-border bg-surface p-5 shadow-panel sm:p-7 print:border-0 print:p-0 print:shadow-none">{children}</div>
                    </main>
                </div>
            </div>
        </>
    );
}

function UserMenu({ name }: { name: string }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => { if (box.current !== null && !box.current.contains(e.target as Node)) setOpen(false); };
        const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', esc);

        return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', esc); };
    }, [open]);

    return (
        <div className="relative" ref={box}>
            <button aria-expanded={open} aria-haspopup="menu" className="flex items-center gap-2 rounded-full py-1 pl-1 pr-2 hover:bg-surface-muted" onClick={() => setOpen(!open)} type="button">
                <span aria-hidden="true" className="grid size-8 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">{initials(name)}</span>
                <span className="hidden max-w-[10rem] truncate text-sm font-medium sm:block">{name}</span>
                <ChevronDown aria-hidden="true" className="size-4 text-muted-foreground" />
            </button>
            {open ? (
                <div className="absolute right-0 mt-2 w-56 rounded-lg border border-border bg-surface p-1 shadow-overlay" role="menu">
                    <p className="truncate px-3 py-2 text-xs text-muted-foreground">{name}</p>
                    <Link className="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-surface-muted" href="/account/sessions" role="menuitem">
                        <BellRing aria-hidden="true" className="size-4 text-muted-foreground" />
                        {t('shell.sessions')}
                    </Link>
                    <button className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-surface-muted" onClick={() => router.post('/logout')} role="menuitem" type="button">
                        <LogOut aria-hidden="true" className="size-4 text-muted-foreground" />
                        {t('common.action.signOut')}
                    </button>
                </div>
            ) : null}
        </div>
    );
}
