import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import vm from 'node:vm'

const SOURCE = readFileSync(new URL('../../../../public/sw.js', import.meta.url), 'utf8')
const ORIGIN = 'https://hotel.example'

type Listener = (event: Record<string, unknown>) => void

/** Runs public/sw.js against a small fake of the browser: a cache store, a network that can be switched off, and the events the browser sends. */
function worker() {
    const listeners: Record<string, Listener> = {}
    const stores = new Map<string, Map<string, Response>>()
    const network = { up: true, requests: [] as string[], headers: [] as Record<string, string>[], notice: null as unknown }
    const shown: { title: string; options: Record<string, unknown> }[] = []
    const clients = { opened: [] as string[], windows: [] as { navigate: (url: string) => Promise<unknown>; focus: () => Promise<unknown> }[] }
    const keyOf = (request: Request | string) => (typeof request === 'string' ? new URL(request, ORIGIN).href : request.url)
    const open = (name: string) => {
        if (!stores.has(name)) stores.set(name, new Map())
        const store = stores.get(name) as Map<string, Response>

        return Promise.resolve({
            match: (request: Request | string) => Promise.resolve(store.get(keyOf(request))?.clone()),
            put: (request: Request, response: Response) => { store.set(keyOf(request), response); return Promise.resolve() },
            addAll: (urls: string[]) => { urls.forEach((u) => store.set(keyOf(u), new Response(`kept ${u}`))); return Promise.resolve() },
            keys: () => Promise.resolve([...store.keys()].map((u) => ({ url: u }))),
            delete: (request: { url: string } | string) => Promise.resolve(store.delete(typeof request === 'string' ? request : request.url)),
        })
    }
    const sandbox = {
        self: {
            addEventListener: (type: string, listener: Listener) => { listeners[type] = listener },
            location: new URL(ORIGIN),
            skipWaiting: () => Promise.resolve(),
            clients: { claim: () => Promise.resolve(), matchAll: () => Promise.resolve(clients.windows), openWindow: (url: string) => { clients.opened.push(url); return Promise.resolve(null) } },
            registration: { pushManager: { getSubscription: () => Promise.resolve({ endpoint: 'https://fcm.googleapis.com/fcm/send/abc' }) }, showNotification: (title: string, options: Record<string, unknown>) => { shown.push({ title, options }); return Promise.resolve() } },
        },
        caches: { open, keys: () => Promise.resolve([...stores.keys()]), delete: (name: string) => Promise.resolve(stores.delete(name)) },
        fetch: (input: Request | string, init?: { headers?: Record<string, string> }) => {
            const request = { url: keyOf(input) }
            network.requests.push(request.url)
            network.headers.push(init?.headers ?? {})
            if (!network.up) return Promise.reject(new TypeError('offline'))
            if (new URL(request.url).pathname === '/push/next') return Promise.resolve(new Response(JSON.stringify({ notice: network.notice })))
            // An answer from our own address, as the browser reports it ("basic").
            return Promise.resolve(Object.defineProperty(new Response(`network ${request.url}`), 'type', { value: 'basic' }))
        },
        URL, Response, Request,
    }
    vm.runInNewContext(SOURCE, sandbox)

    async function fire(request: Request): Promise<Response | null> {
        let answer: Promise<Response> | null = null
        listeners.fetch({ request, respondWith: (p: Promise<Response>) => { answer = p }, waitUntil: () => undefined })

        return answer === null ? null : await answer
    }

    return { stores, network, listeners, fire, shown, clients }
}

const get = (path: string, mode: RequestMode = 'cors') => { const r = new Request(ORIGIN + path); return Object.defineProperty(r, 'mode', { value: mode }) as Request }

