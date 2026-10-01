/**
 * URL <-> table query state (TASK-FND-012, docs/DESIGN/06-DATA-TABLES.md).
 *
 * The URL is the shareable source of truth for page, page size, sort and
 * filters. Everything read from the URL is untrusted: it is clamped to the
 * table's declared allow-lists so a crafted link cannot request an unbounded
 * page size or sort by an undeclared column. The server must still validate
 * and authorize every parameter.
 */
export type SortDirection = 'asc' | 'desc'

export interface TableSort {
    id: string
    direction: SortDirection
}

export interface TableQueryState {
    /** 1-based page number. */
    page: number
    perPage: number
    sort: TableSort | null
    filters: Readonly<Record<string, string>>
}

export interface TableQueryConfig {
    perPageOptions: readonly number[]
    defaultPerPage: number
    /** Column ids the server accepts for ordering. */
    sortableColumns: readonly string[]
    defaultSort?: TableSort | null
    /** Filter keys the screen understands; anything else in the URL is dropped. */
    filterKeys: readonly string[]
}

/** Longest filter value kept from a URL; protects the server from pathological links. */
export const MAX_FILTER_LENGTH = 200

/** Upper bound for the page number read from a URL. */
export const MAX_PAGE = 100_000

export function defaultTableQuery(config: TableQueryConfig): TableQueryState {
    return {
        page: 1,
        perPage: config.defaultPerPage,
        sort: config.defaultSort ?? null,
        filters: {},
    }
}

function parsePositiveInt(value: string | null): number | null {
    if (value === null || !/^\d+$/.test(value)) {
        return null
    }

    const parsed = Number.parseInt(value, 10)

    return Number.isSafeInteger(parsed) && parsed >= 1 ? parsed : null
}

function parseSort(value: string | null, config: TableQueryConfig): TableSort | null {
    if (value === null || value === '') {
        return config.defaultSort ?? null
    }

    const direction: SortDirection = value.startsWith('-') ? 'desc' : 'asc'
    const id = value.replace(/^[-+]/, '')

    if (!config.sortableColumns.includes(id)) {
        return config.defaultSort ?? null
    }

    return { id, direction }
}

export function parseTableQuery(
    search: string | URLSearchParams,
    config: TableQueryConfig,
): TableQueryState {
    const params = typeof search === 'string' ? new URLSearchParams(search) : search

    const requestedPerPage = parsePositiveInt(params.get('per_page'))
    const perPage =
        requestedPerPage !== null && config.perPageOptions.includes(requestedPerPage)
            ? requestedPerPage
            : config.defaultPerPage

    const page = Math.min(parsePositiveInt(params.get('page')) ?? 1, MAX_PAGE)

    const filters: Record<string, string> = {}

    for (const key of config.filterKeys) {
        const raw = params.get(`filter[${key}]`)?.trim()

        if (raw) {
            filters[key] = raw.slice(0, MAX_FILTER_LENGTH)
        }
    }

    return { page, perPage, sort: parseSort(params.get('sort'), config), filters }
}

function sameSort(a: TableSort | null, b: TableSort | null): boolean {
    return a?.id === b?.id && a?.direction === b?.direction
}

/** Omits values equal to the defaults so shared links stay short and canonical. */
export function serializeTableQuery(
    state: TableQueryState,
    config: TableQueryConfig,
): URLSearchParams {
    const params = new URLSearchParams()

    if (state.page > 1) {
        params.set('page', String(state.page))
    }

    if (state.perPage !== config.defaultPerPage) {
        params.set('per_page', String(state.perPage))
    }

    if (!sameSort(state.sort, config.defaultSort ?? null) && state.sort !== null) {
        params.set('sort', `${state.sort.direction === 'desc' ? '-' : ''}${state.sort.id}`)
    }

    for (const key of config.filterKeys) {
        const value = state.filters[key]

        if (value) {
            params.set(`filter[${key}]`, value)
        }
    }

    return params
}

/** Parameters for the server request; always explicit so the contract is visible in network logs. */
export function toRequestParams(state: TableQueryState): Record<string, string | number> {
    const params: Record<string, string | number> = {
        page: state.page,
        per_page: state.perPage,
    }

    if (state.sort !== null) {
        params.sort = `${state.sort.direction === 'desc' ? '-' : ''}${state.sort.id}`
    }

    for (const [key, value] of Object.entries(state.filters)) {
        params[`filter[${key}]`] = value
    }

    return params
}

/** Changing the page size or sort keeps context but returns to the first page. */
export function withPage(state: TableQueryState, page: number): TableQueryState {
    return { ...state, page: Math.max(1, Math.min(Math.trunc(page) || 1, MAX_PAGE)) }
}

export function withPerPage(
    state: TableQueryState,
    perPage: number,
    config: TableQueryConfig,
): TableQueryState {
    return config.perPageOptions.includes(perPage) ? { ...state, perPage, page: 1 } : state
}

export function withSort(
    state: TableQueryState,
    sort: TableSort | null,
    config: TableQueryConfig,
): TableQueryState {
    if (sort !== null && !config.sortableColumns.includes(sort.id)) {
        return state
    }

    return { ...state, sort, page: 1 }
}

/** An empty value removes the filter. A filter change always returns to page 1. */
export function withFilter(
    state: TableQueryState,
    key: string,
    value: string,
    config: TableQueryConfig,
): TableQueryState {
    if (!config.filterKeys.includes(key)) {
        return state
    }

    const filters = { ...state.filters }
    const trimmed = value.trim().slice(0, MAX_FILTER_LENGTH)

    if (trimmed === '') {
        delete filters[key]
    } else {
        filters[key] = trimmed
    }

    return { ...state, filters, page: 1 }
}

export function clearFilters(state: TableQueryState): TableQueryState {
    return { ...state, filters: {}, page: 1 }
}

export function hasActiveFilters(state: TableQueryState): boolean {
    return Object.keys(state.filters).length > 0
}

/** Highest page that can exist for a server total; used to recover from a stale deep link. */
export function lastPage(rowCount: number, perPage: number): number {
    return Math.max(1, Math.ceil(rowCount / perPage))
}
