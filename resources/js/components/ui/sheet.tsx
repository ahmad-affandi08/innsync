import * as SheetPrimitive from '@radix-ui/react-dialog';
import { cva, type VariantProps } from 'class-variance-authority';
import { X } from 'lucide-react';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

const Sheet = SheetPrimitive.Root;
const SheetTrigger = SheetPrimitive.Trigger;
const SheetClose = SheetPrimitive.Close;
const SheetPortal = SheetPrimitive.Portal;

function SheetOverlay({ className, ...props }: ComponentProps<typeof SheetPrimitive.Overlay>) {
    return <SheetPrimitive.Overlay className={cn('fixed inset-0 z-50 bg-overlay data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0', className)} data-slot="sheet-overlay" {...props} />;
}

const sheetVariants = cva('fixed z-50 flex flex-col gap-4 bg-surface text-foreground transition ease-in-out data-[state=closed]:animate-out data-[state=open]:animate-in data-[state=closed]:duration-200 data-[state=open]:duration-300', {
    variants: {
        side: {
            top: 'inset-x-0 top-0 border-b border-border data-[state=closed]:slide-out-to-top data-[state=open]:slide-in-from-top',
            bottom: 'inset-x-0 bottom-0 border-t border-border data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom',
            left: 'inset-y-0 left-0 h-full w-72 max-w-[85vw] border-r border-border data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left',
            right: 'inset-y-0 right-0 h-full w-80 max-w-[90vw] border-l border-border data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right',
        },
    },
    defaultVariants: { side: 'right' },
});

function SheetContent({ children, className, side = 'right', ...props }: ComponentProps<typeof SheetPrimitive.Content> & VariantProps<typeof sheetVariants>) {
    return (
        <SheetPortal>
            <SheetOverlay />
            <SheetPrimitive.Content className={cn(sheetVariants({ side }), className)} data-slot="sheet-content" {...props}>
                {children}
                <SheetPrimitive.Close className="absolute right-3 top-3 p-1 text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <X aria-hidden="true" className="size-4" />
                    <span className="sr-only">Close</span>
                </SheetPrimitive.Close>
            </SheetPrimitive.Content>
        </SheetPortal>
    );
}

function SheetHeader({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('flex flex-col gap-1.5 p-4', className)} data-slot="sheet-header" {...props} />;
}

function SheetFooter({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('mt-auto flex flex-col gap-2 p-4', className)} data-slot="sheet-footer" {...props} />;
}

function SheetTitle({ className, ...props }: ComponentProps<typeof SheetPrimitive.Title>) {
    return <SheetPrimitive.Title className={cn('text-base font-semibold', className)} data-slot="sheet-title" {...props} />;
}

function SheetDescription({ className, ...props }: ComponentProps<typeof SheetPrimitive.Description>) {
    return <SheetPrimitive.Description className={cn('text-sm text-muted-foreground', className)} data-slot="sheet-description" {...props} />;
}

export { Sheet, SheetClose, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetOverlay, SheetPortal, SheetTitle, SheetTrigger };
