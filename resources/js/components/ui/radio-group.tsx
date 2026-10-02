import * as RadioGroupPrimitive from '@radix-ui/react-radio-group';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

function RadioGroup({ className, ...props }: ComponentProps<typeof RadioGroupPrimitive.Root>) {
    return <RadioGroupPrimitive.Root className={cn('grid gap-2', className)} data-slot="radio-group" {...props} />;
}

function RadioGroupItem({ className, ...props }: ComponentProps<typeof RadioGroupPrimitive.Item>) {
    return (
        <RadioGroupPrimitive.Item
            className={cn('grid aspect-square size-4 place-items-center border border-input bg-surface focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary', className)}
            data-slot="radio-group-item"
            {...props}
        >
            <RadioGroupPrimitive.Indicator className="size-2 bg-primary" />
        </RadioGroupPrimitive.Item>
    );
}

export { RadioGroup, RadioGroupItem };
