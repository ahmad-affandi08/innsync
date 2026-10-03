// Browser evidence for NFR-01 (load time on a 4G connection), NFR-27 (accessibility) and NFR-28 (browser support), run against a running copy of the application:
//
//   node scripts/browser-audit.mjs perf  --base=http://127.0.0.1:8000 --email=dev@innsync.test --password='Password123!'
//   node scripts/browser-audit.mjs a11y  --base=...  --email=... --password=...
//   node scripts/browser-audit.mjs crawl --pages=auto   (opens every page and reports errors in the page, the console and the requests)
//
// It needs a Playwright installation (not a dependency of the project; the global one is used when the project has none) and the `axe-core` development dependency. The 4G profile is the one of Lighthouse's
// "Slow 4G" (1.6 Mbit/s, 150 ms), which is stricter than the usual 4G of a city; pass --profile=fast for 9 Mbit/s and 170 ms. Output is JSON on stdout.
import { createRequire } from 'node:module'
import { readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { createServer } from 'node:http'
import { gzipSync } from 'node:zlib'

const require = createRequire(import.meta.url)
const args = Object.fromEntries(process.argv.slice(3).map((a) => a.replace(/^--/, '').split(/=(.*)/s).slice(0, 2)))
const mode = process.argv[2] ?? 'perf'
let base = (args.base ?? 'http://127.0.0.1:8000').replace(/\/$/, '')

function load(name) {
    for (const path of [undefined, '/opt/node22/lib/node_modules']) {
        try {
            return path === undefined ? require(name) : require(require.resolve(name, { paths: [path] }))
        } catch {
            // try the next place
        }
    }
    throw new Error(`${name} is not installed: install it (npm i -g ${name}) or run this where it is available.`)
}

const { chromium } = load('playwright')
const PROFILES = { slow: { downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8, latency: 150 }, fast: { downloadThroughput: (9 * 1024 * 1024) / 8, uploadThroughput: (1.5 * 1024 * 1024) / 8, latency: 170 } }
const profile = PROFILES[args.profile ?? 'slow']
/** Every address of the back office and the guest pages that a person can open without choosing a record, from the application's own route list. */
function allPages() {
    const routes = JSON.parse(execFileSync('php', ['artisan', 'route:list', '--json'], { encoding: 'utf8', maxBuffer: 20_000_000 }))
    const skip = /export|print|photo|signature|download|logout|^\/?(health|up|mfa|confirm-password|reconfirm|offline-check|login|properties|dashboard\/today|storage|build)|\/tickets$/

    return [...new Set(routes.filter((r) => r.method.split('|').includes('GET') && !r.uri.includes('{') && !r.uri.startsWith('api/') && !skip.test(r.uri)).map((r) => `/${r.uri.replace(/^\//, '')}`))].sort()
}

const PAGES = (args.pages === 'auto' ? allPages() : (args.pages ?? '/,/dashboard,/front-office/room-board,/front-office/reservations,/front-office/stays,/housekeeping,/fnb/pos,/kitchen,/inventory/stock,/hr/me,/finance/payables,/reports,/maintenance,/laundry,/approvals,/help').split(',')) // `--pages=auto` takes them all
const PUBLIC = ['/login']

async function signIn(page) {
    await page.goto(`${base}/login`)
    await page.getByLabel(/email/i).fill(args.email ?? 'dev@innsync.test')
    await page.getByLabel(/password|kata sandi/i).first().fill(args.password ?? 'Password123!')
    await page.locator('form button[type=submit]').first().click()
    await page.waitForLoadState('networkidle')

    if (page.url().includes('/properties')) {
        await page.getByRole('button', { name: /continue|lanjut/i }).click()
        await page.waitForLoadState('networkidle')
    }
}

async function throttled(context, page) {
    const cdp = await context.newCDPSession(page)
    await cdp.send('Network.enable')
    await cdp.send('Network.emulateNetworkConditions', { offline: false, ...profile })
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: false })

    return cdp
}

async function perf(browser) {
    const results = []
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36' })
    const page = await context.newPage()
    await signIn(page)
    await throttled(context, page)

    for (const [warm, label] of [[false, 'cold'], [true, 'warm']]) {
        for (const path of PAGES) {
            if (!warm) {
                // A first visit to this page on this phone: nothing kept from any earlier page.
                const cdp = await context.newCDPSession(page)
                await cdp.send('Network.clearBrowserCache')
            }

            const started = Date.now()
            let status = 0
            try {
                const response = await page.goto(`${base}${path}`, { waitUntil: 'load', timeout: 60000 })
                status = response?.status() ?? 0
                await page.locator('h1').first().waitFor({ state: 'visible', timeout: 30000 })
            } catch (error) {
                results.push({ path, cache: label, status, error: String(error).slice(0, 120) })
                continue
            }
            const ms = Date.now() - started
            const bytes = await page.evaluate(() => performance.getEntriesByType('resource').reduce((sum, r) => sum + (r.transferSize || 0), 0))
            results.push({ path, cache: label, status, visibleMs: ms, kilobytes: Math.round(bytes / 1024) })
        }
    }

    await context.close()

    return { profile: args.profile ?? 'slow', conditions: profile, results }
}

