import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { ACTOR_A, ACTOR_B, harness, PROPERTY } from './engine-support.ts'
import { ForbiddenPayloadError, NoSessionError } from './sync-engine.ts'
import { ULID_PATTERN } from './ulid.ts'

const sale = (mode = 'ok') => ({ type: 'test.sale', payload: { mode, amount: 25000 } })

describe('recording', () => {
    it('queues an encrypted payload with a valid client-generated operation id and a monotonic sequence', async () => {
        const { engine, store } = await harness()

        const a = await engine.enqueue(sale())
        const b = await engine.enqueue(sale())

        assert.match(a.operationId, ULID_PATTERN)
        assert.notEqual(a.operationId, b.operationId)
        assert.equal(b.clientSequence, a.clientSequence + 1)
        assert.equal(a.state, 'pending')

        const stored = await store.get(a.operationId)
        const raw = new TextDecoder().decode(stored?.box?.data)

        assert.doesNotMatch(raw, /25000|amount|test\.sale/, 'the payload is not readable at rest')
        assert.equal(JSON.stringify({ ...stored, box: null }).includes('25000'), false, 'metadata holds no payload')
    })

    it('refuses to record without a signed-in user and refuses card data, identity documents and secrets', async () => {
        const h = await harness()

        h.session.current = null
        await assert.rejects(() => h.engine.enqueue(sale()), NoSessionError)

        h.session.current = { actorId: ACTOR_A, propertyId: PROPERTY }
        await assert.rejects(() => h.engine.enqueue({ type: 'x.y.z', payload: { card_number: '4111' } }), ForbiddenPayloadError)
        await assert.rejects(() => h.engine.enqueue({ type: 'x.y.z', payload: { guest: { Passport_Number: 'X' } } }), ForbiddenPayloadError)
        assert.equal((await h.store.list()).length, 0, 'nothing sensitive was queued')
    })
})

describe('interoperability with the server', () => {
    it('matches results to entries although the server returns operation ids in lower case', async () => {
        const h = await harness()
        const entry = await h.engine.enqueue(sale())

        assert.match(entry.operationId, /[A-Z]/, 'the client generates upper-case ULIDs')
        await h.engine.flush()

        const sent = h.server.batches[0]?.items[0]?.operation_id
        assert.equal(sent, entry.operationId)
        assert.ok([...h.server.applied.keys()].every((id) => id === id.toLowerCase()), 'the fake server answers in lower case, like the real one')
        assert.equal((await h.store.get(entry.operationId))?.state, 'accepted', 'and the entry is still resolved')
        assert.equal((await h.engine.snapshot()).pending, 0)
    })
})

