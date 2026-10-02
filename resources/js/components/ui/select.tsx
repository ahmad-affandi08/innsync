import { ChevronDown } from 'lucide-react';
import type { SelectHTMLAttributes } from 'react';

import { cn } from '@/shared/lib/utils';

type SelectProps = SelectHTMLAttributes<HTMLSelectElement>;

/**
 * Native select: keyboard, screen-reader and mobile-picker behavior come from
 * the platform. A searchable Combobox is a separate, later primitive.
 */
function Select({ children, className, ...props }: SelectProps) {
    return (
        <div className="relative">
            <select
                className={cn(
                    'flex min-h-11 w-full appearance-none rounded-lg border border-input bg-surface py-2 pl-3 pr-9 text-sm text-foreground shadow-sm outline-none transition focus-visible:border-brand focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-danger',
                    className,
                )}
                {...props}
            >
                {children}
            </select>
            <ChevronDown
                aria-hidden="true"
                className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            />
        </div>
    );
}

export { Select };
