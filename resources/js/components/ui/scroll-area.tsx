import * as ScrollAreaPrimitive from '@radix-ui/react-scroll-area';
import { useLayoutEffect, useRef, type ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

/** Where each `keep` list was scrolled to, so a list that is drawn again by the next page starts where the person left it. */
const positions = new Map<string, number>();

function ScrollArea({
    children,
    className,
    keep,
    orientation = 'vertical',
    ...props
}: ComponentProps<typeof ScrollAreaPrimitive.Root> & {
    orientation?: 'vertical' | 'horizontal' | 'both';
    /** A name for a menu that every page draws again: its scroll position is kept between pages, and the page the person is on (`aria-current="page"`) is brought into view if it is hidden. */
    keep?: string;
}) {
    const viewport = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const el = viewport.current;
        if (el === null || keep === undefined) return;

        el.scrollTop = positions.get(keep) ?? 0;
        const active = el.querySelector<HTMLElement>('[aria-current="page"]');
        if (active !== null) {
            const a = active.getBoundingClientRect();
            const v = el.getBoundingClientRect();
            if (a.top < v.top) el.scrollTop -= v.top - a.top + 8;
            else if (a.bottom > v.bottom) el.scrollTop += a.bottom - v.bottom + 8;
        }
        positions.set(keep, el.scrollTop);

        const save = () => positions.set(keep, el.scrollTop);
        el.addEventListener('scroll', save, { passive: true });

        return () => el.removeEventListener('scroll', save);
    }, [keep]);

    return (
        <ScrollAreaPrimitive.Root className={cn('relative overflow-hidden', className)} data-slot="scroll-area" {...props}>
            <ScrollAreaPrimitive.Viewport className="size-full" ref={viewport}>{children}</ScrollAreaPrimitive.Viewport>
            {(orientation === 'vertical' || orientation === 'both') && <ScrollBar orientation="vertical" />}
            {(orientation === 'horizontal' || orientation === 'both') && <ScrollBar orientation="horizontal" />}
            <ScrollAreaPrimitive.Corner />
        </ScrollAreaPrimitive.Root>
    );
}

function ScrollBar({ className, orientation = 'vertical', ...props }: ComponentProps<typeof ScrollAreaPrimitive.ScrollAreaScrollbar>) {
    return (
        <ScrollAreaPrimitive.ScrollAreaScrollbar
            className={cn(
                'flex touch-none select-none p-px transition-colors',
                orientation === 'vertical' && 'h-full w-2 border-l border-l-transparent',
                orientation === 'horizontal' && 'h-2 flex-col border-t border-t-transparent',
                className,
            )}
            orientation={orientation}
            {...props}
        >
            <ScrollAreaPrimitive.ScrollAreaThumb className="relative flex-1 rounded-full bg-border transition-colors hover:bg-muted-foreground/50" />
        </ScrollAreaPrimitive.ScrollAreaScrollbar>
    );
}

export { ScrollArea, ScrollBar };
