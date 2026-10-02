import * as TogglePrimitive from '@radix-ui/react-toggle';
import * as ToggleGroupPrimitive from '@radix-ui/react-toggle-group';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

const item =
    'inline-flex h-9 items-center justify-center gap-2 border border-input bg-surface px-3 text-sm font-medium text-foreground transition-colors hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground';

function Toggle({ className, ...props }: ComponentProps<typeof TogglePrimitive.Root>) {
    return <TogglePrimitive.Root className={cn(item, className)} data-slot="toggle" {...props} />;
}

function ToggleGroup({ className, ...props }: ComponentProps<typeof ToggleGroupPrimitive.Root>) {
    return <ToggleGroupPrimitive.Root className={cn('inline-flex items-center', className)} data-slot="toggle-group" {...props} />;
}

function ToggleGroupItem({ className, ...props }: ComponentProps<typeof ToggleGroupPrimitive.Item>) {
    return <ToggleGroupPrimitive.Item className={cn(item, '-ml-px first:ml-0', className)} data-slot="toggle-group-item" {...props} />;
}

export { Toggle, ToggleGroup, ToggleGroupItem };