describe('synchronizing', () => {
    it('keeps everything while offline, counts no attempts, and syncs after recovery', async () => {
        const h = await harness()
        await h.engine.enqueue(sale())
        await h.engine.enqueue(sale())

        h.server.faults.offline = true
        await h.engine.flush()

        let snap = await h.engine.snapshot()
        assert.equal(snap.pending, 2)
        assert.equal(snap.networkDown, true)
        assert.ok((await h.store.list()).every((e) => e.attempts === 0), 'a lost network is not the entry\'s fault')
        assert.equal(h.server.effects, 0)

        // Not due yet: nothing is sent until the backoff passes.
        h.server.faults.offline = false
        const deliveries = () => h.server.batches.filter((batch) => batch.items.length > 0).length
        const sentBefore = deliveries()
        await h.engine.flush()
        assert.equal(deliveries(), sentBefore, 'no delivery before the backoff passes (an empty heartbeat is not a delivery)')

        h.clock.now += 600_000
        await h.engine.flush()
        snap = await h.engine.snapshot()
        assert.equal(snap.pending, 0)
        assert.equal(snap.accepted, 2)
        assert.equal(snap.networkDown, false)
        assert.equal(h.server.effects, 2)
        assert.ok((await h.store.list()).every((e) => e.box === null), 'an accepted entry wipes its payload')
    })

    it('survives hours offline with a large queue, in order, with one effect each', async () => {
        const h = await harness({ batchSize: 10 })

        for (let i = 0; i < 120; i++) {
            await h.engine.enqueue({ type: 'test.sale', payload: { mode: 'ok', n: i } })
        }

        h.server.faults.offline = true
        for (let hour = 0; hour < 5; hour++) {
            h.clock.now += 3_600_000
            await h.engine.flush()
        }

        assert.equal((await h.engine.snapshot()).pending, 120, 'nothing is lost or given up after 5 hours')
        assert.ok((await h.store.list()).every((e) => e.attempts === 0 && e.state === 'pending'))

        h.server.faults.offline = false
        h.clock.now += 3_600_000
        await h.engine.flush()

        assert.equal(h.server.effects, 120)
        // The order in which the server first applied each operation is the device's own order.
        const sequenceOf = new Map(h.server.batches.flatMap((b) => b.items.map((i) => [i.operation_id, i.client_sequence] as const)))
        const appliedOrder = [...h.server.applied.keys()].map((id) => sequenceOf.get(id) as number)

        assert.equal(appliedOrder.length, 120)
        assert.deepEqual(appliedOrder, [...appliedOrder].sort((a, b) => a - b), 'applied strictly in the device\'s own order')
    })

    it('resending after a lost response applies the effect exactly once', async () => {
        const h = await harness()
        await h.engine.enqueue(sale())

        // The server applies the sale but the device never hears back.
        h.server.faults.loseResponses = 1
        await h.engine.flush()
        assert.equal(h.server.effects, 1)
        assert.equal((await h.engine.snapshot()).pending, 1, 'the device still believes it is unsent')

        h.clock.now += 600_000
        await h.engine.flush()

        assert.equal(h.server.effects, 1, 'the duplicate delivery did not apply twice')
        const [entry] = await h.store.list()
        assert.equal(entry?.state, 'accepted')
    })

    it('returns an in-flight entry to the queue after a crash, and resending is safe', async () => {
        const h = await harness()
        const entry = await h.engine.enqueue(sale())
        await h.store.put({ ...entry, state: 'sending' })

        await h.engine.recoverInterrupted()

        assert.equal((await h.store.get(entry.operationId))?.state, 'pending')
        await h.engine.flush()
        assert.equal(h.server.effects, 1)
    })

    it('shares one run between concurrent flushes', async () => {
        const h = await harness()
        await h.engine.enqueue(sale())

        await Promise.all([h.engine.flush(), h.engine.flush(), h.engine.flush()])

        assert.equal(h.server.batches.length, 1)
        assert.equal(h.server.effects, 1)
    })
})

