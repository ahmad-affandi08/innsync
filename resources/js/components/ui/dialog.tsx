import * as AlertDialogPrimitive from '@radix-ui/react-alert-dialog';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';

// ---- the shadcn/ui primitives, owned here and without radius or shadow ----

const DialogRoot = DialogPrimitive.Root;
const DialogTrigger = DialogPrimitive.Trigger;
const DialogClose = DialogPrimitive.Close;
const DialogPortal = DialogPrimitive.Portal;

function DialogOverlay({ className, ...props }: ComponentProps<typeof DialogPrimitive.Overlay>) {
    return <DialogPrimitive.Overlay className={cn('fixed inset-0 z-50 bg-overlay data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0', className)} data-slot="dialog-overlay" {...props} />;
}

function DialogContent({ children, className, ...props }: ComponentProps<typeof DialogPrimitive.Content>) {
    const { t } = useTranslation();

    return (
        <DialogPortal>
            <DialogOverlay />
            <DialogPrimitive.Content
                className={cn('fixed left-1/2 top-1/2 z-50 grid max-h-[calc(100dvh-2rem)] w-[min(34rem,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 gap-4 overflow-y-auto border border-border bg-surface p-6 text-foreground data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0', className)}
                data-slot="dialog-content"
                {...props}
            >
                {children}
                <DialogPrimitive.Close className="absolute right-4 top-4 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <X aria-hidden="true" className="size-4" />
                    <span className="sr-only">{t('ui.dialog.close')}</span>
                </DialogPrimitive.Close>
            </DialogPrimitive.Content>
        </DialogPortal>
    );
}

function DialogHeader({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('flex flex-col gap-1.5', className)} data-slot="dialog-header" {...props} />;
}

function DialogFooter({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('flex flex-wrap justify-end gap-2', className)} data-slot="dialog-footer" {...props} />;
}

function DialogTitle({ className, ...props }: ComponentProps<typeof DialogPrimitive.Title>) {
    return <DialogPrimitive.Title className={cn('text-lg font-semibold leading-none tracking-tight', className)} data-slot="dialog-title" {...props} />;
}

function DialogDescription({ className, ...props }: ComponentProps<typeof DialogPrimitive.Description>) {
    return <DialogPrimitive.Description className={cn('text-sm text-muted-foreground', className)} data-slot="dialog-description" {...props} />;
}

// ---- the project's Dialog and ConfirmDialog, on top of them (same props as before) ----

type DialogProps = {
    open: boolean;
    title: string;
    description?: string;
    children?: ReactNode;
    /** Footer actions. Closing via Escape/backdrop calls `onClose`. */
    footer?: ReactNode;
    onClose: () => void;
    /** Alert dialogs interrupt for a consequential choice and ignore clicks outside. */
    role?: 'dialog' | 'alertdialog';
    className?: string;
};

/** A modal with a focus trap, an inert background, Escape handling and focus return, from Radix through shadcn/ui. */
function Dialog({ children, className, description, footer, onClose, open, role = 'dialog', title }: DialogProps) {
    const body = (
        <>
            <div className="flex flex-col gap-1.5">
                {role === 'alertdialog' ? (
                    <AlertDialogPrimitive.Title className="text-lg font-semibold leading-none tracking-tight">{title}</AlertDialogPrimitive.Title>
                ) : (
                    <DialogTitle>{title}</DialogTitle>
                )}
                {description ? (
                    role === 'alertdialog' ? (
                        <AlertDialogPrimitive.Description className="text-sm text-muted-foreground">{description}</AlertDialogPrimitive.Description>
                    ) : (
                        <DialogDescription>{description}</DialogDescription>
                    )
                ) : null}
            </div>
            {children}
            {footer ? <DialogFooter>{footer}</DialogFooter> : null}
        </>
    );
    const surface = cn('fixed left-1/2 top-1/2 z-50 grid max-h-[calc(100dvh-2rem)] w-[min(34rem,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 gap-4 overflow-y-auto border border-border bg-surface p-6 text-foreground data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0', className);

    if (role === 'alertdialog') {
        return (
            <AlertDialogPrimitive.Root onOpenChange={(next) => { if (!next) onClose(); }} open={open}>
                <AlertDialogPrimitive.Portal>
                    <AlertDialogPrimitive.Overlay className="fixed inset-0 z-50 bg-overlay data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0" />
                    <AlertDialogPrimitive.Content className={surface}>
                        {body}
                    </AlertDialogPrimitive.Content>
                </AlertDialogPrimitive.Portal>
            </AlertDialogPrimitive.Root>
        );
    }

    return (
        <DialogRoot onOpenChange={(next) => { if (!next) onClose(); }} open={open}>
            <DialogPortal>
                <DialogOverlay />
                <DialogPrimitive.Content className={surface}>
                    {body}
                </DialogPrimitive.Content>
            </DialogPortal>
        </DialogRoot>
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
function ConfirmDialog({ cancelLabel, children, confirmLabel, consequence, destructive = false, onCancel, onConfirm, open, pending = false, title }: ConfirmDialogProps) {
    return (
        <Dialog
            description={consequence}
            footer={
                <>
                    <Button disabled={pending} onClick={onCancel} type="button" variant="outline">{cancelLabel}</Button>
                    <Button loading={pending} onClick={onConfirm} type="button" variant={destructive ? 'destructive' : 'default'}>{confirmLabel}</Button>
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

export { ConfirmDialog, Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, DialogTrigger };
