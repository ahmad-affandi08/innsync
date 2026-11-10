import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { FRONT_OFFICE_LINKS, MODULE_LINKS, quickActions, visibleLinks, type NavLink } from './module-links.ts'

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

    it('gives every need of the real menus as an area of two parts', () => {
        for (const list of Object.values(MODULE_LINKS)) {
            for (const l of list) {
                for (const n of l.needs ?? []) assert.match(n, /^[a-z-]+\.[a-z-]+$/, `${l.href}: ${n}`)
            }
        }
    })
})

const hrefs = (list: readonly { href: string }[]) => list.map((l) => l.href)

describe('optional pages in the menu', () => {
    it('leaves out a page whose feature is switched off and offers it when it is switched on', () => {
        const off = hrefs(visibleLinks(FRONT_OFFICE_LINKS, null, { 'day-use': false, 'gift-cards': false }))
        const on = hrefs(visibleLinks(FRONT_OFFICE_LINKS, null, { 'day-use': true, 'gift-cards': true }))

        assert.ok(!off.includes('/front-office/day-use') && !off.includes('/front-office/gift-cards'))
        assert.ok(on.includes('/front-office/day-use') && on.includes('/front-office/gift-cards'))
    })

    it('offers every page when the features could not be read', () => {
        assert.equal(visibleLinks(FRONT_OFFICE_LINKS, null, null).length, FRONT_OFFICE_LINKS.length)
        assert.equal(visibleLinks(FRONT_OFFICE_LINKS, null, undefined).length, FRONT_OFFICE_LINKS.length)
    })

    it('keeps the pages of daily work whatever the features are', () => {
        const all = { waitlist: false, groups: false, 'day-use': false, activities: false, 'gift-cards': false, commissions: false, 'door-locks': false, 'foreign-currency': false, loyalty: false }
        const shown = hrefs(visibleLinks(FRONT_OFFICE_LINKS, null, all))

        for (const core of ['/front-office/today', '/front-office/reservations', '/front-office/cashier', '/front-office/night-audit']) assert.ok(shown.includes(core), core)
    })
})

describe('the quick menu', () => {
    it('offers a fault report and a purchase request for each department the person works in', () => {
        const entries = quickActions({ accessibleModules: ['housekeeping', 'kitchen'], disabledModules: [], permissionAreas: null })

        assert.deepEqual(entries.map((e) => e.action.key), ['damage', 'purchase'])
        assert.deepEqual(hrefs(entries[0]?.targets ?? []), ['/housekeeping/damage-reports', '/kitchen/damage-reports'])
        assert.deepEqual(hrefs(entries[1]?.targets ?? []), ['/inventory/requests?department=housekeeping', '/inventory/requests?department=kitchen'])
    })

    it('leaves out a department that is not used, a purchase request without the right to make one, and an action with nothing left', () => {
        const entries = quickActions({ accessibleModules: null, disabledModules: ['kitchen', 'laundry'], permissionAreas: ['housekeeping.task'] })

        assert.deepEqual(entries.map((e) => e.action.key), ['damage'])
        assert.deepEqual(hrefs(entries[0]?.targets ?? []), ['/housekeeping/damage-reports', '/fnb/damage-reports'])
    })

    it('offers nothing before the person is known', () => {
        assert.deepEqual(quickActions(null), [])
    })
})
