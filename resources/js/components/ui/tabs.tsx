import * as TabsPrimitive from '@radix-ui/react-tabs';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

function Tabs({ className, ...props }: ComponentProps<typeof TabsPrimitive.Root>) {
    return <TabsPrimitive.Root className={cn('flex flex-col gap-4', className)} data-slot="tabs" {...props} />;
}

function TabsList({ className, ...props }: ComponentProps<typeof TabsPrimitive.List>) {
    return <TabsPrimitive.List className={cn('flex w-full flex-wrap items-stretch gap-1 overflow-x-auto print:hidden', className)} data-slot="tabs-list" {...props} />;
}

function TabsTrigger({ className, ...props }: ComponentProps<typeof TabsPrimitive.Trigger>) {
    return (
        <TabsPrimitive.Trigger
            className={cn(
                'inline-flex min-h-9 items-center justify-center gap-2 whitespace-nowrap bg-primary px-4 text-sm text-primary-foreground transition-colors hover:bg-primary/85 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-brand data-[state=active]:font-semibold data-[state=active]:text-primary',
                className,
            )}
            data-slot="tabs-trigger"
            {...props}
        />
    );
}

function TabsContent({ className, ...props }: ComponentProps<typeof TabsPrimitive.Content>) {
    // Every panel stays mounted (what was typed in one is kept when another is shown), the hidden ones are not displayed, and a printout has all of them.
    return <TabsPrimitive.Content className={cn('focus-visible:outline-none data-[state=inactive]:hidden print:data-[state=inactive]:!flex', className)} data-slot="tabs-content" forceMount {...props} />;
}

export { Tabs, TabsContent, TabsList, TabsTrigger };
