import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { groupHeading, MODULE_LINKS, visibleLinks, type NavLink } from './module-links.ts'

const links: NavLink[] = [
    { href: '/a', label: 'a' },
    { href: '/b', label: 'b', needs: ['finance.payable'], group: 'g1' },
    { href: '/c', label: 'c', needs: ['finance.bank', 'finance.tax'], group: 'g1' },
    { href: '/d', label: 'd', needs: ['loyalty.x'], group: 'g2', feature: 'loyalty' },
]

describe('menu pages by permission area', () => {
    it('offers every page when the areas could not be read', () => {
        assert.equal(visibleLinks(links, null).length, 4)
        assert.equal(visibleLinks(links, undefined).length, 4)
    })

    it('keeps a page without a need and a page with any one of its areas, and drops the rest', () => {
        assert.deepEqual(visibleLinks(links, ['finance.tax']).map((l) => l.href), ['/a', '/c'])
        assert.deepEqual(visibleLinks(links, []).map((l) => l.href), ['/a'])
    })

    it('leaves out the page of a feature that is switched off, whatever the person holds', () => {
        assert.deepEqual(visibleLinks(links, ['loyalty.x'], { loyalty: false }).map((l) => l.href), ['/a'])
        assert.deepEqual(visibleLinks(links, ['loyalty.x'], { loyalty: true }).map((l) => l.href), ['/a', '/d'])
    })

    it('shows a group heading only above the first page of the group in what is left', () => {
        const left = visibleLinks(links, ['finance.tax', 'finance.payable', 'loyalty.x'])

        assert.deepEqual(left.map((_, i) => groupHeading(left, i)), [undefined, 'g1', undefined, 'g2'])
    })

    it('gives every need of the real menus as an area of two parts', () => {
        for (const list of Object.values(MODULE_LINKS)) {
            for (const l of list) {
                for (const n of l.needs ?? []) assert.match(n, /^[a-z-]+\.[a-z-]+$/, `${l.href}: ${n}`)
            }
        }
    })
})
