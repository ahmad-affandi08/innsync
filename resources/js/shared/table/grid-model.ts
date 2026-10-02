/**
 * Client-side table model for lists a screen already holds in full
 * (docs/DESIGN/06-DATA-TABLES.md: server paging stays the rule for unbounded
 * data). Pure functions: search, per-column filters, sort and paging.
 */
export type GridValue = string | number | null | undefined

export const PAGE_SIZES = [10, 25, 50, 100] as const
export const DEFAULT_PAGE_SIZE = 10

export type GridSort = { id: string; direction: 'asc' | 'desc' }

export type GridState = {
    search: string
    /** Column id → chosen value (select filter) or typed text (text filter). */
    filters: Readonly<Record<string, string>>
    sort: GridSort | null
    page: number
    pageSize: number
}

export type GridColumnModel<T> = {
    id: string
    /** Value used to search, sort and filter; a column without it does none of them. */
    value?: (row: T) => GridValue
    /** Text the search matches when it differs from `value` (e.g. a formatted amount). */
    searchText?: (row: T) => string
    filter?: 'select' | 'text'
    sortable?: boolean
}

export const initialGridState = (pageSize = DEFAULT_PAGE_SIZE): GridState => ({ search: '', filters: {}, sort: null, page: 1, pageSize })

const norm = (v: unknown): string => String(v ?? '').toLocaleLowerCase()

export function matchesSearch<T>(row: T, columns: readonly GridColumnModel<T>[], search: string): boolean {
    const needle = norm(search).trim()

    if (needle === '') return true

    return needle.split(/\s+/).every((word) => columns.some((c) => {
        const text = c.searchText ? c.searchText(row) : c.value ? c.value(row) : null

        return text !== null && text !== undefined && norm(text).includes(word)
    }))
}

export function matchesFilters<T>(row: T, columns: readonly GridColumnModel<T>[], filters: Readonly<Record<string, string>>): boolean {
    return columns.every((c) => {
        const wanted = filters[c.id]

        if (wanted === undefined || wanted === '' || !c.filter || !c.value) return true

        const actual = c.value(row)

        return c.filter === 'select' ? String(actual ?? '') === wanted : norm(actual).includes(norm(wanted).trim())
    })
}

export function compareValues(a: GridValue, b: GridValue): number {
    const aEmpty = a === null || a === undefined || a === ''
    const bEmpty = b === null || b === undefined || b === ''

    if (aEmpty || bEmpty) return aEmpty === bEmpty ? 0 : aEmpty ? 1 : -1
    if (typeof a === 'number' && typeof b === 'number') return a - b

    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' })
}

export function sortRows<T>(rows: readonly T[], column: GridColumnModel<T> | undefined, sort: GridSort | null): T[] {
    if (sort === null || column?.value === undefined) return [...rows]

    const value = column.value
    const factor = sort.direction === 'asc' ? 1 : -1

    // Array.prototype.sort is stable, so equal rows keep the server's order.
    return [...rows].sort((x, y) => {
        const a = value(x)
        const b = value(y)
        const empty = (v: GridValue) => v === null || v === undefined || v === ''

        // Empty values sink to the end in either direction.
        if (empty(a) || empty(b)) return compareValues(a, b)

        return factor * compareValues(a, b)
    })
}

export const lastPageOf = (total: number, pageSize: number): number => Math.max(1, Math.ceil(total / Math.max(1, pageSize)))

export type GridView<T> = {
    /** Rows matching search and filters, sorted. */
    matched: T[]
    /** The rows of the current page. */
    pageRows: T[]
    page: number
    pages: number
    from: number
    to: number
}

export function buildGridView<T>(rows: readonly T[], columns: readonly GridColumnModel<T>[], state: GridState): GridView<T> {
    const filtered = rows.filter((r) => matchesSearch(r, columns, state.search) && matchesFilters(r, columns, state.filters))
    const matched = sortRows(filtered, columns.find((c) => c.id === state.sort?.id), state.sort)
    const pages = lastPageOf(matched.length, state.pageSize)
    const page = Math.min(Math.max(1, state.page), pages)
    const start = (page - 1) * state.pageSize
    const pageRows = matched.slice(start, start + state.pageSize)

    return { matched, pageRows, page, pages, from: matched.length === 0 ? 0 : start + 1, to: start + pageRows.length }
}

export function distinctValues<T>(rows: readonly T[], column: GridColumnModel<T>): string[] {
    if (!column.value) return []

    const seen = new Set<string>()

    for (const row of rows) {
        const v = column.value(row)

        if (v !== null && v !== undefined && v !== '') seen.add(String(v))
    }

    return [...seen].sort((a, b) => compareValues(a, b))
}

/** Page buttons: first, last, the current page and its neighbours, with gaps as `'gap'`. */
export function pageNumbers(page: number, pages: number): (number | 'gap')[] {
    const wanted = new Set([1, pages, page - 1, page, page + 1])
    const list = [...wanted].filter((n) => n >= 1 && n <= pages).sort((a, b) => a - b)
    const out: (number | 'gap')[] = []

    list.forEach((n, i) => {
        const before = list[i - 1]

        if (before !== undefined && n - before > 1) out.push(n - before === 2 ? n - 1 : 'gap')

        out.push(n)
    })

    return out
}

export const activeFilterCount = (filters: Readonly<Record<string, string>>): number => Object.values(filters).filter((v) => v !== '').length
