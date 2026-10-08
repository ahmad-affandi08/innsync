import { useTranslation } from '@/shared/i18n/i18n';
import { useBrand } from '@/shared/lib/brand';
import { cn } from '@/shared/lib/utils';

/**
 * "Powered by InnSYnc", only where the property shows its own logo and has not switched the line off. `print` puts it once at the end of a printed
 * document; `inline` is the line inside a screen's own footer. Both read the same switch.
 */
export function PoweredBy({ className, print = false }: { className?: string; print?: boolean }) {
    const { t } = useTranslation();
    const brand = useBrand();

    if (brand.logoUrl === null || !brand.poweredBy) {
        return null;
    }

    return <span className={cn(print ? 'hidden pt-3 text-center text-xs text-muted-foreground print:block' : 'text-xs text-muted-foreground', className)} data-testid="powered-by">{t('brand.poweredBy')}</span>;
}
