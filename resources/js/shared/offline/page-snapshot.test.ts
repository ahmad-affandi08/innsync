import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'

import { createAesGcmCipher, generateDeviceKey } from './crypto.ts'
import { isOfflinePage, OFFLINE_PAGES, openSnapshot, pathOf, SNAPSHOT_MAX_AGE_MS, saveSnapshot, type InertiaPageLike, type SnapshotRecord, type SnapshotStore } from './page-snapshot.ts'

function memory(): SnapshotStore & { records: Map<string, SnapshotRecord> } {
    const records = new Map<string, SnapshotRecord>()

    return {
        records,
        put: async (r) => void records.set(r.path, r),
        get: async (p) => records.get(p) ?? null,
        clear: async () => records.clear(),
    }
}

const page = (url: string, userId = 'u1'): InertiaPageLike => ({ component: 'fnb-sales/pages/register', url, version: 'v1', props: { auth: { userId, propertyId: 'p1' }, register: { menu: ['soup'] }, errors: { x: 'old' }, flash: { ok: 'saved' } } })

async function deps() {
    return { store: memory(), cipher: createAesGcmCipher(await generateDeviceKey()) }
}

describe('page snapshot', () => {
    it('keeps only the offline pages, with the query string ignored', async () => {
        const d = await deps()

        assert.equal(await saveSnapshot(d, page('/fnb/pos'), 1000), false)
        assert.equal(await saveSnapshot(d, page('/fnb/register?outlet=1'), 1000), true)
        assert.equal(pathOf('/fnb/register?outlet=1#x'), '/fnb/register')
        assert.equal(isOfflinePage('/housekeeping/my-rooms'), true)
        assert.equal(isOfflinePage('/fnb/register/extra'), false)
    })

    it('is not stored in the clear, and comes back without flash messages or old errors', async () => {
        const d = await deps()
        await saveSnapshot(d, page('/fnb/register'), 1000)

        const record = d.store.records.get('/fnb/register')
        assert.ok(record !== undefined)
        assert.equal(new TextDecoder().decode((record as SnapshotRecord).box.data).includes('soup'), false)

        const opened = await openSnapshot(d, '/fnb/register', 2000)
        assert.deepEqual((opened?.page.props.register as { menu: string[] }).menu, ['soup'])
        assert.equal('errors' in (opened?.page.props ?? {}), false)
        assert.equal('flash' in (opened?.page.props ?? {}), false)
        assert.equal(opened?.savedAt, 1000)
    })

    it('does not keep a page for nobody, and does not open one that is too old, from the future, or sealed for someone else', async () => {
        const d = await deps()
        assert.equal(await saveSnapshot(d, { ...page('/fnb/register'), props: { auth: null } }, 1000), false)

        await saveSnapshot(d, page('/fnb/register'), 1000)
        assert.equal(await openSnapshot(d, '/fnb/register', 1000 + SNAPSHOT_MAX_AGE_MS + 1), null)
        assert.equal(await openSnapshot(d, '/fnb/register', 1000 - 120_000), null)
        assert.equal(await openSnapshot(d, '/housekeeping/my-rooms', 2000), null)

        const record = d.store.records.get('/fnb/register') as SnapshotRecord
        d.store.records.set('/fnb/register', { ...record, userId: 'someone-else' })
        assert.equal(await openSnapshot(d, '/fnb/register', 2000), null, 'the ciphertext is bound to the person it was made for')

        const other = { store: d.store, cipher: createAesGcmCipher(await generateDeviceKey()) }
        d.store.records.set('/fnb/register', record)
        assert.equal(await openSnapshot(other, '/fnb/register', 2000), null, 'another key cannot read it')
    })

    it('uses the same list of pages as the service worker', () => {
        const worker = readFileSync(new URL('../../../../public/sw.js', import.meta.url), 'utf8')
        const listed = /const OFFLINE_PAGES = \[(.*?)\];/.exec(worker)?.[1]
        assert.ok(listed !== undefined)
        assert.deepEqual([...(listed as string).matchAll(/'([^']+)'/g)].map((m) => m[1]), [...OFFLINE_PAGES])
    })
})
