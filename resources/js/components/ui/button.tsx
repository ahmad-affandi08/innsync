import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import type { ButtonHTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

const buttonVariants = cva(
    'inline-flex min-h-10 items-center justify-center gap-2 whitespace-nowrap rounded-md px-4 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:opacity-90',
                outline:
                    'border border-input bg-background text-foreground hover:bg-surface-muted',
                destructive:
                    'bg-destructive text-destructive-foreground hover:opacity-90',
            },
            size: {
                default: 'h-10',
                sm: 'h-9 px-3',
                lg: 'h-11 px-6',
                icon: 'size-10 px-0',
            },
        },
        defaultVariants: {
            variant: 'default',
            size: 'default',
        },
    },
);

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> &
    VariantProps<typeof buttonVariants> & {
        asChild?: boolean;
    };

function Button({
    asChild = false,
    className,
    size,
    variant,
    ...props
}: ButtonProps) {
    const Component = asChild ? Slot : 'button';

    return (
        <Component
            className={cn(buttonVariants({ className, size, variant }))}
            {...props}
        />
    );
}

export { Button, buttonVariants };
