import type { FormEvent, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/shared/lib/utils';

type FilterBarProps = {
    /** Accessible name of the search landmark. */
    label: string;
    children: ReactNode;
    resetLabel: string;
    /** Reset is shown only while a filter is active. */
    active: boolean;
    onReset: () => void;
    className?: string;
};

/**
 * Container for a screen's filter controls. Controls are bound to the URL
 * query state (`useTableQuery`), so a filtered view is shareable and survives
 * reload. Submitting never reloads the page.
 */
function FilterBar({
    active,
    children,
    className,
    label,
    onReset,
    resetLabel,
}: FilterBarProps) {
    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
    };

    return (
        <form
            aria-label={label}
            className={cn('flex flex-wrap items-end gap-3', className)}
            onSubmit={handleSubmit}
            role="search"
        >
            {children}
            {active ? (
                <Button onClick={onReset} type="button" variant="ghost">
                    {resetLabel}
                </Button>
            ) : null}
        </form>
    );
}

export { FilterBar };
