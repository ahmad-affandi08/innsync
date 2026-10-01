import type { LabelHTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

type LabelProps = LabelHTMLAttributes<HTMLLabelElement>;

function Label({ className, ...props }: LabelProps) {
    return (
        <label
            className={cn('text-sm font-medium text-foreground', className)}
            {...props}
        />
    );
}

export { Label };
