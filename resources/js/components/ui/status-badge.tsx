import {
    Circle,
    CircleCheck,
    CircleHelp,
    CircleX,
    Clock,
    Info,
    TriangleAlert,
    type LucideIcon,
} from 'lucide-react';

import { cn } from '@/shared/lib/utils';

/**
 * `unknown` is deliberately distinct from `success` and `pending`: a payment
 * or integration outcome the provider has not confirmed must never look
 * settled (docs/DESIGN/07-STATES-FEEDBACK.md).
 */
export type StatusTone =
    | 'neutral'
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'pending'
    | 'unknown';

const toneStyles: Record<StatusTone, { icon: LucideIcon; className: string }> =
    {
        neutral: {
            icon: Circle,
            className: 'border-border bg-surface-muted text-foreground',
        },
        success: {
            icon: CircleCheck,
            className: 'border-success/40 bg-success/10 text-success',
        },
        warning: {
            icon: TriangleAlert,
            className: 'border-warning/40 bg-warning/10 text-warning',
        },
        danger: {
            icon: CircleX,
            className: 'border-danger/40 bg-danger/10 text-danger',
        },
        info: {
            icon: Info,
            className: 'border-info/40 bg-info/10 text-info',
        },
        pending: {
            icon: Clock,
            className: 'border-info/40 bg-surface text-info',
        },
        unknown: {
            icon: CircleHelp,
            className:
                'border-dashed border-warning bg-surface text-warning font-semibold',
        },
    };

type StatusBadgeProps = {
    tone: StatusTone;
    /** Required text: business meaning is never conveyed by color alone (NFR-27). */
    label: string;
    className?: string;
};

function StatusBadge({ className, label, tone }: StatusBadgeProps) {
    const { className: toneClass, icon: Icon } = toneStyles[tone];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 border px-2.5 py-0.5 text-xs font-medium',
                toneClass,
                className,
            )}
            data-tone={tone}
        >
            <Icon aria-hidden="true" className="size-3.5 shrink-0" />
            {label}
        </span>
    );
}

export { StatusBadge };
