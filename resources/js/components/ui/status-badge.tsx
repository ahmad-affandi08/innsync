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
            className: 'border-transparent bg-muted-foreground text-white',
        },
        success: {
            icon: CircleCheck,
            className: 'border-transparent bg-success text-white',
        },
        warning: {
            icon: TriangleAlert,
            className: 'border-transparent bg-warning text-white',
        },
        danger: {
            icon: CircleX,
            className: 'border-transparent bg-danger text-white',
        },
        info: {
            icon: Info,
            className: 'border-transparent bg-info text-white',
        },
        pending: {
            icon: Clock,
            className: 'border-info bg-surface text-info',
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
                'inline-flex items-center gap-1.5 border px-2.5 py-0.5 text-xs font-semibold',
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
