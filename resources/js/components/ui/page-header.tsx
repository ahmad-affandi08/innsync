import type { ReactNode } from 'react';

import { cn } from '@/shared/lib/utils';

type PageHeaderProps = {
    title: string;
    description?: string;
    /** Primary and secondary actions, already filtered by server-provided permissions. */
    actions?: ReactNode;
    className?: string;
};

function PageHeader({ actions, className, description, title }: PageHeaderProps) {
    return (
        <header
            className={cn(
                'flex flex-wrap items-start justify-between gap-3 border-b border-border pb-4',
                className,
            )}
        >
            <div className="min-w-0">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>
                {description ? (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </div>
            {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
        </header>
    );
}

export { PageHeader };
