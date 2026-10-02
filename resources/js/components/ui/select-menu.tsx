import * as SelectPrimitive from '@radix-ui/react-select';
import { Check, ChevronDown, ChevronUp } from 'lucide-react';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

/** The shadcn/ui Select (a styled listbox). Forms keep the native `Select` of this folder; use this where a menu of choices reads better. */
const SelectMenu = SelectPrimitive.Root;
const SelectGroup = SelectPrimitive.Group;
const SelectValue = SelectPrimitive.Value;

function SelectTrigger({ children, className, ...props }: ComponentProps<typeof SelectPrimitive.Trigger>) {
    return (
        <SelectPrimitive.Trigger
            className={cn('flex min-h-10 w-full items-center justify-between gap-2 border border-input bg-surface px-3 py-2 text-sm text-foreground focus-visible:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50 data-[placeholder]:text-muted-foreground', className)}
            data-slot="select-trigger"
            {...props}
        >
            {children}
            <SelectPrimitive.Icon asChild><ChevronDown aria-hidden="true" className="size-4 text-muted-foreground" /></SelectPrimitive.Icon>
        </SelectPrimitive.Trigger>
    );
}

function SelectContent({ children, className, position = 'popper', ...props }: ComponentProps<typeof SelectPrimitive.Content>) {
    return (
        <SelectPrimitive.Portal>
            <SelectPrimitive.Content
                className={cn('relative z-50 max-h-72 min-w-[8rem] overflow-hidden border border-border bg-surface text-foreground data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0', position === 'popper' && 'w-[var(--radix-select-trigger-width)]', className)}
                data-slot="select-content"
                position={position}
                sideOffset={4}
                {...props}
            >
                <SelectPrimitive.ScrollUpButton className="flex h-6 items-center justify-center"><ChevronUp aria-hidden="true" className="size-4" /></SelectPrimitive.ScrollUpButton>
                <SelectPrimitive.Viewport className="p-1">{children}</SelectPrimitive.Viewport>
                <SelectPrimitive.ScrollDownButton className="flex h-6 items-center justify-center"><ChevronDown aria-hidden="true" className="size-4" /></SelectPrimitive.ScrollDownButton>
            </SelectPrimitive.Content>
        </SelectPrimitive.Portal>
    );
}

function SelectLabel({ className, ...props }: ComponentProps<typeof SelectPrimitive.Label>) {
    return <SelectPrimitive.Label className={cn('px-2.5 py-2 text-xs font-medium text-muted-foreground', className)} {...props} />;
}

function SelectItem({ children, className, ...props }: ComponentProps<typeof SelectPrimitive.Item>) {
    return (
        <SelectPrimitive.Item className={cn('relative flex w-full cursor-default select-none items-center py-2 pl-8 pr-2.5 text-sm outline-none focus:bg-surface-muted data-[disabled]:pointer-events-none data-[disabled]:opacity-50', className)} data-slot="select-item" {...props}>
            <span className="absolute left-2.5 flex size-4 items-center justify-center">
                <SelectPrimitive.ItemIndicator><Check aria-hidden="true" className="size-4" /></SelectPrimitive.ItemIndicator>
            </span>
            <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
        </SelectPrimitive.Item>
    );
}

function SelectSeparator({ className, ...props }: ComponentProps<typeof SelectPrimitive.Separator>) {
    return <SelectPrimitive.Separator className={cn('-mx-1 my-1 h-px bg-border', className)} {...props} />;
}

export { SelectContent, SelectGroup, SelectItem, SelectLabel, SelectMenu, SelectSeparator, SelectTrigger, SelectValue };