async function crawl(browser) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
    const page = await context.newPage()
    await signIn(page)
    const problems = []
    let visited = 0

    for (const path of PAGES) {
        const errors = []
        const onError = (e) => errors.push(`pageerror: ${String(e).slice(0, 160)}`)
        const onConsole = (m) => { if (m.type() === 'error') errors.push(`console: ${m.text().slice(0, 160)}`) }
        const onResponse = (r) => { if (r.status() >= 500 || (r.status() >= 400 && r.request().resourceType() !== 'document' && r.status() !== 401)) errors.push(`http ${r.status()}: ${r.url().replace(base, '').slice(0, 100)}`) }
        page.on('pageerror', onError)
        page.on('console', onConsole)
        page.on('response', onResponse)
        let status = 0
        let hasHeading = false

        try {
            const response = await page.goto(`${base}${path}`, { waitUntil: 'networkidle', timeout: 45000 })
            status = response?.status() ?? 0
            hasHeading = (await page.locator('h1').count()) > 0
        } catch (error) {
            errors.push(`navigation: ${String(error).slice(0, 120)}`)
        }

        page.off('pageerror', onError)
        page.off('console', onConsole)
        page.off('response', onResponse)
        visited++

        if (status >= 400 || errors.length > 0 || (!hasHeading && status < 300 && !page.url().includes('/print'))) problems.push({ path, status, url: page.url().replace(base, ''), hasHeading, errors })
    }

    await context.close()

    return { visited, problems }
}

async function a11y(browser) {
    const axe = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8')
    const out = []
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
    const page = await context.newPage()

    for (const path of PUBLIC) {
        await page.goto(`${base}${path}`, { waitUntil: 'networkidle' })
        await page.addScriptTag({ content: axe })
        out.push({ path, ...(await scan(page)) })
    }

    await signIn(page)

    for (const locale of (args.locales ?? 'en,id').split(',')) {
        await page.evaluate(async (next) => {
            const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')
            await fetch('/locale', { method: 'POST', headers: { 'X-XSRF-TOKEN': xsrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ locale: next }) })
        }, locale)

        for (const path of PAGES) {
            const response = await page.goto(`${base}${path}`, { waitUntil: 'networkidle' })
            if ((response?.status() ?? 0) >= 400) { out.push({ path, locale, status: response?.status() }); continue }
            await page.addScriptTag({ content: axe })
            out.push({ path, locale, ...(await scan(page)) })
        }
    }

    await context.close()

    return out
}

async function scan(page) {
    const result = await page.evaluate(async () => {
        // eslint-disable-next-line no-undef
        const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } })

        return r.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, sample: v.nodes[0]?.target?.join(' ').slice(0, 100) }))
    })

    return { violations: result }
}

/**
 * The development server of PHP neither compresses nor sets cache headers. A real web server runs `public/.htaccess`, which does both, so the audit puts a small proxy in front that does the same
 * (gzip for the text types, a year for /build/assets) unless `--prod-like=false` asks for the raw server.
 */
async function prodLikeProxy(target) {
    const text = /^(text\/|application\/(javascript|json)|image\/svg)/
    const server = createServer(async (req, res) => {
        const body = ['GET', 'HEAD'].includes(req.method ?? 'GET') ? undefined : Buffer.concat(await req.toArray())
        const upstream = await fetch(`${target}${req.url}`, { method: req.method, headers: { ...req.headers, 'accept-encoding': 'identity' }, body, redirect: 'manual' })
        const headers = Object.fromEntries(upstream.headers.entries())
        let payload = Buffer.from(await upstream.arrayBuffer())
        delete headers['content-length']
        delete headers['transfer-encoding']
        delete headers['content-encoding']

        if (headers.location !== undefined) headers.location = headers.location.replaceAll(target, `http://${req.headers.host}`)
        // The server writes absolute addresses of itself (APP_URL); the browser must keep coming back through here.
        if (text.test(headers['content-type'] ?? '')) payload = Buffer.from(payload.toString('utf8').replaceAll(target, `http://${req.headers.host}`))
        if (text.test(headers['content-type'] ?? '') && payload.length > 512 && String(req.headers['accept-encoding'] ?? '').includes('gzip')) {
            payload = gzipSync(payload, { level: 6 })
            headers['content-encoding'] = 'gzip'
        }

        if ((req.url ?? '').startsWith('/build/assets/')) headers['cache-control'] = 'public, max-age=31536000, immutable'

        if (args.debug !== undefined) console.error(req.method, req.url, upstream.status, headers['content-type'], headers['content-encoding'] ?? '')
        const setCookie = upstream.headers.getSetCookie()
        delete headers['set-cookie']
        res.writeHead(upstream.status, { ...headers, ...(setCookie.length > 0 ? { 'set-cookie': setCookie } : {}) })
        res.end(payload)
    })
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))

    return { url: `http://127.0.0.1:${server.address().port}`, close: () => server.close() }
}

const proxy = args['prod-like'] === 'false' ? null : await prodLikeProxy(base)
if (proxy !== null) base = proxy.url

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
try {
    const report = mode === 'a11y' ? await a11y(browser) : mode === 'crawl' ? await crawl(browser) : await perf(browser)
    console.log(JSON.stringify(report, null, 2))
} finally {
    await browser.close()
    proxy?.close()
}
