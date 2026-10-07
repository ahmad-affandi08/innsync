import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { parsePeople } from './people-list.ts'

describe('parsePeople', () => {
    it('reads names and emails separated by comma, semicolon or tab', () => {
        const r = parsePeople('Sari Wulandari, sari@hotel.id\nBudi;budi@hotel.id\nRina\trina@hotel.id')

        assert.deepEqual(r.people, [
            { name: 'Sari Wulandari', email: 'sari@hotel.id' },
            { name: 'Budi', email: 'budi@hotel.id' },
            { name: 'Rina', email: 'rina@hotel.id' },
        ])
        assert.deepEqual(r.invalid, [])
    })

    it('reads the "Name <email>" form and an email alone', () => {
        const r = parsePeople('Dewi Lestari <dewi@hotel.id>\nagus@hotel.id')

        assert.deepEqual(r.people, [{ name: 'Dewi Lestari', email: 'dewi@hotel.id' }, { name: 'agus', email: 'agus@hotel.id' }])
    })

    it('reports a line without an email and keeps a repeated email once', () => {
        const r = parsePeople('\nTono\nSari, sari@hotel.id\nSARI again, SARI@hotel.id\n')

        assert.deepEqual(r.invalid, ['Tono'])
        assert.equal(r.people.length, 1)
    })
})
