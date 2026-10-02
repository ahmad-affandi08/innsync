import * as HoverCardPrimitive from '@radix-ui/react-hover-card';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

const HoverCard = HoverCardPrimitive.Root;
const HoverCardTrigger = HoverCardPrimitive.Trigger;

function HoverCardContent({ align = 'center', className, sideOffset = 6, ...props }: ComponentProps<typeof HoverCardPrimitive.Content>) {
    return (
        <HoverCardPrimitive.Portal>
            <HoverCardPrimitive.Content align={align} className={cn('z-50 w-64 border border-border bg-surface p-4 text-sm outline-none', className)} data-slot="hover-card-content" sideOffset={sideOffset} {...props} />
        </HoverCardPrimitive.Portal>
    );
}

export { HoverCard, HoverCardContent, HoverCardTrigger };
