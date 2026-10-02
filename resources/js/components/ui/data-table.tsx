import {
    columnVisibilityFeature,
    rowPaginationFeature,
    rowSortingFeature,
    tableFeatures,
    useTable,
    type ColumnDef,
    type ColumnVisibilityState,
    type PaginationState,
    type RowData,
    type SortingState,
} from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState, type ErrorStateProps } from '@/components/ui/error-state';
import { Pagination, type PaginationLabels } from '@/components/ui/pagination';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/shared/lib/utils';
import {
    clearFilters,
    hasActiveFilters,
    lastPage,
    withPage,
    withPerPage,
    withSort,
    type TableQueryConfig,
    type TableQueryState,
} from '@/shared/table/table-query-state';

export const dataTableFeatures = tableFeatures({
    columnVisibilityFeature,
    rowPaginationFeature,
    rowSortingFeature,
});

/** Typed column model. Define column arrays at module level so references stay stable. */
export type DataTableColumn<TData extends RowData> = ColumnDef<typeof dataTableFeatures, TData>;

export type DataTableLabels = {
    /** Accessible name/caption of the table (visually hidden). */
    caption: string;
    /** Announced while the first page is loading. */
    loading: string;
    /** Announced while a later page or filter is refreshing. */
    refreshing: string;
    /** Accessible name of the horizontally scrollable region. */
    scrollRegion: string;
    empty: { title: string; description?: string; action?: ReactNode };
    filteredEmpty: { title: string; description?: string; clearFilters: string };
    /** Label of the sort button for a column, e.g. "Sort by Name". */
    sortBy: (columnLabel: string) => string;
    pagination: PaginationLabels;
};

type DataTableProps<TData extends RowData> = {
    columns: DataTableColumn<TData>[];
    /** Already filtered, sorted and paginated by the server. Never pass the full dataset. */
    rows: readonly TData[] | undefined;
    /** Stable semantic server id; array indexes are not allowed as row keys. */
    getRowId: (row: TData) => string;
    /** Server-reported total number of matching rows. */
    rowCount: number | undefined;
    query: TableQueryState;
    queryConfig: TableQueryConfig;
    onQueryChange: (next: TableQueryState) => void;
    /** True until the first result for the current query exists. */
    isLoading: boolean;
    /** True while any request for this table is in flight. */
    isFetching?: boolean;
    error?: unknown;
    errorState: Omit<ErrorStateProps, 'error'>;
    labels: DataTableLabels;
    /** Visibility is a presentation preference; it never replaces authorization. */
    columnVisibility?: ColumnVisibilityState;
    /** Compact rendering below the `md` breakpoint instead of a squeezed table. */
    renderMobileRow?: (row: TData) => ReactNode;
    className?: string;
};

const SKELETON_ROWS = 6;

