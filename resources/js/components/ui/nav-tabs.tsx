import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/shared/lib/utils';

export type NavTab = { href: string; label: string };

type NavTabsProps = {
    label: string;
    tabs: readonly NavTab[];
    /** The path of the page being shown; the tab with the longest matching address is the active one. */
    path: string;
    allLabel: string;
    className?: string;
};

/** More tabs than this also get a list of all the pages, since the strip scrolls. */
const LIST_FROM = 8;

/** The pages of a module as tabs under the header: navy, with the active page in orange. The strip scrolls sideways when there are many. */
function NavTabs({ allLabel, className, label, path, tabs }: NavTabsProps) {
    const strip = useRef<HTMLUListElement>(null);
    const active = tabs.reduce<NavTab | null>((best, tab) => ((path === tab.href || path.startsWith(`${tab.href}/`)) && (best === null || tab.href.length > best.href.length) ? tab : best), null);

    useEffect(() => {
        strip.current?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest', inline: 'center' });
    }, [active?.href]);

    return (
        <nav aria-label={label} className={cn('flex items-stretch gap-2 border-b border-border bg-surface px-4 py-2 lg:px-8 print:hidden', className)}>
            <ul className="flex min-w-0 flex-1 items-stretch gap-1 overflow-x-auto" ref={strip}>
                {tabs.map((tab) => {
                    const isActive = active?.href === tab.href;

                    return (
                        <li className="flex" key={tab.href}>
                            <Link
                                aria-current={isActive ? 'page' : undefined}
                                className={cn(
                                    'flex min-h-9 items-center whitespace-nowrap px-4 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
                                    isActive ? 'bg-brand font-semibold text-primary' : 'bg-primary text-primary-foreground hover:bg-primary/85',
                                )}
                                href={tab.href}
                            >
                                {tab.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
            {tabs.length > LIST_FROM ? (
                <DropdownMenu>
                    <DropdownMenuTrigger className="flex shrink-0 items-center gap-1 whitespace-nowrap border border-input px-3 text-sm text-muted-foreground hover:bg-surface-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" type="button">
                        {allLabel}<ChevronDown aria-hidden="true" className="size-3.5" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="max-h-96 w-60 overflow-y-auto">
                        {tabs.map((tab) => (
                            <DropdownMenuItem asChild key={tab.href}><Link href={tab.href}>{tab.label}</Link></DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            ) : null}
        </nav>
    );
}

export { NavTabs };