describe('public/sw.js', () => {
    it('keeps the "no connection" page when it is installed, and removes the copies of an older version when it starts', async () => {
        const w = worker()
        w.stores.set('innsync-old-assets', new Map())
        w.stores.set('other-site-cache', new Map())

        await new Promise<void>((resolve) => w.listeners.install({ waitUntil: (p: Promise<unknown>) => void p.then(() => resolve()) }))
        assert.ok(w.stores.get('innsync-v3-shell')?.has(`${ORIGIN}/offline.html`))

        await new Promise<void>((resolve) => w.listeners.activate({ waitUntil: (p: Promise<unknown>) => void p.then(() => resolve()) }))
        assert.equal(w.stores.has('innsync-old-assets'), false)
        assert.equal(w.stores.has('other-site-cache'), true, 'what belongs to something else is left alone')
        assert.equal(w.stores.has('innsync-v3-shell'), true)
    })

    it('answers a built file from the phone after the first time, and never keeps anything else', async () => {
        const w = worker()
        const first = await w.fire(get('/build/assets/app-abc123.js'))
        assert.equal(await first?.text(), `network ${ORIGIN}/build/assets/app-abc123.js`)

        w.network.up = false
        const again = await w.fire(get('/build/assets/app-abc123.js'))
        assert.equal(await again?.text(), `network ${ORIGIN}/build/assets/app-abc123.js`, 'served from the phone with no network')

        const keptUrls = [...(w.stores.get('innsync-v3-assets')?.keys() ?? [])]
        assert.deepEqual(keptUrls, [`${ORIGIN}/build/assets/app-abc123.js`])
    })

    it('does not touch a write, another site, an answer of the application or a page that can be reached', async () => {
        const w = worker()

        assert.equal(await w.fire(new Request(`${ORIGIN}/fnb/bills`, { method: 'POST', body: '{}' })), null, 'a write goes to the network, never through the worker')
        assert.equal(await w.fire(new Request('https://cdn.example/lib.js')), null)
        assert.equal(await w.fire(get('/dashboard', 'cors')), null, 'an answer of the application (not a page load) is not kept or answered')

        const page = await w.fire(get('/dashboard', 'navigate'))
        assert.equal(await page?.text(), `network ${ORIGIN}/dashboard`)
        assert.equal([...w.stores.values()].some((s) => [...s.keys()].some((k) => k.includes('/dashboard'))), false, 'a page is never kept')
    })

    it('shows the "no connection" page when a page cannot be reached at all', async () => {
        const w = worker()
        await new Promise<void>((resolve) => w.listeners.install({ waitUntil: (p: Promise<unknown>) => void p.then(() => resolve()) }))
        w.network.up = false

        const page = await w.fire(get('/front-office/room-board', 'navigate'))
        assert.equal(await page?.text(), 'kept /offline.html')
    })

    it('opens the two offline screens from the shell when the network is down, and renews the shell when they are reached', async () => {
        const w = worker()
        await new Promise<void>((resolve) => w.listeners.install({ waitUntil: (p: Promise<unknown>) => void p.then(() => resolve()) }))
        assert.ok(w.stores.get('innsync-v3-shell')?.has(`${ORIGIN}/offline-shell`))

        let renewed: Promise<unknown> = Promise.resolve()
        w.listeners.fetch({ request: get('/fnb/register', 'navigate'), respondWith: () => undefined, waitUntil: (p: Promise<unknown>) => { renewed = p } })
        await renewed
        assert.ok(w.network.requests.includes(`${ORIGIN}/offline-shell`), 'the shell is fetched again while the screen is reached online')
        assert.equal(await (await w.fire(get('/fnb/register', 'navigate')))?.text(), `network ${ORIGIN}/fnb/register`, 'online: the screen itself')

        w.network.up = false
        const renewedShell = `network ${ORIGIN}/offline-shell`
        assert.equal(await (await w.fire(get('/fnb/register', 'navigate')))?.text(), renewedShell)
        assert.equal(await (await w.fire(get('/housekeeping/my-rooms', 'navigate')))?.text(), renewedShell)
        assert.equal(await (await w.fire(get('/fnb/pos', 'navigate')))?.text(), 'kept /offline.html', 'any other screen gets the plain page')
    })

    it('keeps at most a few hundred built files', async () => {
        const w = worker()

        for (let i = 0; i < 405; i++) await w.fire(get(`/build/assets/chunk-${i}.js`))

        const kept = [...(w.stores.get('innsync-v3-assets')?.keys() ?? [])]
        assert.equal(kept.length, 400)
        assert.equal(kept.includes(`${ORIGIN}/build/assets/chunk-0.js`), false, 'the oldest go first')
        assert.equal(kept.includes(`${ORIGIN}/build/assets/chunk-404.js`), true)
    })

    it('shows the notice it fetches after an empty push, naming this device in a header, and only a path of this application as the place to open', async () => {
        const w = worker()
        w.network.notice = { title: 'Approval waiting', body: 'Open approvals.', url: '/approvals' }
        await new Promise<void>((resolve) => w.listeners.push({ waitUntil: (p: Promise<unknown>) => void p.then(() => resolve()) }))

        assert.equal(w.shown.length, 1)
        assert.equal(w.shown[0].title, 'Approval waiting')
        assert.equal((w.shown[0].options.data as { url: string }).url, '/approvals')
        assert.equal(w.network.headers.at(-1)?.['X-Push-Endpoint'], 'https://fcm.googleapis.com/fcm/send/abc')

        w.network.notice = { title: 'Elsewhere', body: 'x', url: '//evil.example/x' }
        await new Promise<void>((resolve) => w.listeners.push({ waitUntil: (p: Promise<unknown>) => void p.then(() => resolve()) }))
        assert.equal((w.shown[1].options.data as { url: string }).url, '/', 'an address outside this application is not followed')
    })

    it('shows a plain line when the notice cannot be fetched or there is none, and never leaves a push without a notification', async () => {
        const w = worker()
        w.network.up = false
        await new Promise<void>((resolve) => w.listeners.push({ waitUntil: (p: Promise<unknown>) => void p.then(() => resolve()) }))
        assert.equal(w.shown[0].title, 'InnSYnc')
        assert.match(String(w.shown[0].options.body), /perhatian|attention/)

        w.network.up = true
        w.network.notice = null
        await new Promise<void>((resolve) => w.listeners.push({ waitUntil: (p: Promise<unknown>) => void p.then(() => resolve()) }))
        assert.equal(w.shown.length, 2)
    })

    it('opens the notice when it is touched: in a window that is open, else in a new one', async () => {
        const w = worker()
        let closed = false
        const event = { notification: { close: () => { closed = true }, data: { url: '/approvals' } }, waitUntil: (_p: Promise<unknown>) => undefined }
        let done: Promise<unknown> = Promise.resolve()
        w.listeners.notificationclick({ ...event, waitUntil: (p: Promise<unknown>) => { done = p } })
        await done
        assert.equal(closed, true)
        assert.deepEqual(w.clients.opened, [`${ORIGIN}/approvals`])

        const navigated: string[] = []
        w.clients.windows.push({ navigate: (url: string) => { navigated.push(url); return Promise.resolve({ focus: () => Promise.resolve() }) }, focus: () => Promise.resolve() })
        w.listeners.notificationclick({ ...event, waitUntil: (p: Promise<unknown>) => { done = p } })
        await done
        assert.deepEqual(navigated, [`${ORIGIN}/approvals`])
        assert.equal(w.clients.opened.length, 1, 'no new window when one is open')
    })
})
