import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Columns3, Search, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useId, useMemo, useState, type ReactNode } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useTranslation } from '@/shared/i18n/i18n';
import { cn } from '@/shared/lib/utils';
import {
    activeFilterCount,
    buildGridView,
    DEFAULT_PAGE_SIZE,
    distinctValues,
    initialGridState,
    pageNumbers,
    PAGE_SIZES,
    type GridColumnModel,
    type GridState,
} from '@/shared/table/grid-model';

export type DataGridColumn<T> = GridColumnModel<T> & {
    /** Plain-text name: the header, the column menu and the filter label. */
    label: string;
    /** Richer header content when the plain label is not enough. */
    header?: ReactNode;
    cell?: (row: T) => ReactNode;
    align?: 'right';
    /** Hidden until the person turns it on in the Columns menu. */
    hidden?: boolean;
    /** The row's name for assistive technology; the column cannot be hidden. */
    rowHeader?: boolean;
    /** Shown in the table footer, under this column (totals computed by the server). */
    footer?: ReactNode;
    /** Display text of a select filter's value. */
    filterLabel?: (value: string) => string;
    className?: string;
};

type DataGridProps<T> = {
    /** Stable name; remembers the page size and hidden columns per browser. */
    id: string;
    caption: string;
    rows: readonly T[];
    columns: readonly DataGridColumn<T>[];
    getRowId: (row: T) => string;
    rowTestId?: (row: T) => string;
    testId?: string;
    /** Label of the footer row's first cell when that column has no footer of its own. */
    footerLabel?: string;
    /** Shown when the screen has no rows at all (not when a filter hides them). */
    empty?: ReactNode;
    /** Extra controls placed in the toolbar, after the built-in ones. */
    toolbarExtra?: ReactNode;
    className?: string;
};

type Saved = { pageSize: number; hidden: string[] | null };

const storageKey = (id: string) => `innsync.grid.${id}`;

function readSaved(id: string): Saved {
    try {
        const raw = window.localStorage.getItem(storageKey(id));
        const parsed = raw === null ? null : (JSON.parse(raw) as Partial<Saved>);
        const size = parsed?.pageSize;

        return {
            pageSize: PAGE_SIZES.includes(size as (typeof PAGE_SIZES)[number]) ? (size as number) : DEFAULT_PAGE_SIZE,
            hidden: Array.isArray(parsed?.hidden) ? parsed.hidden.filter((x): x is string => typeof x === 'string') : null,
        };
    } catch {
        return { pageSize: DEFAULT_PAGE_SIZE, hidden: null };
    }
}

function writeSaved(id: string, saved: Saved) {
    try {
        window.localStorage.setItem(storageKey(id), JSON.stringify(saved));
    } catch {
        // Private mode or blocked storage: the grid still works, it just forgets.
    }
}

/**
 * The standard list table: search, per-column filters, show/hide columns,
 * sortable headers and full pagination (10/25/50/100, 10 by default).
 * Works on the rows a screen already holds; unbounded lists page on the server.
 */
