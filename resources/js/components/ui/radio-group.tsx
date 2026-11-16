import * as RadioGroupPrimitive from '@radix-ui/react-radio-group';
import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

function RadioGroup({ className, ...props }: ComponentProps<typeof RadioGroupPrimitive.Root>) {
    return <RadioGroupPrimitive.Root className={cn('grid gap-2', className)} data-slot="radio-group" {...props} />;
}

function RadioGroupItem({ className, ...props }: ComponentProps<typeof RadioGroupPrimitive.Item>) {
    return (
        <RadioGroupPrimitive.Item
            className={cn('grid aspect-square size-5 place-items-center rounded-full border-[1.5px] border-b-[3px] border-primary bg-surface transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:bg-brand', className)}
            data-slot="radio-group-item"
            {...props}
        >
            <RadioGroupPrimitive.Indicator className="size-2 rounded-full bg-primary-foreground" />
        </RadioGroupPrimitive.Item>
    );
}

export { RadioGroup, RadioGroupItem };
