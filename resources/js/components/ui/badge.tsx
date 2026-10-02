import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center gap-1 border px-2 py-0.5 text-xs font-medium',
    {
        variants: {
            tone: {
                neutral: 'border-border bg-surface-muted text-foreground',
                success: 'border-success/40 bg-success/10 text-success',
                warning: 'border-warning/40 bg-warning/10 text-warning',
                danger: 'border-danger/40 bg-danger/10 text-danger',
                info: 'border-info/40 bg-info/10 text-info',
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
