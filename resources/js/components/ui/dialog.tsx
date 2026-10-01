import { useEffect, useId, useRef, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/shared/lib/utils';

type DialogProps = {
    open: boolean;
    title: string;
    description?: string;
    children?: ReactNode;
    /** Footer actions. Closing via Escape/backdrop calls `onClose`. */
    footer?: ReactNode;
    onClose: () => void;
    /** Alert dialogs interrupt for a consequential choice and ignore backdrop clicks. */
    role?: 'dialog' | 'alertdialog';
    className?: string;
};

/**
 * Modal built on the native `<dialog>` element: the browser provides the focus
 * trap, inert background, Escape handling and focus return, so no extra
 * dependency is needed. Focus moves into the dialog on open.
 */
function Dialog({
    children,
    className,
    description,
    footer,
    onClose,
    open,
    role = 'dialog',
    title,
}: DialogProps) {
    const ref = useRef<HTMLDialogElement>(null);
    const id = useId();
    const titleId = `${id}-title`;
    const descriptionId = `${id}-description`;

    useEffect(() => {
        const dialog = ref.current;

        if (dialog === null) {
            return;
        }

        if (open && !dialog.open) {
            dialog.showModal();
        } else if (!open && dialog.open) {
            dialog.close();
        }
    }, [open]);

    return (
        <dialog
            aria-describedby={description ? descriptionId : undefined}
            aria-labelledby={titleId}
            className={cn(
                'm-auto w-[min(32rem,calc(100vw-2rem))] rounded-lg border border-border bg-surface p-0 text-foreground shadow-overlay backdrop:bg-overlay',
                className,
            )}
            onCancel={(event) => {
                event.preventDefault();
                onClose();
            }}
            onClick={(event) => {
                if (role === 'dialog' && event.target === ref.current) {
                    onClose();
                }
            }}
            ref={ref}
            role={role}
        >
            <div className="flex flex-col gap-4 p-5">
                <div className="flex flex-col gap-1">
                    <h2 className="text-lg font-semibold" id={titleId}>
                        {title}
                    </h2>
                    {description ? (
                        <p
                            className="text-sm text-muted-foreground"
                            id={descriptionId}
                        >
                            {description}
                        </p>
                    ) : null}
                </div>
                {children}
                {footer ? (
                    <div className="flex flex-wrap justify-end gap-2">{footer}</div>
                ) : null}
            </div>
        </dialog>
    );
}

type ConfirmDialogProps = {
    open: boolean;
    title: string;
    /** State the consequence plainly (docs/RULES/07: destructive actions show consequence). */
    consequence: string;
    confirmLabel: string;
    cancelLabel: string;
    destructive?: boolean;
    /** Disables both actions and shows progress while the server processes the action. */
    pending?: boolean;
    /** Reason/approval fields required by the PRD for this action. */
    children?: ReactNode;
    onConfirm: () => void;
    onCancel: () => void;
};

/** AlertDialog for sensitive or destructive actions; the server result is displayed by the caller. */
function ConfirmDialog({
    cancelLabel,
    children,
    confirmLabel,
    consequence,
    destructive = false,
    onCancel,
    onConfirm,
    open,
    pending = false,
    title,
}: ConfirmDialogProps) {
    return (
        <Dialog
            description={consequence}
            footer={
                <>
                    <Button
                        disabled={pending}
                        onClick={onCancel}
                        type="button"
                        variant="outline"
                    >
                        {cancelLabel}
                    </Button>
                    <Button
                        loading={pending}
                        onClick={onConfirm}
                        type="button"
                        variant={destructive ? 'destructive' : 'default'}
                    >
                        {confirmLabel}
                    </Button>
                </>
            }
            onClose={pending ? () => undefined : onCancel}
            open={open}
            role="alertdialog"
            title={title}
        >
            {children}
        </Dialog>
    );
}

export { ConfirmDialog, Dialog };
