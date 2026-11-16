import * as SwitchPrimitive from '@radix-ui/react-switch';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

/**
 * An on/off switch that looks pressed in and raised: the track is sunken with a darker lower edge, the knob stands on its own edge, and the state is written in the track (ON or OFF),
 * so it is read without guessing from the colour. The words are decoration for the eye; the switch is named and announced by its label and its on/off state.
 */
function Switch({ className, ...props }: ComponentProps<typeof SwitchPrimitive.Root>) {
    return (
        <SwitchPrimitive.Root
            className={cn(
                'group peer relative inline-flex h-7 w-16 shrink-0 items-center border border-b-2 border-control bg-secondary shadow-[inset_0_1px_2px_rgb(16_24_40/0.18)] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary data-[state=checked]:border-b-primary-edge data-[state=checked]:bg-primary',
                className,
            )}
            data-slot="switch"
            {...props}
        >
            <span aria-hidden="true" className="pointer-events-none absolute right-2 text-[10px] font-semibold tracking-wide text-muted-foreground group-data-[state=checked]:hidden">OFF</span>
            <span aria-hidden="true" className="pointer-events-none absolute left-2 hidden text-[10px] font-semibold tracking-wide text-primary-foreground group-data-[state=checked]:block">ON</span>
            <SwitchPrimitive.Thumb className="pointer-events-none block h-5 w-6 translate-x-0.5 border border-b-2 border-control bg-surface transition-transform data-[state=checked]:translate-x-[2.25rem]" />
        </SwitchPrimitive.Root>
    );
}

export { Switch };
