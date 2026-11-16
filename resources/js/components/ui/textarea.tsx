import type { TextareaHTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement>;

function Textarea({ className, rows = 3, ...props }: TextareaProps) {
    return (
        <textarea
            className={cn(
                'flex min-h-20 w-full border border-control bg-surface-muted shadow-[inset_0_2px_3px_rgba(16,24,40,0.14)] focus-visible:bg-surface px-3 py-2 text-sm text-foreground outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger',
                className,
            )}
            rows={rows}
            {...props}
        />
    );
}

export { Textarea };