function DataGrid<T>({ caption, className, columns, empty, footerLabel, getRowId, id, rowTestId, rows, testId, toolbarExtra }: DataGridProps<T>) {
    const { t } = useTranslation();
    const searchId = useId();
    const defaultHidden = useMemo(() => columns.filter((c) => c.hidden).map((c) => c.id), [columns]);
    const [state, setState] = useState<GridState>(() => initialGridState(DEFAULT_PAGE_SIZE));
    const [hidden, setHidden] = useState<string[]>(defaultHidden);

    // Saved preferences are read after mount so the server and first client render agree.
    useEffect(() => {
        const saved = readSaved(id);

        setState((s) => ({ ...s, pageSize: saved.pageSize }));

        if (saved.hidden !== null) setHidden(saved.hidden);
    }, [id]);

    const view = useMemo(() => buildGridView(rows, columns, state), [rows, columns, state]);
    const visible = columns.filter((c) => c.rowHeader || !hidden.includes(c.id));
    const filterable = columns.filter((c) => c.filter && c.value);
    const filterCount = activeFilterCount(state.filters);
    const filtering = filterCount > 0 || state.search.trim() !== '';
    const hasFooter = visible.some((c) => c.footer !== undefined);

    function patch(next: Partial<GridState>, resetPage = true) {
        setState((s) => ({ ...s, ...next, page: resetPage ? 1 : (next.page ?? s.page) }));
    }

    function save(pageSize: number, nextHidden: string[]) {
        writeSaved(id, { pageSize, hidden: nextHidden });
    }

    function toggleSort(columnId: string) {
        const current = state.sort?.id === columnId ? state.sort.direction : null;

        patch({ sort: current === null ? { id: columnId, direction: 'asc' } : current === 'asc' ? { id: columnId, direction: 'desc' } : null });
    }

    function clearAll() {
        patch({ search: '', filters: {} });
    }

    if (rows.length === 0) {
        return <div className={className}>{empty ?? <EmptyState title={t('ui.grid.noRows')} />}</div>;
    }

    const pages = pageNumbers(view.page, view.pages);
    const first = visible[0];

    return (
        <div className={cn('flex flex-col gap-3', className)} data-grid={id}>
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-72">
                    <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <label className="sr-only" htmlFor={searchId}>{t('ui.grid.search')}</label>
                    <Input
                        className="min-h-10 pl-9 pr-9"
                        id={searchId}
                        onChange={(e) => patch({ search: e.target.value })}
                        placeholder={t('ui.grid.searchPlaceholder')}
                        type="search"
                        value={state.search}
                    />
                    {state.search !== '' ? (
                        <button aria-label={t('ui.grid.clearFilters')} className="absolute right-2 top-1/2 grid size-6 -translate-y-1/2 place-items-center text-muted-foreground hover:text-foreground" onClick={() => patch({ search: '' })} type="button">
                            <X aria-hidden="true" className="size-4" />
                        </button>
                    ) : null}
                </div>

                {filterable.length > 0 ? (
                    <Popover>
                        <PopoverTrigger asChild>
                            <Button type="button" variant="outline">
                                <SlidersHorizontal aria-hidden="true" className="size-4" />
                                {t('ui.grid.filters')}
                                {filterCount > 0 ? <Badge tone="info">{filterCount}</Badge> : null}
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent align="start" aria-label={t('ui.grid.filtersTitle')} className="w-80">
                            <div className="flex flex-col gap-3">
                                {filterable.map((c) => {
                                    const fieldId = `${searchId}-${c.id}`;

                                    return (
                                        <div className="flex flex-col gap-1" key={c.id}>
                                            <label className="text-xs font-medium text-muted-foreground" htmlFor={fieldId}>{c.label}</label>
                                            {c.filter === 'select' ? (
                                                <Select id={fieldId} onChange={(e) => patch({ filters: { ...state.filters, [c.id]: e.target.value } })} value={state.filters[c.id] ?? ''}>
                                                    <option value="">{t('ui.grid.all')}</option>
                                                    {distinctValues(rows, c).map((v) => <option key={v} value={v}>{c.filterLabel ? c.filterLabel(v) : v}</option>)}
                                                </Select>
                                            ) : (
                                                <Input id={fieldId} onChange={(e) => patch({ filters: { ...state.filters, [c.id]: e.target.value } })} value={state.filters[c.id] ?? ''} />
                                            )}
                                        </div>
                                    );
                                })}
                                <Button disabled={filterCount === 0} onClick={() => patch({ filters: {} })} size="sm" type="button" variant="outline">{t('ui.grid.clearFilters')}</Button>
                            </div>
                        </PopoverContent>
                    </Popover>
                ) : null}

                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button type="button" variant="outline">
                            <Columns3 aria-hidden="true" className="size-4" />
                            {t('ui.grid.columns')}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" className="min-w-56">
                        <DropdownMenuLabel>{t('ui.grid.columnsTitle')}</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {columns.filter((c) => !c.rowHeader).map((c) => (
                            <DropdownMenuCheckboxItem
                                checked={!hidden.includes(c.id)}
                                key={c.id}
                                onCheckedChange={(on) => {
                                    const next = on ? hidden.filter((h) => h !== c.id) : [...hidden, c.id];

                                    setHidden(next);
                                    save(state.pageSize, next);
                                }}
                                onSelect={(e) => e.preventDefault()}
                            >
                                {c.label}
                            </DropdownMenuCheckboxItem>
                        ))}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={(e) => { e.preventDefault(); setHidden([]); save(state.pageSize, []); }}>{t('ui.grid.showAll')}</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                {filtering ? <Button onClick={clearAll} type="button" variant="ghost">{t('ui.grid.clearFilters')}</Button> : null}
                {toolbarExtra}
                <p aria-live="polite" className="ml-auto text-sm text-muted-foreground" role="status">{filtering ? t('ui.grid.matches', { count: view.matched.length }) : ''}</p>
            </div>

            <div className="border border-border bg-surface">
                <Table data-testid={testId}>
                    <caption className="sr-only">{caption}</caption>
                    <TableHeader className="bg-surface-muted">
                        <TableRow className="hover:bg-transparent">
                            {visible.map((c) => {
                                const sortable = (c.sortable ?? c.value !== undefined) && c.value !== undefined;
                                const dir = state.sort?.id === c.id ? state.sort.direction : null;

                                return (
                                    <TableHead
                                        aria-sort={dir === 'asc' ? 'ascending' : dir === 'desc' ? 'descending' : sortable ? 'none' : undefined}
                                        className={cn('whitespace-nowrap', c.align === 'right' && 'text-right')}
                                        key={c.id}
                                        scope="col"
                                    >
                                        {sortable ? (
                                            <button
                                                aria-label={t('ui.grid.sortBy', { column: c.label })}
                                                className={cn('inline-flex min-h-8 items-center gap-1 font-medium hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', c.align === 'right' && 'flex-row-reverse')}
                                                onClick={() => toggleSort(c.id)}
                                                type="button"
                                            >
                                                {c.header ?? c.label}
                                                {dir === 'asc' ? <ArrowUp aria-hidden="true" className="size-3.5" /> : dir === 'desc' ? <ArrowDown aria-hidden="true" className="size-3.5" /> : <ArrowUpDown aria-hidden="true" className="size-3.5 opacity-40" />}
                                            </button>
                                        ) : (c.header ?? c.label)}
                                    </TableHead>
                                );
                            })}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {view.pageRows.map((row) => (
                            <TableRow data-testid={rowTestId?.(row)} key={getRowId(row)}>
                                {visible.map((c) => {
                                    const content = c.cell ? c.cell(row) : c.value ? String(c.value(row) ?? '') : null;

                                    return c.rowHeader
                                        ? <TableHead className={cn('font-medium text-foreground', c.className)} key={c.id} scope="row">{content}</TableHead>
                                        : <TableCell className={cn(c.align === 'right' && 'text-right tabular-nums', c.className)} key={c.id}>{content}</TableCell>;
                                })}
                            </TableRow>
                        ))}
                    </TableBody>
                    {hasFooter ? (
                        <TableFooter>
                            <TableRow className="hover:bg-transparent">
                                {visible.map((c) => (
                                    <TableCell className={cn('font-medium', c.align === 'right' && 'text-right tabular-nums')} key={c.id}>
                                        {c.footer ?? (c.id === first?.id ? (footerLabel ?? t('ui.grid.total')) : null)}
                                    </TableCell>
                                ))}
                            </TableRow>
                        </TableFooter>
                    ) : null}
                </Table>
                {view.matched.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 border-t border-border px-4 py-10 text-center">
                        <p className="font-medium">{t('ui.grid.nothing')}</p>
                        <p className="text-sm text-muted-foreground">{t('ui.grid.nothingHint')}</p>
                        <Button onClick={clearAll} size="sm" type="button" variant="outline">{t('ui.grid.clearFilters')}</Button>
                    </div>
                ) : null}
            </div>

            <nav aria-label={t('ui.pagination.navigation')} className="flex flex-wrap items-center justify-between gap-3 text-sm">
                <p className="text-muted-foreground" data-testid="grid-summary">
                    {filtering
                        ? t('ui.grid.summaryFiltered', { from: view.from, to: view.to, total: view.matched.length, all: rows.length })
                        : t('ui.grid.summary', { from: view.from, to: view.to, total: view.matched.length })}
                </p>
                <div className="flex flex-wrap items-center gap-3">
                    <label className="flex items-center gap-2 text-muted-foreground">
                        <span>{t('ui.grid.perPage')}</span>
                        <Select
                            className="min-h-10 w-20"
                            searchable={false}
                            onChange={(e) => {
                                const size = Number(e.target.value);

                                patch({ pageSize: size });
                                save(size, hidden);
                            }}
                            value={state.pageSize}
                        >
                            {PAGE_SIZES.map((n) => <option key={n} value={n}>{n}</option>)}
                        </Select>
                    </label>
                    <div className="flex items-center gap-1">
                        <Button aria-label={t('ui.grid.first')} disabled={view.page <= 1} onClick={() => patch({ page: 1 }, false)} size="icon" type="button" variant="outline"><ChevronsLeft aria-hidden="true" className="size-4" /></Button>
                        <Button aria-label={t('ui.grid.previous')} disabled={view.page <= 1} onClick={() => patch({ page: view.page - 1 }, false)} size="icon" type="button" variant="outline"><ChevronLeft aria-hidden="true" className="size-4" /></Button>
                        {pages.map((p, i) => p === 'gap'
                            ? <span aria-hidden="true" className="px-1 text-muted-foreground" key={`gap-${i}`}>…</span>
                            : (
                                <Button
                                    aria-current={p === view.page ? 'page' : undefined}
                                    aria-label={t('ui.grid.goTo', { page: p })}
                                    className="min-w-10 tabular-nums"
                                    key={p}
                                    onClick={() => patch({ page: p }, false)}
                                    size="icon"
                                    type="button"
                                    variant={p === view.page ? 'default' : 'outline'}
                                >
                                    {p}
                                </Button>
                            ))}
                        <Button aria-label={t('ui.grid.next')} disabled={view.page >= view.pages} onClick={() => patch({ page: view.page + 1 }, false)} size="icon" type="button" variant="outline"><ChevronRight aria-hidden="true" className="size-4" /></Button>
                        <Button aria-label={t('ui.grid.last')} disabled={view.page >= view.pages} onClick={() => patch({ page: view.pages }, false)} size="icon" type="button" variant="outline"><ChevronsRight aria-hidden="true" className="size-4" /></Button>
                    </div>
                </div>
            </nav>
            <p className="sr-only" role="status">{t('ui.grid.pageStatus', { page: view.page, pages: view.pages })}</p>
        </div>
    );
}

export { DataGrid };