function DataTable<TData extends RowData>({
    className,
    columnVisibility,
    columns,
    error,
    errorState,
    getRowId,
    isFetching = false,
    isLoading,
    labels,
    onQueryChange,
    query,
    queryConfig,
    renderMobileRow,
    rowCount,
    rows,
}: DataTableProps<TData>) {
    const sorting: SortingState =
        query.sort === null
            ? []
            : [{ id: query.sort.id, desc: query.sort.direction === 'desc' }];
    const pagination: PaginationState = {
        pageIndex: query.page - 1,
        pageSize: query.perPage,
    };

    const table = useTable({
        features: dataTableFeatures,
        columns,
        data: (rows ?? []) as TData[],
        getRowId,
        // Server owns filtering, sorting and paging: these flags only stop the
        // client pipeline from re-processing a single page.
        manualPagination: true,
        manualSorting: true,
        rowCount: rowCount ?? 0,
        enableMultiSort: false,
        state: {
            sorting,
            pagination,
            columnVisibility: columnVisibility ?? {},
        },
        onSortingChange: (updater) => {
            const next = typeof updater === 'function' ? updater(sorting) : updater;
            const first = next[0];

            onQueryChange(
                withSort(
                    query,
                    first ? { id: first.id, direction: first.desc ? 'desc' : 'asc' } : null,
                    queryConfig,
                ),
            );
        },
    });

    // A stale deep link past the last page recovers to the last real page.
    useEffect(() => {
        if (!isFetching && rowCount !== undefined && query.page > lastPage(rowCount, query.perPage)) {
            onQueryChange(withPage(query, lastPage(rowCount, query.perPage)));
        }
    }, [isFetching, onQueryChange, query, rowCount]);

    const hasError = error !== undefined && error !== null;
    const tableRows = table.getRowModel().rows;
    const filtered = hasActiveFilters(query);
    const columnCount = Math.max(table.getVisibleLeafColumns().length, 1);

    let body: ReactNode;

    if (isLoading) {
        body = (
            <tbody>
                {Array.from({ length: SKELETON_ROWS }, (_, index) => (
                    <tr className="border-b border-border" key={index}>
                        <td className="px-3 py-3" colSpan={columnCount}>
                            <Skeleton className="h-5 w-full" />
                        </td>
                    </tr>
                ))}
            </tbody>
        );
    } else if (hasError && tableRows.length === 0) {
        body = null;
    } else if (tableRows.length === 0) {
        body = null;
    } else {
        body = (
            <tbody>
                {tableRows.map((row) => (
                    <tr
                        className="border-b border-border last:border-b-0 hover:bg-surface-muted"
                        key={row.id}
                    >
                        {row.getVisibleCells().map((cell) => (
                            <td className="px-3 py-2.5 align-middle" key={cell.id}>
                                <table.FlexRender cell={cell} />
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        );
    }

    let message: ReactNode = null;

    if (hasError && !isLoading && tableRows.length === 0) {
        message = <ErrorState {...errorState} error={error} />;
    } else if (!isLoading && !hasError && tableRows.length === 0) {
        message = filtered ? (
            <EmptyState
                action={
                    <Button
                        onClick={() => onQueryChange(clearFilters(query))}
                        type="button"
                        variant="outline"
                    >
                        {labels.filteredEmpty.clearFilters}
                    </Button>
                }
                description={labels.filteredEmpty.description}
                title={labels.filteredEmpty.title}
            />
        ) : (
            <EmptyState
                action={labels.empty.action}
                description={labels.empty.description}
                title={labels.empty.title}
            />
        );
    }

    return (
        <div
            aria-busy={isLoading || isFetching || undefined}
            className={cn('flex flex-col gap-3', className)}
        >
            {/* Stale rows stay visible during a refresh; a failed refresh is reported without discarding them. */}
            {hasError && tableRows.length > 0 ? (
                <ErrorState {...errorState} error={error} />
            ) : null}

            <p aria-live="polite" className="sr-only" role="status">
                {isLoading ? labels.loading : isFetching ? labels.refreshing : ''}
            </p>

            <div
                aria-label={labels.scrollRegion}
                className={cn(
                    'overflow-x-auto border border-border bg-surface',
                    renderMobileRow ? 'hidden md:block' : undefined,
                )}
                // Keyboard users must be able to scroll a wide table.
                role="region"
                tabIndex={0}
            >
                <table className="w-full border-collapse text-left text-sm">
                    <caption className="sr-only">{labels.caption}</caption>
                    <thead className="border-b border-border bg-surface-muted">
                        {table.getHeaderGroups().map((group) => (
                            <tr key={group.id}>
                                {group.headers.map((header) => {
                                    // Only columns the server declared sortable get a control.
                                    const canSort =
                                        header.column.getCanSort() &&
                                        queryConfig.sortableColumns.includes(header.column.id);
                                    const sorted = header.column.getIsSorted();
                                    const title =
                                        typeof header.column.columnDef.header === 'string'
                                            ? header.column.columnDef.header
                                            : header.column.id;

                                    return (
                                        <th
                                            aria-sort={
                                                sorted === 'asc'
                                                    ? 'ascending'
                                                    : sorted === 'desc'
                                                      ? 'descending'
                                                      : canSort
                                                        ? 'none'
                                                        : undefined
                                            }
                                            className="whitespace-nowrap px-3 py-2.5 text-xs font-semibold text-muted-foreground"
                                            colSpan={header.colSpan}
                                            key={header.id}
                                            scope="col"
                                        >
                                            {header.isPlaceholder ? null : canSort ? (
                                                <button
                                                    aria-label={labels.sortBy(title)}
                                                    className="inline-flex min-h-8 items-center gap-1 text-left font-semibold hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                    onClick={header.column.getToggleSortingHandler()}
                                                    type="button"
                                                >
                                                    <table.FlexRender header={header} />
                                                    {sorted === 'asc' ? (
                                                        <ArrowUp aria-hidden="true" className="size-3.5" />
                                                    ) : sorted === 'desc' ? (
                                                        <ArrowDown aria-hidden="true" className="size-3.5" />
                                                    ) : (
                                                        <ArrowUpDown aria-hidden="true" className="size-3.5 opacity-50" />
                                                    )}
                                                </button>
                                            ) : (
                                                <table.FlexRender header={header} />
                                            )}
                                        </th>
                                    );
                                })}
                            </tr>
                        ))}
                    </thead>
                    {body}
                </table>
                {message}
            </div>

            {renderMobileRow ? (
                <ul className="flex flex-col gap-2 md:hidden">
                    {isLoading
                        ? Array.from({ length: SKELETON_ROWS }, (_, index) => (
                              <li key={index}>
                                  <Skeleton className="h-16 w-full" />
                              </li>
                          ))
                        : tableRows.map((row) => (
                              <li
                                  className="border border-border bg-surface p-3 "
                                  key={row.id}
                              >
                                  {renderMobileRow(row.original)}
                              </li>
                          ))}
                    {tableRows.length === 0 && !isLoading ? (
                        <li className="border border-border bg-surface">{message}</li>
                    ) : null}
                </ul>
            ) : null}

            {rowCount !== undefined && rowCount > 0 ? (
                <Pagination
                    disabled={isFetching}
                    labels={labels.pagination}
                    onPageChange={(page) => onQueryChange(withPage(query, page))}
                    onPerPageChange={(perPage) =>
                        onQueryChange(withPerPage(query, perPage, queryConfig))
                    }
                    page={query.page}
                    perPage={query.perPage}
                    perPageOptions={queryConfig.perPageOptions}
                    rowCount={rowCount}
                />
            ) : null}
        </div>
    );
}

export { DataTable };
