import type { ComponentProps } from 'react';

import { cn } from '@/shared/lib/utils';

function Card({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('border border-border bg-surface text-foreground', className)} data-slot="card" {...props} />;
}

function CardHeader({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('flex flex-col gap-1.5 border-b border-border p-5', className)} data-slot="card-header" {...props} />;
}

function CardTitle({ className, ...props }: ComponentProps<'h3'>) {
    return <h3 className={cn('text-base font-semibold leading-none tracking-tight', className)} data-slot="card-title" {...props} />;
}

function CardDescription({ className, ...props }: ComponentProps<'p'>) {
    return <p className={cn('text-sm text-muted-foreground', className)} data-slot="card-description" {...props} />;
}

function CardContent({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('p-5', className)} data-slot="card-content" {...props} />;
}

function CardFooter({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('flex items-center border-t border-border p-5', className)} data-slot="card-footer" {...props} />;
}

export { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle };
