import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center gap-1 border px-2 py-0.5 text-xs font-semibold',
    {
        variants: {
            tone: {
                neutral: 'border-transparent bg-muted-foreground text-white',
                success: 'border-transparent bg-success text-white',
                warning: 'border-transparent bg-warning text-white',
                danger: 'border-transparent bg-danger text-white',
                info: 'border-transparent bg-info text-white',
            },
        },
        defaultVariants: { tone: 'neutral' },
    },
);

type BadgeProps = HTMLAttributes<HTMLSpanElement> &
    VariantProps<typeof badgeVariants>;

/** Semantic tones only; feature code must not pass ad-hoc colors. */
function Badge({ className, tone, ...props }: BadgeProps) {
    return (
        <span className={cn(badgeVariants({ tone }), className)} {...props} />
    );
}

export { Badge, badgeVariants };
