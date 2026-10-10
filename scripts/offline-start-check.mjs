// Proof, in a real Chromium, that the restaurant register opens from nothing when the network is down (docs/OPERATIONS/OFFLINE-OPERATIONS.md):
//
//   node scripts/offline-start-check.mjs --base=http://127.0.0.1:8000 --email=dev@innsync.test --password='Password123!' --offline-cmd='<stops the server>' --online-cmd='<starts it again>'
//
// Playwright's own offline switch does not reach a service worker's requests, so the network is cut for real: the two commands stop and start the server being tested.
//
// It signs in, opens the register online (the device keeps an encrypted copy), opens a fresh page with the network switched off, checks that the register shows from the copy and that another screen shows
// the plain "no connection" page, then signs out and checks that the copy is gone. It needs Playwright (the global install is used when the project has none). Exit code 0 only when every step held.
import { execSync } from 'node:child_process'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split(/=(.*)/s).slice(0, 2)))
const base = (args.base ?? 'http://127.0.0.1:8000').replace(/\/$/, '')

function load(name) {
    for (const path of [undefined, '/opt/node22/lib/node_modules', '/opt/node-tools/node_modules']) {
        try {
            return path === undefined ? require(name) : require(require.resolve(name, { paths: [path] }))
        } catch {
            // try the next place
        }
    }
    throw new Error(`${name} is not installed`)
}

const { chromium } = load('playwright')
const results = []
const check = (name, ok, detail = '') => {
    results.push({ name, ok, detail })
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail === '' ? '' : ` — ${detail}`}`)
}

const copies = (page) =>
    page.evaluate(
        () => new Promise((resolve) => {
            const open = indexedDB.open('innsync-offline')
            open.onerror = () => resolve(null)
            open.onsuccess = () => {
                if (!open.result.objectStoreNames.contains('snapshots')) return resolve([])
                const all = open.result.transaction('snapshots').objectStore('snapshots').getAll()
                all.onsuccess = () => resolve(all.result.map((r) => ({ path: r.path, plain: new TextDecoder().decode(r.box.data).includes('component') })))
            }
        }),
    )

const browser = await chromium.launch()
const context = await browser.newContext()
const page = await context.newPage()
page.on('pageerror', (e) => console.log('pageerror', e.message))

await page.goto(`${base}/login`)
await page.getByLabel(/email/i).fill(args.email ?? 'dev@innsync.test')
await page.getByLabel(/password|kata sandi/i).first().fill(args.password ?? 'Password123!')
await page.locator('form button[type=submit]').first().click()
await page.waitForLoadState('networkidle')

if (page.url().includes('/properties')) {
    await page.getByRole('button', { name: /continue|lanjut/i }).click()
    await page.waitForLoadState('networkidle')
}

await page.goto(`${base}/fnb/register`)
await page.waitForLoadState('networkidle')
check('the register opens online', !page.url().includes('/login'), page.url())

// The worker takes the page on its next visit; the copy is written a moment after the page starts.
await page.evaluate(() => navigator.serviceWorker.ready.then(() => true))
await page.reload()
await page.waitForLoadState('networkidle')
await page.waitForTimeout(1500)
check('the service worker controls the page', await page.evaluate(() => navigator.serviceWorker.controller !== null))

const kept = await copies(page)
check('an encrypted copy of the register is kept', Array.isArray(kept) && kept.some((r) => r.path === '/fnb/register' && r.plain === false), JSON.stringify(kept))

// A fresh page, as after the device was closed and opened again, with the network off.
await page.close()
execSync(args['offline-cmd'], { stdio: 'inherit' })
const cold = await context.newPage()
await cold.goto(`${base}/fnb/register`).catch((e) => console.log('goto', e.message))
await cold.waitForTimeout(3000)
const text = (await cold.locator('body').innerText()).replace(/\s+/g, ' ')
check('the register opens with the network off, from the copy on the device', /Working from this device|Bekerja dari perangkat ini/i.test(text), text.slice(0, 200))

const other = await context.newPage()
await other.goto(`${base}/fnb/pos`).catch(() => undefined)
await other.waitForTimeout(800)
const otherText = (await other.locator('body').innerText()).replace(/\s+/g, ' ')
check('another screen shows the plain "no connection" page', /no (internet )?connection|tidak ada (koneksi|sambungan)/i.test(otherText), otherText.slice(0, 120))

// Signing out removes the copy.
execSync(args['online-cmd'], { stdio: 'inherit' })
await cold.goto(`${base}/dashboard`)
await cold.waitForLoadState('networkidle')
await cold.getByRole('button', { name: new RegExp(args.account ?? 'account|akun|profil|dev', 'i') }).first().click().catch(() => undefined)
await cold.getByRole('menuitem', { name: /sign out|keluar/i }).click().catch(() => undefined)
await cold.waitForTimeout(2000)
const left = await copies(cold)
check('signing out removes the copies', Array.isArray(left) && left.length === 0, JSON.stringify(left))

await browser.close()
process.exit(results.every((r) => r.ok) ? 0 : 1)
