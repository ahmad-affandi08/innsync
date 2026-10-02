import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { activeFilterCount, buildGridView, distinctValues, initialGridState, pageNumbers, type GridColumnModel } from './grid-model.ts'

type Row = { id: string; name: string; status: string; amount: number | null }

const rows: Row[] = Array.from({ length: 23 }, (_, i) => ({ id: `r${i + 1}`, name: `Guest ${String(i + 1).padStart(2, '0')}`, status: i % 3 === 0 ? 'open' : 'closed', amount: i === 4 ? null : (i + 1) * 100 }))
const columns: GridColumnModel<Row>[] = [
    { id: 'name', value: (r) => r.name },
    { id: 'status', value: (r) => r.status, filter: 'select' },
    { id: 'amount', value: (r) => r.amount },
]
const state = initialGridState()

describe('grid model', () => {
    it('pages at 10 by default and reports the range', () => {
        const v = buildGridView(rows, columns, state)
        assert.equal(v.pageRows.length, 10)
        assert.deepEqual([v.from, v.to, v.pages, v.matched.length], [1, 10, 3, 23])
        const last = buildGridView(rows, columns, { ...state, page: 3 })
        assert.deepEqual([last.pageRows.length, last.from, last.to], [3, 21, 23])
    })

    it('clamps a page past the end and honours 25/50/100', () => {
        assert.equal(buildGridView(rows, columns, { ...state, page: 99 }).page, 3)
        assert.equal(buildGridView(rows, columns, { ...state, pageSize: 25 }).pageRows.length, 23)
    })

    it('searches every word across columns, case-insensitively', () => {
        assert.equal(buildGridView(rows, columns, { ...state, search: 'GUEST 07' }).matched.length, 1)
        assert.equal(buildGridView(rows, columns, { ...state, search: 'open guest 04' }).matched.length, 1)
        assert.equal(buildGridView(rows, columns, { ...state, search: 'nothing' }).matched.length, 0)
    })

    it('filters by a select value and by text', () => {
        assert.equal(buildGridView(rows, columns, { ...state, filters: { status: 'open' } }).matched.length, 8)
        const text: GridColumnModel<Row>[] = [{ id: 'name', value: (r) => r.name, filter: 'text' }]
        assert.equal(buildGridView(rows, text, { ...state, filters: { name: 'guest 1' } }).matched.length, 10)
    })

    it('sorts numerically and keeps empty values last in both directions', () => {
        const asc = buildGridView(rows, columns, { ...state, pageSize: 100, sort: { id: 'amount', direction: 'asc' } }).matched
        const desc = buildGridView(rows, columns, { ...state, pageSize: 100, sort: { id: 'amount', direction: 'desc' } }).matched
        assert.equal(asc[0]?.amount, 100)
        assert.equal(asc.at(-1)?.amount, null)
        assert.equal(desc[0]?.amount, 2300)
        assert.equal(desc.at(-1)?.amount, null)
    })

    it('lists distinct values and counts active filters', () => {
        assert.deepEqual(distinctValues(rows, columns[1]!), ['closed', 'open'])
        assert.equal(activeFilterCount({ a: 'x', b: '' }), 1)
    })

    it('builds page numbers with gaps', () => {
        assert.deepEqual(pageNumbers(1, 3), [1, 2, 3])
        assert.deepEqual(pageNumbers(1, 10), [1, 2, 'gap', 10])
        assert.deepEqual(pageNumbers(5, 10), [1, 'gap', 4, 5, 6, 'gap', 10])
        assert.deepEqual(pageNumbers(1, 1), [1])
    })
})