describe('what the server could not apply is never discarded', () => {
    it('keeps a conflict with its payload and action until a person removes it', async () => {
        const h = await harness()
        const entry = await h.engine.enqueue(sale('conflict'))

        await h.engine.flush()

        const stored = await h.store.get(entry.operationId)
        assert.equal(stored?.state, 'conflict')
        assert.equal(stored?.lastCode, 'stale_state')
        assert.equal(stored?.lastAction, 'review')
        assert.equal(stored?.serverVersion, 7)
        assert.deepEqual(await h.engine.reveal(entry.operationId), { mode: 'conflict', amount: 25000 }, 'the person can still see what was recorded')
        assert.equal((await h.engine.snapshot()).attention, 1)

        // Time does not make it go away, and a purge only touches accepted receipts.
        h.clock.now += 30 * 86_400_000
        await h.engine.purge()
        await h.engine.flush()
        assert.equal((await h.store.get(entry.operationId))?.state, 'conflict')

        assert.equal(await h.engine.remove(entry.operationId), true)
        assert.equal(await h.store.get(entry.operationId), null)
    })

    it('keeps a rejection the same way', async () => {
        const h = await harness()
        const entry = await h.engine.enqueue(sale('reject'))

        await h.engine.flush()

        assert.equal((await h.store.get(entry.operationId))?.state, 'rejected')
        assert.equal((await h.store.get(entry.operationId))?.lastCode, 'closed_shift')
        assert.notEqual(await h.engine.reveal(entry.operationId), null)
    })

    it('refuses to remove entries that still hold unsent work', async () => {
        const h = await harness()
        const pending = await h.engine.enqueue(sale())
        const stalled = await h.engine.enqueue(sale())
        await h.store.put({ ...(await h.store.get(stalled.operationId))!, state: 'stalled' })

        assert.equal(await h.engine.remove(pending.operationId), false)
        assert.equal(await h.engine.remove(stalled.operationId), false)
        assert.equal(await h.engine.remove('01ARZ3NDEKTSV4RRFFQ69G5FZZ'), false)
        assert.equal((await h.store.list()).length, 2)
    })

    it('counts transient server failures, backs off, stalls at the bound, and a person can retry', async () => {
        const h = await harness({ maxAttempts: 3 })
        const entry = await h.engine.enqueue(sale('later'))

        await h.engine.flush()
        let stored = await h.store.get(entry.operationId)
        assert.equal(stored?.attempts, 1)
        assert.ok((stored?.nextAttemptAt ?? 0) > h.clock.now, 'delayed before the next attempt')

        for (let i = 0; i < 2; i++) {
            h.clock.now += 400_000
            await h.engine.flush()
        }

        stored = await h.store.get(entry.operationId)
        assert.equal(stored?.state, 'stalled')
        assert.equal(stored?.attempts, 3)
        assert.notEqual(await h.engine.reveal(entry.operationId), null, 'a stalled entry keeps its payload')

        h.server.transient = false
        await h.engine.retry(entry.operationId)
        await h.engine.flush()

        assert.equal((await h.store.get(entry.operationId))?.state, 'accepted')
        assert.equal(h.server.effects, 1)
    })

    it('does not let a later entry overtake an earlier one that failed transiently', async () => {
        const h = await harness()
        const first = await h.engine.enqueue(sale('later'))
        const second = await h.engine.enqueue(sale('ok'))

        await h.engine.flush()

        assert.equal((await h.store.get(first.operationId))?.state, 'pending')
        assert.equal((await h.store.get(second.operationId))?.state, 'pending', 'deferred, not applied')
        assert.equal((await h.store.get(second.operationId))?.attempts, 0, 'a deferred entry is not penalized')
        assert.equal(h.server.effects, 0)
    })

    it('never lets a later entry overtake an earlier one that is waiting, in flight, or stalled', async () => {
        // Batch size 1: the earlier entry fails alone, and the next batch would otherwise carry the later one.
        const h = await harness({ batchSize: 1, maxAttempts: 2 })
        const first = await h.engine.enqueue(sale('later'))
        const second = await h.engine.enqueue(sale('ok'))

        await h.engine.flush()
        assert.equal(h.server.effects, 0, 'the later entry was not sent behind a failed earlier one')

        // Still inside the backoff of the first entry: the second must keep waiting, however often we flush.
        h.clock.now += 500
        await h.engine.flush()
        await h.engine.flush()
        assert.equal(h.server.effects, 0)
        assert.equal((await h.store.get(second.operationId))?.state, 'pending')

        // Backoff over: the first is tried again, still failing, and now stalls.
        h.clock.now += 400_000
        await h.engine.flush()
        assert.equal((await h.store.get(first.operationId))?.state, 'stalled')
        h.clock.now += 400_000
        await h.engine.flush()
        assert.equal(h.server.effects, 0, 'a stalled earlier entry holds up the line until a person retries it')
        assert.equal((await h.engine.snapshot()).attention, 1, 'and that is visible')

        h.server.transient = false
        await h.engine.retry(first.operationId)
        await h.engine.flush()
        assert.equal(h.server.effects, 2)
        const order = [...h.server.applied.keys()]
        assert.deepEqual(order, [first.operationId.toLowerCase(), second.operationId.toLowerCase()], 'applied in recorded order')
    })

    it('lets entries behind a finished (conflict or rejected) entry proceed', async () => {
        const h = await harness({ batchSize: 1 })
        const conflict = await h.engine.enqueue(sale('conflict'))
        const next = await h.engine.enqueue(sale('ok'))

        await h.engine.flush()

        assert.equal((await h.store.get(conflict.operationId))?.state, 'conflict')
        assert.equal((await h.store.get(next.operationId))?.state, 'accepted')
    })

    it('marks an entry it can no longer decrypt as rejected instead of dropping it', async () => {
        const h = await harness()
        const entry = await h.engine.enqueue(sale())
        const stored = (await h.store.get(entry.operationId))!
        stored.box!.data[0] = (stored.box!.data[0]! ^ 0xff) & 0xff
        await h.store.put(stored)

        await h.engine.flush()

        const after = await h.store.get(entry.operationId)
        assert.equal(after?.state, 'rejected')
        assert.equal(after?.lastCode, 'local_decrypt_failed')
        assert.equal(h.server.batches.length, 0, 'garbled data is never sent')
    })
})

