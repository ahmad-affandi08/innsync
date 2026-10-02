import { cva } from 'class-variance-authority';
import { CircleX, Info, TriangleAlert, CircleCheck } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/shared/lib/utils';

const alertVariants = cva('flex gap-3 rounded-lg border p-3.5 text-sm', {
    variants: {
        tone: {
            info: 'border-info/40 bg-info/10 text-foreground',
            success: 'border-success/40 bg-success/10 text-foreground',
            warning: 'border-warning/40 bg-warning/10 text-foreground',
            danger: 'border-danger/40 bg-danger/10 text-foreground',
        },
    },
    defaultVariants: { tone: 'info' },
});

const icons = {
    info: { icon: Info, className: 'text-info' },
    success: { icon: CircleCheck, className: 'text-success' },
    warning: { icon: TriangleAlert, className: 'text-warning' },
    danger: { icon: CircleX, className: 'text-danger' },
};

type AlertProps = {
    tone: 'info' | 'success' | 'warning' | 'danger';
    title: ReactNode;
    children?: ReactNode;
    actions?: ReactNode;
    className?: string;
};

/**
 * Inline feedback. Danger/warning use `role="alert"` so changes are announced;
 * informational and success messages use a polite status region.
 */
function Alert({ actions, children, className, title, tone }: AlertProps) {
    const { className: iconClass, icon: Icon } = icons[tone];
    const assertive = tone === 'danger' || tone === 'warning';

    return (
        <div
            className={cn(alertVariants({ tone }), className)}
            role={assertive ? 'alert' : 'status'}
        >
            <Icon aria-hidden="true" className={cn('mt-0.5 size-4 shrink-0', iconClass)} />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="font-medium">{title}</p>
                {children ? (
                    <div className="text-muted-foreground">{children}</div>
                ) : null}
                {actions ? (
                    <div className="mt-1 flex flex-wrap gap-2">{actions}</div>
                ) : null}
            </div>
        </div>
    );
}

export { Alert };
