import type { InputHTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

type InputProps = InputHTMLAttributes<HTMLInputElement>;

/** Wrap in `FormField` so the label, hint and error are programmatically associated. */
function Input({ className, ...props }: InputProps) {
    return (
        <input
            className={cn(
                'flex h-10 min-h-10 w-full border border-input bg-surface px-3 py-2 text-sm text-foreground outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger',
                className,
            )}
            {...props}
        />
    );
}

export { Input };
