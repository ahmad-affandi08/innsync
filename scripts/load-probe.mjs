// A concurrency probe for NFR-02 (50 users at the same time) against a running copy of the application, from many signed-in sessions, each opening pages at the pace of a person.
//
//   node scripts/load-probe.mjs --base=http://127.0.0.1:8000 --property=<property ulid> --users=50 --seconds=30 --password='Password123!' --email-pattern='load{n}@innsync.test'
//
// The accounts must exist (the development seeder makes one; a probe needs as many as `--users`, with the same password). Output is JSON: requests, errors, throughput and the p50, p95 and p99 of the time a
// page took. Run it against a copy that looks like the hotel's own server and database; the PHP development server is only a floor, not a promise.
const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split(/=(.*)/s).slice(0, 2)))
const base = (args.base ?? 'http://127.0.0.1:8000').replace(/\/$/, '')
const users = Number(args.users ?? 50)
const seconds = Number(args.seconds ?? 30)
const password = args.password ?? 'Password123!'
const pattern = args['email-pattern'] ?? 'load{n}@innsync.test'
const property = args.property
const PAGES = (args.pages ?? '/front-office/room-board,/housekeeping,/fnb/pos,/dashboard,/front-office/reservations,/front-office/stays,/kitchen,/inventory/stock').split(',')

class Session {
    cookies = new Map()

    async fetch(path, init = {}) {
        const headers = { Cookie: [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '), Accept: 'text/html,application/json', ...init.headers }
        const xsrf = this.cookies.get('XSRF-TOKEN')
        if (xsrf !== undefined && init.method === 'POST') headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf)
        const response = await fetch(`${base}${path}`, { ...init, headers, redirect: 'manual' })
        for (const line of response.headers.getSetCookie()) {
            const [pair] = line.split(';')
            const [name, ...value] = pair.split('=')
            this.cookies.set(name.trim(), value.join('='))
        }

        return response
    }

    async login(email) {
        await this.fetch('/login')
        const response = await this.fetch('/login', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email, password }) })
        if (response.status >= 400) throw new Error(`login ${email}: ${response.status}`)
        const picked = await this.fetch('/properties/select', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ property_id: property }) })
        if (picked.status >= 400) throw new Error(`select property: ${picked.status}`)
    }
}

function percentile(sorted, p) {
    return sorted.length === 0 ? 0 : sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))]
}

if (property === undefined) throw new Error('--property=<ULID of the property> is required')

const sessions = []
for (let n = 1; n <= users; n++) {
    const s = new Session()
    await s.login(pattern.replace('{n}', String(n)))
    sessions.push(s)
}

const timings = []
const errors = []
const until = Date.now() + seconds * 1000

await Promise.all(sessions.map(async (s, i) => {
    while (Date.now() < until) {
        const path = PAGES[Math.floor(Math.random() * PAGES.length)]
        const started = performance.now()
        try {
            const response = await s.fetch(path)
            await response.arrayBuffer()
            const ms = performance.now() - started
            if (response.status >= 400) errors.push({ path, status: response.status })
            else timings.push(ms)
        } catch (error) {
            errors.push({ path, error: String(error).slice(0, 80) })
        }
        await new Promise((resolve) => setTimeout(resolve, 300 + Math.random() * 900 + (i % 3) * 50))
    }
}))

timings.sort((a, b) => a - b)
console.log(JSON.stringify({
    users, seconds, requests: timings.length + errors.length, errors: errors.length, errorSample: errors.slice(0, 3),
    perSecond: Number(((timings.length + errors.length) / seconds).toFixed(1)),
    ms: { p50: Math.round(percentile(timings, 50)), p95: Math.round(percentile(timings, 95)), p99: Math.round(percentile(timings, 99)), max: Math.round(timings.at(-1) ?? 0) },
}, null, 2))
