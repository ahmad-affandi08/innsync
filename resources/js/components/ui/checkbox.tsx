import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import { Check, Minus } from 'lucide-react';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

/**
 * A box with a navy frame and a thicker lower edge, like the buttons and the switch; ticked, it is filled with the brand orange so the state is seen at a glance. Part of a group that is only partly
 * chosen (`checked="indeterminate"`) shows a dash instead of the tick.
 */
function Checkbox({ className, ...props }: ComponentProps<typeof CheckboxPrimitive.Root>) {
    return (
        <CheckboxPrimitive.Root
            className={cn(
                'group peer grid size-5 shrink-0 place-items-center border-[1.5px] border-b-[3px] border-primary bg-surface text-primary-foreground transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-brand data-[state=indeterminate]:bg-brand',
                className,
            )}
            data-slot="checkbox"
            {...props}
        >
            <CheckboxPrimitive.Indicator className="flex items-center justify-center text-current">
                <Check aria-hidden="true" className="size-3.5 group-data-[state=indeterminate]:hidden" strokeWidth={3.5} />
                <Minus aria-hidden="true" className="hidden size-3.5 group-data-[state=indeterminate]:block" strokeWidth={3.5} />
            </CheckboxPrimitive.Indicator>
        </CheckboxPrimitive.Root>
    );
}

export { Checkbox };
