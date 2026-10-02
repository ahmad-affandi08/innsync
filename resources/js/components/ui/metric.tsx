import type { ReactNode } from 'react';

import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/shared/lib/utils';

type MetricProps = {
    label: string;
    /** Server-formatted value; the UI never computes authoritative totals. */
    value: ReactNode;
    /** Secondary context such as the comparison period or a status badge. */
    detail?: ReactNode;
    loading?: boolean;
    className?: string;
};

/** Dense label/value pair; a `dl` keeps the relationship meaningful to screen readers. */
function Metric({ className, detail, label, loading = false, value }: MetricProps) {
    return (
        <dl
            aria-busy={loading || undefined}
            className={cn(
                'border border-border bg-surface p-4',
                className,
            )}
        >
            <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold tabular-nums text-foreground">
                {loading ? <Skeleton className="h-8 w-24" /> : value}
            </dd>
            {detail && !loading ? (
                <dd className="mt-1 text-xs text-muted-foreground">{detail}</dd>
            ) : null}
        </dl>
    );
}

export { Metric };
