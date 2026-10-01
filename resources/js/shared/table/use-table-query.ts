import { router, usePage } from '@inertiajs/react';
import { useCallback, useMemo } from 'react';

import {
    parseTableQuery,
    serializeTableQuery,
    type TableQueryConfig,
    type TableQueryState,
} from '@/shared/table/table-query-state';

type UpdateOptions = {
    /** Use for keystroke-level changes (text filters) so history is not flooded. */
    replace?: boolean;
};

/**
 * Binds a table's page/size/sort/filter state to the URL query string
 * (docs/RULES/08: URL is the shareable source for filters).
 *
 * The update is an Inertia client-side visit: it changes the address bar and
 * history without a server round trip. The rows themselves are fetched by
 * TanStack Query keyed on this state, so a canonical dataset is never loaded
 * through both mechanisms (ADR-0004). `config` must be a stable module-level
 * constant.
 */
export function useTableQuery(config: TableQueryConfig) {
    const { url } = usePage();
    const [path = '', rest = ''] = url.split('#')[0]?.split('?') ?? [];
    const search = rest;

    const state = useMemo(() => parseTableQuery(search, config), [search, config]);

    const setState = useCallback(
        (next: TableQueryState, options: UpdateOptions = {}) => {
            const query = serializeTableQuery(next, config).toString();
            const target = query === '' ? path : `${path}?${query}`;
            const visit = { url: target, preserveScroll: true, preserveState: true };

            if (options.replace) {
                router.replace(visit);
            } else {
                router.push(visit);
            }
        },
        [config, path],
    );

    return [state, setState] as const;
}
