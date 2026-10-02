import * as SwitchPrimitive from '@radix-ui/react-switch';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

function Switch({ className, ...props }: ComponentProps<typeof SwitchPrimitive.Root>) {
    return (
        <SwitchPrimitive.Root
            className={cn('peer inline-flex h-6 w-11 shrink-0 items-center border border-input bg-surface-muted transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary data-[state=checked]:bg-primary', className)}
            data-slot="switch"
            {...props}
        >
            <SwitchPrimitive.Thumb className="pointer-events-none block size-4 translate-x-0.5 bg-foreground transition-transform data-[state=checked]:translate-x-[1.5rem] data-[state=checked]:bg-primary-foreground" />
        </SwitchPrimitive.Root>
    );
}

export { Switch };
