import { useMemo } from 'react';

import type { DataTableLabels } from '@/components/ui/data-table';
import type { ErrorStateProps } from '@/components/ui/error-state';
import type { PaginationLabels } from '@/components/ui/pagination';
import { useTranslation } from '@/shared/i18n/i18n';
import type { ReactNode } from 'react';

/** Localized copy for the shared error state, covering every failure kind. */
export function useErrorStateCopy(): Omit<ErrorStateProps, 'error' | 'onRetry' | 'onRefresh' | 'signInHref'> {
    const { t } = useTranslation();

    return useMemo(
        () => ({
            copy: {
                validation: { title: t('ui.failure.title.validation') },
                conflict: {
                    title: t('ui.failure.title.conflict'),
                    description: t('ui.failure.description.conflict'),
                },
                forbidden: {
                    title: t('ui.failure.title.forbidden'),
                    description: t('ui.failure.description.forbidden'),
                },
                unauthenticated: { title: t('ui.failure.title.unauthenticated') },
                'session-expired': {
                    title: t('ui.failure.title.sessionExpired'),
                    description: t('ui.failure.description.sessionExpired'),
                },
                'not-found': {
                    title: t('ui.failure.title.notFound'),
                    description: t('ui.failure.description.notFound'),
                },
                'rate-limited': {
                    title: t('ui.failure.title.rateLimited'),
                    description: t('ui.failure.description.rateLimited'),
                },
                offline: {
                    title: t('ui.failure.title.offline'),
                    description: t('ui.failure.description.offline'),
                },
            },
            fallback: {
                title: t('ui.failure.title.serverError'),
                description: t('ui.failure.description.serverError'),
            },
            retryLabel: t('ui.failure.retry'),
            refreshLabel: t('ui.failure.refresh'),
            signInLabel: t('ui.failure.signIn'),
            referenceLabel: t('ui.failure.reference'),
        }),
        [t],
    );
}

export function usePaginationLabels(): PaginationLabels {
    const { t } = useTranslation();

    return useMemo(
        () => ({
            navigation: t('ui.pagination.navigation'),
            previous: t('ui.pagination.previous'),
            next: t('ui.pagination.next'),
            perPage: t('ui.pagination.perPage'),
            summary: ({ from, to, total }) => t('ui.pagination.summary', { from, to, total }),
            pageStatus: (page, pages) => t('ui.pagination.pageStatus', { page, pages }),
        }),
        [t],
    );
}

/**
 * Standard table copy. The screen supplies only what is specific to it: the
 * table's name and, optionally, its own empty-state wording and action.
 */
export function useDataTableLabels(options: {
    name: string;
    empty?: { title: string; description?: string; action?: ReactNode };
}): DataTableLabels {
    const { t } = useTranslation();
    const pagination = usePaginationLabels();
    const { empty, name } = options;

    return useMemo(
        () => ({
            caption: name,
            loading: t('ui.table.loading'),
            refreshing: t('ui.table.refreshing'),
            scrollRegion: t('ui.table.scrollRegion', { name }),
            empty: empty ?? { title: t('ui.table.empty.title') },
            filteredEmpty: {
                title: t('ui.table.filteredEmpty.title'),
                description: t('ui.table.filteredEmpty.description'),
                clearFilters: t('ui.table.filteredEmpty.clear'),
            },
            sortBy: (column) => t('ui.table.sortBy', { column }),
            pagination,
        }),
        [empty, name, pagination, t],
    );
}
