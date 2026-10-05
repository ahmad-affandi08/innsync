import * as ScrollAreaPrimitive from '@radix-ui/react-scroll-area';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

function ScrollArea({
    children,
    className,
    orientation = 'vertical',
    ...props
}: ComponentProps<typeof ScrollAreaPrimitive.Root> & { orientation?: 'vertical' | 'horizontal' | 'both' }) {
    return (
        <ScrollAreaPrimitive.Root className={cn('relative overflow-hidden', className)} data-slot="scroll-area" {...props}>
            <ScrollAreaPrimitive.Viewport className="size-full">{children}</ScrollAreaPrimitive.Viewport>
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
