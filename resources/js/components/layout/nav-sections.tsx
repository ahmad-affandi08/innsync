import { ChevronRight } from 'lucide-react';
import { useState, type ReactNode } from 'react';

import type { NavLink } from '@/components/layout/module-links';
import { cn } from '@/shared/lib/utils';

/** Which groups the person opened by hand. Every page draws the menu again, so this lives outside the component; the group of the page the person is on is always open. */
const opened = new Map<string, boolean>();

type Section = { group: string | undefined; items: NavLink[] };

/** The links in order, cut into runs of the same group (a link with no group is a run of its own kind). */
export function sectionsOf(links: readonly NavLink[]): Section[] {
    const out: Section[] = [];

    for (const l of links) {
        const last = out[out.length - 1];

        if (last !== undefined && last.group === l.group && l.group !== undefined) last.items.push(l);
        else out.push({ group: l.group, items: [l] });
    }

    return out;
}

type Props = {
    links: readonly NavLink[];
    /** Whether a page is the one the person is on. */
    here: (href: string) => boolean;
    label: (key: string) => string;
    /** The link itself; this component wraps it in a list item. */
    renderLink: (l: NavLink) => ReactNode;
    /** Classes of the group heading button; padding follows the layout that draws it. */
    headingClass: string;
    /** Classes of the list inside an open group. */
    listClass?: string;
};

/**
 * The pages of a module under group headings that fold: only the group of the current page is open at first, the rest show only the heading, and a click opens or closes one.
 * Pages that have no group are listed as they are. Folding hides nothing from the menu's keyboard order: a closed group is a button, an open one lists its links.
 */
export function NavSections({ links, here, label, renderLink, headingClass, listClass }: Props) {
    const [, redraw] = useState(0);

    return (
        <>
            {sectionsOf(links).map((s) => {
                if (s.group === undefined) return s.items.map((l) => <li key={l.href}>{renderLink(l)}</li>);

                const group = s.group;
                const current = s.items.some((l) => here(l.href));
                const open = current || opened.get(group) === true;

                return (
                    <li key={group}>
                        {current ? (
                            <p className={cn(headingClass, 'cursor-default')}>
                                <ChevronRight aria-hidden="true" className="size-3.5 shrink-0 rotate-90 text-muted-foreground" />
                                <span className="flex-1 truncate text-left">{label(group)}</span>
                            </p>
                        ) : (
                            <button
                                aria-expanded={open}
                                className={cn(headingClass, 'w-full hover:bg-surface-muted')}
                                onClick={() => { opened.set(group, !open); redraw((n) => n + 1); }}
                                type="button"
                            >
                                <ChevronRight aria-hidden="true" className={cn('size-3.5 shrink-0 text-muted-foreground transition-transform', open && 'rotate-90')} />
                                <span className="flex-1 truncate text-left">{label(group)}</span>
                            </button>
                        )}
                        {open ? <ul className={cn('mt-0.5 flex flex-col gap-0.5', listClass)}>{s.items.map((l) => <li key={l.href}>{renderLink(l)}</li>)}</ul> : null}
                    </li>
                );
            })}
        </>
    );
}
