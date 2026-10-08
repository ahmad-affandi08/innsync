import { usePage } from '@inertiajs/react';

export type Brand = { logoUrl: string | null; poweredBy: boolean };

/** The property's own logo and whether "Powered by InnSYnc" is shown, as the server decided for this page. */
export function useBrand(): Brand {
    return usePage<{ brand?: Brand }>().props.brand ?? { logoUrl: null, poweredBy: false };
}
