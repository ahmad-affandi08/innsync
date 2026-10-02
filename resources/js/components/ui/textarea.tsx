import type { TextareaHTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement>;

function Textarea({ className, rows = 3, ...props }: TextareaProps) {
    return (
        <textarea
            className={cn(
                'flex min-h-20 w-full border border-input bg-surface px-3 py-2 text-sm text-foreground outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger',
                className,
            )}
            rows={rows}
            {...props}
        />
    );
}

export { Textarea };
