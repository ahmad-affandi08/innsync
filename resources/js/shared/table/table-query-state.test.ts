import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import {
    clearFilters,
    defaultTableQuery,
    hasActiveFilters,
    lastPage,
    MAX_FILTER_LENGTH,
    MAX_PAGE,
    parseTableQuery,
    serializeTableQuery,
    toRequestParams,
    withFilter,
    withPage,
    withPerPage,
    withSort,
    type TableQueryConfig,
} from './table-query-state.ts'

const config: TableQueryConfig = {
    perPageOptions: [10, 25, 50],
    defaultPerPage: 25,
    sortableColumns: ['created_at', 'name'],
    defaultSort: { id: 'created_at', direction: 'desc' },
    filterKeys: ['status', 'q'],
}

describe('parseTableQuery', () => {
    it('returns defaults for an empty query', () => {
        assert.deepEqual(parseTableQuery('', config), defaultTableQuery(config))
    })

    it('reads page, size, sort and filters', () => {
        const state = parseTableQuery('page=3&per_page=50&sort=name&filter[status]=open&filter[q]=%20ana%20', config)

        assert.deepEqual(state, {
            page: 3,
            perPage: 50,
            sort: { id: 'name', direction: 'asc' },
            filters: { status: 'open', q: 'ana' },
        })
    })

    it('treats a leading minus as descending', () => {
        assert.deepEqual(parseTableQuery('sort=-name', config).sort, { id: 'name', direction: 'desc' })
    })

    it('rejects hostile input instead of passing it to the server', () => {
        const state = parseTableQuery(
            `page=-1&per_page=100000&sort=password_hash&filter[evil]=1&filter[q]=${'x'.repeat(500)}`,
            config,
        )

        assert.equal(state.page, 1)
        assert.equal(state.perPage, 25)
        assert.deepEqual(state.sort, config.defaultSort)
        assert.deepEqual(Object.keys(state.filters), ['q'])
        assert.equal(state.filters.q?.length, MAX_FILTER_LENGTH)
    })

    it('rejects non-numeric and fractional pages and caps absurd ones', () => {
        assert.equal(parseTableQuery('page=abc', config).page, 1)
        assert.equal(parseTableQuery('page=1.5', config).page, 1)
        assert.equal(parseTableQuery('page=0', config).page, 1)
        assert.equal(parseTableQuery('page=99999999999999999999', config).page, 1)
        assert.equal(parseTableQuery('page=999999999', config).page, MAX_PAGE)
    })
})

describe('serializeTableQuery', () => {
    it('omits defaults so links are canonical', () => {
        assert.equal(serializeTableQuery(defaultTableQuery(config), config).toString(), '')
    })

    it('round-trips a non-default state', () => {
        const state = {
            page: 2,
            perPage: 10,
            sort: { id: 'name', direction: 'desc' as const },
            filters: { status: 'open' },
        }
        const search = serializeTableQuery(state, config).toString()

        assert.deepEqual(parseTableQuery(search, config), state)
    })
})

describe('transitions', () => {
    const base = { ...defaultTableQuery(config), page: 4 }

    it('returns to page 1 when filters, sort or size change', () => {
        assert.equal(withFilter(base, 'status', 'open', config).page, 1)
        assert.equal(withSort(base, { id: 'name', direction: 'asc' }, config).page, 1)
        assert.equal(withPerPage(base, 50, config).page, 1)
        assert.equal(clearFilters(base).page, 1)
    })

    it('ignores undeclared columns, filters and page sizes', () => {
        assert.equal(withSort(base, { id: 'nope', direction: 'asc' }, config), base)
        assert.equal(withFilter(base, 'nope', 'x', config), base)
        assert.equal(withPerPage(base, 9999, config), base)
    })

    it('removes a filter when its value is blank', () => {
        const filtered = withFilter(base, 'q', 'ana', config)

        assert.equal(hasActiveFilters(filtered), true)
        assert.equal(hasActiveFilters(withFilter(filtered, 'q', '   ', config)), false)
    })

    it('clamps page navigation', () => {
        assert.equal(withPage(base, 0).page, 1)
        assert.equal(withPage(base, Number.NaN).page, 1)
        assert.equal(withPage(base, 7.9).page, 7)
    })

    it('computes the last page for a server total', () => {
        assert.equal(lastPage(0, 25), 1)
        assert.equal(lastPage(25, 25), 1)
        assert.equal(lastPage(26, 25), 2)
    })
})

describe('toRequestParams', () => {
    it('always sends explicit page, size, sort and filter keys', () => {
        assert.deepEqual(
            toRequestParams({
                page: 2,
                perPage: 10,
                sort: { id: 'name', direction: 'desc' },
                filters: { status: 'open' },
            }),
            { page: 2, per_page: 10, sort: '-name', 'filter[status]': 'open' },
        )
    })
})
