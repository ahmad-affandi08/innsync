import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { LoaderCircle } from 'lucide-react';
import type { ButtonHTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

const buttonVariants = cva(
    'inline-flex cursor-pointer items-center justify-center gap-2 whitespace-nowrap px-4 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 aria-busy:cursor-progress',
    {
        variants: {
            variant: {
                default: 'bg-primary text-primary-foreground hover:bg-primary/90',
                accent: 'bg-accent text-accent-foreground hover:bg-accent/90',
                secondary:
                    'bg-secondary text-secondary-foreground hover:opacity-90',
                outline:
                    'border border-input bg-surface text-foreground hover:border-brand/50 hover:bg-surface-muted',
                ghost: 'text-foreground hover:bg-surface-muted',
                destructive:
                    'bg-destructive text-destructive-foreground hover:opacity-90',
            },
            size: {
                default: 'h-10',
                sm: 'h-8 px-3',
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
        /**
         * Blocks duplicate submission while a request is in flight and exposes
         * the busy state to assistive technology. Backend idempotency remains
         * mandatory (docs/DESIGN/05-FORMS-VALIDATION.md).
         */
        loading?: boolean;
    };

function Button({
    asChild = false,
    children,
    className,
    disabled,
    loading = false,
    size,
    variant,
    ...props
}: ButtonProps) {
    if (asChild) {
        return (
            <Slot
                className={cn(buttonVariants({ className, size, variant }))}
                {...props}
            >
                {children}
            </Slot>
        );
    }

    return (
        <button
            aria-busy={loading || undefined}
            className={cn(buttonVariants({ className, size, variant }))}
            disabled={disabled || loading}
            {...props}
        >
            {loading ? (
                <LoaderCircle
                    aria-hidden="true"
                    className="size-4 animate-spin motion-reduce:animate-none"
                />
            ) : null}
            {children}
        </button>
    );
}

type IconButtonProps = Omit<ButtonProps, 'asChild' | 'size' | 'children'> & {
    /** Accessible name; an icon alone is not a label. */
    label: string;
    icon: React.ReactNode;
};

function IconButton({ icon, label, title, ...props }: IconButtonProps) {
    return (
        <Button
            aria-label={label}
            size="icon"
            title={title ?? label}
            type="button"
            {...props}
        >
            <span aria-hidden="true">{icon}</span>
        </Button>
    );
}

export { Button, IconButton, buttonVariants };
