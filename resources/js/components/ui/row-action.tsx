import { Link } from '@inertiajs/react';
import { LoaderCircle, type LucideIcon } from 'lucide-react';
import type { ButtonHTMLAttributes } from 'react';

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/shared/lib/utils';

/**
 * One colour per meaning, the same on every table (docs/DESIGN/04-COMPONENTS.md, "Row actions"):
 * info = look (open, detail, print, download); warning = change (edit, adjust, save an edit);
 * success = move forward (approve, confirm, activate, post, complete); danger = stop or remove
 * (delete, cancel, reject, deactivate, void); neutral = anything else.
 */
export type RowActionTone = 'info' | 'warning' | 'success' | 'danger' | 'neutral';

/** Solid colour with a darker edge underneath: raised like the navy buttons. The edge is the tone mixed with black. */
const tones: Record<RowActionTone, string> = {
    info: 'bg-info border-b-[color-mix(in_oklch,var(--info),black_35%)]',
    warning: 'bg-warning border-b-[color-mix(in_oklch,var(--warning),black_35%)]',
    success: 'bg-success border-b-[color-mix(in_oklch,var(--success),black_35%)]',
    danger: 'bg-danger border-b-[color-mix(in_oklch,var(--danger),black_35%)]',
    neutral: 'bg-muted-foreground border-b-[color-mix(in_oklch,var(--muted-foreground),black_35%)]',
};

type RowActionProps = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> & {
    icon: LucideIcon;
    /** Accessible name and the hover text; an icon alone is not a label. */
    label: string;
    tone?: RowActionTone;
    /** Renders an Inertia link instead of a button. */
    href?: string;
    /** With `href`: a plain link (a file, a download) the browser opens itself instead of Inertia. */
    native?: boolean;
    /** With `href`: a plain link opened in a new tab (a photo, a letter). */
    newTab?: boolean;
    loading?: boolean;
    'data-testid'?: string;
    /** Lets a DropdownMenuTrigger / AlertDialogTrigger `asChild` wrap it. */
    ref?: React.Ref<HTMLButtonElement>;
};

/** An icon button for a table row, coloured by what it does, named on hover and to assistive technology. */
function RowAction({ icon: Icon, label, tone = 'neutral', href, native = false, newTab = false, loading = false, disabled, className, type = 'button', ...props }: RowActionProps) {
    const cls = cn(
        // A light line on top and a 3px dark edge below make it stand up; pressing sinks it onto its edge.
        'inline-flex size-8 shrink-0 cursor-pointer items-center justify-center border-t border-b-[3px] border-t-white/35 text-white transition-[filter,transform] hover:brightness-110 active:translate-y-0.5 active:border-b focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40 aria-busy:cursor-progress',
        tones[tone],
        className,
    );
    const glyph = loading ? <LoaderCircle aria-hidden="true" className="size-4 animate-spin motion-reduce:animate-none" /> : <Icon aria-hidden="true" className="size-4" />;
    const off = disabled === true || loading;
    const control =
        href !== undefined && !off && (native || newTab) ? (
            <a aria-label={label} className={cls} data-testid={props['data-testid']} href={href} rel={newTab ? 'noreferrer' : undefined} target={newTab ? '_blank' : undefined}>
                {glyph}
            </a>
        ) : href !== undefined && !off ? (
            <Link aria-label={label} className={cls} data-testid={props['data-testid']} href={href}>
                {glyph}
            </Link>
        ) : (
            <button aria-busy={loading || undefined} aria-label={label} className={cls} disabled={off} type={type} {...props}>
                {glyph}
            </button>
        );

    return (
        <TooltipProvider delayDuration={150}>
            <Tooltip>
                {/* A disabled button gets no pointer events, so the hover lands on this wrapper and still names it. */}
                <TooltipTrigger asChild>{off ? <span className="inline-flex">{control}</span> : control}</TooltipTrigger>
                <TooltipContent>{label}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

/** Lays out a row's actions: small gap, never wrapping into a second line. */
function RowActions({ children, className }: { children: React.ReactNode; className?: string }) {
    return <div className={cn('flex flex-nowrap items-center gap-1', className)}>{children}</div>;
}

export { RowAction, RowActions };
