import type { TextareaHTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement>;

function Textarea({ className, rows = 3, ...props }: TextareaProps) {
    return (
        <textarea
            className={cn(
                'flex min-h-20 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground shadow-sm outline-none transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger',
                className,
            )}
            rows={rows}
            {...props}
        />
    );
}

export { Textarea };
