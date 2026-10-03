import type { ReactNode } from 'react';

import { Illustration, type IllustrationName } from '@/components/ui/illustration';
import { cn } from '@/shared/lib/utils';

type EmptyStateProps = {
    title: string;
    description?: string;
    /** The next useful step (create, clear filters); omit when the user cannot act. */
    action?: ReactNode;
    /** The picture above the text; `null` for none. Defaults to the empty folder. */
    illustration?: IllustrationName | null;
    className?: string;
};

/**
 * Use for "no data yet" and, with a clear-filters action, for "filtered-empty".
 * Both are distinct from loading and from error.
 */
function EmptyState({ action, className, description, illustration = 'empty', title }: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center gap-2 border border-dashed border-border bg-surface px-4 py-10 text-center',
                className,
            )}
        >
            {illustration === null ? null : <Illustration className="w-24" name={illustration} />}
            <p className="text-base font-medium text-foreground">{title}</p>
            {description ? (
                <p className="max-w-prose text-sm text-muted-foreground">
                    {description}
                </p>
            ) : null}
            {action ? <div className="mt-2 flex gap-2">{action}</div> : null}
        </div>
    );
}

export { EmptyState };
