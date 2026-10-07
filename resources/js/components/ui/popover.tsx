import * as PopoverPrimitive from '@radix-ui/react-popover';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

/**
 * Modal by default: inside a dialog, the dialog locks the scroll of everything outside it, and a popover is drawn outside it, so a long list
 * (roles, rooms, guests) could not be scrolled by wheel or finger. A modal popover takes the scroll lock over while it is open.
 */
function Popover({ modal = true, ...props }: ComponentProps<typeof PopoverPrimitive.Root>) {
    return <PopoverPrimitive.Root modal={modal} {...props} />;
}

const PopoverTrigger = PopoverPrimitive.Trigger;
const PopoverAnchor = PopoverPrimitive.Anchor;

function PopoverContent({ align = 'center', className, sideOffset = 6, ...props }: ComponentProps<typeof PopoverPrimitive.Content>) {
    return (
        <PopoverPrimitive.Portal>
            <PopoverPrimitive.Content
                align={align}
                className={cn('z-[70] w-72 border border-border bg-surface p-4 text-foreground outline-none data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=closed]:animate-out data-[state=closed]:fade-out-0', className)}
                data-slot="popover-content"
                sideOffset={sideOffset}
                {...props}
            />
        </PopoverPrimitive.Portal>
    );
}

export { Popover, PopoverAnchor, PopoverContent, PopoverTrigger };
