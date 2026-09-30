import type { InputHTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

type InputProps = InputHTMLAttributes<HTMLInputElement>;

function Input({ className, ...props }: InputProps) {
    return (
        <input
            className={cn(
                'flex min-h-11 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm outline-none transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

export { Input };