describe('session and scope', () => {
    it('pauses on an expired session without touching the queue, and resumes after sign-in', async () => {
        const h = await harness()
        await h.engine.enqueue(sale())

        h.server.faults.httpStatus = 419
        await h.engine.flush()

        let snap = await h.engine.snapshot()
        assert.equal(snap.blocked, 'sign-in')
        assert.equal(snap.pending, 1)
        assert.equal((await h.store.list())[0]?.attempts, 0)

        h.server.faults.httpStatus = null
        await h.engine.flush()
        snap = await h.engine.snapshot()
        assert.equal(snap.blocked, null)
        assert.equal(snap.accepted, 1)
    })

    it('treats a missing property selection as a pause, not a failure', async () => {
        const h = await harness()
        await h.engine.enqueue(sale())
        h.server.faults.httpStatus = 403

        await h.engine.flush()

        assert.equal((await h.engine.snapshot()).blocked, 'property')
        assert.equal((await h.engine.snapshot()).pending, 1)
    })

    it('sends only the signed-in user\'s entries in the active property, and reports the others', async () => {
        const h = await harness()
        await h.engine.enqueue(sale())

        h.session.current = { actorId: ACTOR_B, propertyId: PROPERTY }
        await h.engine.enqueue(sale())
        await h.engine.flush()

        assert.equal(h.server.batches.flatMap((b) => b.items).every((i) => i.actor_id === ACTOR_B), true)
        let snap = await h.engine.snapshot()
        assert.equal(snap.otherActors, 1, 'cashier A\'s item waits for cashier A')
        assert.equal(snap.accepted, 1)

        h.session.current = { actorId: ACTOR_A, propertyId: PROPERTY }
        await h.engine.flush()
        snap = await h.engine.snapshot()
        assert.equal(snap.otherActors, 0)
        assert.equal(h.server.effects, 2)

        h.session.current = { actorId: ACTOR_A, propertyId: '01ARZ3NDEKTSV4RRFFQ69G5FAW' }
        assert.equal((await h.engine.snapshot()).pending, 0, 'another property has its own queue')
    })

    it('does nothing while signed out', async () => {
        const h = await harness()
        await h.engine.enqueue(sale())
        h.session.current = null

        await h.engine.flush()

        assert.equal(h.server.batches.length, 0)
    })
})

describe('malformed batches', () => {
    it('isolates the offending entry and still syncs the rest', async () => {
        const h = await harness()
        const good1 = await h.engine.enqueue(sale())
        const bad = await h.engine.enqueue(sale('malformed'))
        const good2 = await h.engine.enqueue(sale())

        await h.engine.flush()

        assert.equal((await h.store.get(good1.operationId))?.state, 'accepted')
        assert.equal((await h.store.get(good2.operationId))?.state, 'accepted')
        const culprit = await h.store.get(bad.operationId)
        assert.equal(culprit?.state, 'rejected')
        assert.equal(culprit?.lastCode, 'invalid_batch')
        assert.notEqual(culprit?.box, null, 'and its payload is kept')
    })
})

describe('housekeeping and monitoring', () => {
    it('purges only accepted receipts after the retention period', async () => {
        const h = await harness({ acceptedRetentionMs: 1000 })
        const done = await h.engine.enqueue(sale())
        await h.engine.flush()
        const conflict = await h.engine.enqueue(sale('conflict'))
        await h.engine.flush()

        h.clock.now += 5000
        assert.equal(await h.engine.purge(), 1)

        assert.equal(await h.store.get(done.operationId), null)
        assert.equal((await h.store.get(conflict.operationId))?.state, 'conflict')
    })

    it('reports queue depth with every batch and heartbeats an idle but non-empty queue', async () => {
        const h = await harness({ heartbeatEveryMs: 1000 })
        await h.engine.enqueue(sale('later'))
        h.clock.now += 120_000

        await h.engine.flush()
        assert.deepEqual(h.server.batches[0]?.client_status, { pending: 1, oldest_pending_seconds: 120 })

        // Idle (the entry waits out its backoff): an empty batch still tells the server.
        const before = h.server.batches.length
        h.clock.now += 1500
        await h.engine.flush()
        assert.equal(h.server.batches.length, before + 1)
        assert.deepEqual(h.server.batches.at(-1)?.items, [])
        assert.equal(h.server.batches.at(-1)?.client_status.pending, 1)
    })

    it('knows when the next delayed entry becomes due', async () => {
        const h = await harness()
        assert.equal(await h.engine.nextWakeAt(), null)

        await h.engine.enqueue(sale())
        h.server.faults.offline = true
        await h.engine.flush()

        const wake = await h.engine.nextWakeAt()
        assert.ok(wake !== null && wake > h.clock.now)
    })
})
