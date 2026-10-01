import type { HTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

/** Decorative placeholder; the owning region carries `aria-busy` and a status label. */
function Skeleton({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            aria-hidden="true"
            className={cn(
                'animate-pulse rounded-md bg-surface-muted motion-reduce:animate-none',
                className,
            )}
            {...props}
        />
    );
}

export { Skeleton };
