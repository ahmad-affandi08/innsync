import { Slot } from '@radix-ui/react-slot';
import { ChevronRight } from 'lucide-react';
import type { ComponentProps } from 'react';

import { useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';

function Breadcrumb(props: ComponentProps<'nav'>) {
    const { t } = useTranslation();

    return <nav aria-label={t('ui.breadcrumb')} data-slot="breadcrumb" {...props} />;
}

function BreadcrumbList({ className, ...props }: ComponentProps<'ol'>) {
    return <ol className={cn('flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground', className)} data-slot="breadcrumb-list" {...props} />;
}

function BreadcrumbItem({ className, ...props }: ComponentProps<'li'>) {
    return <li className={cn('inline-flex items-center gap-1.5', className)} data-slot="breadcrumb-item" {...props} />;
}

function BreadcrumbLink({ asChild, className, ...props }: ComponentProps<'a'> & { asChild?: boolean }) {
    const Comp = asChild ? Slot : 'a';

    return <Comp className={cn('transition-colors hover:text-foreground', className)} data-slot="breadcrumb-link" {...props} />;
}

function BreadcrumbPage({ className, ...props }: ComponentProps<'span'>) {
    return <span aria-current="page" className={cn('font-medium text-foreground', className)} data-slot="breadcrumb-page" {...props} />;
}

function BreadcrumbSeparator({ children, className, ...props }: ComponentProps<'li'>) {
    return (
        <li aria-hidden="true" className={cn('[&>svg]:size-3.5', className)} data-slot="breadcrumb-separator" role="presentation" {...props}>
            {children ?? <ChevronRight />}
        </li>
    );
}

export { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator };
