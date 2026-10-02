import * as ProgressPrimitive from '@radix-ui/react-progress';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

function Progress({ className, value, ...props }: ComponentProps<typeof ProgressPrimitive.Root>) {
    return (
        <ProgressPrimitive.Root className={cn('relative h-1.5 w-full overflow-hidden bg-surface-muted', className)} data-slot="progress" value={value} {...props}>
            <ProgressPrimitive.Indicator className="size-full flex-1 bg-brand transition-all" style={{ transform: `translateX(-${100 - (value ?? 0)}%)` }} />
        </ProgressPrimitive.Root>
    );
}

export { Progress };
