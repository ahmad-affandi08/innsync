import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { registerServiceWorker } from './register.ts'

function fakes(secure = true, withWorker = true) {
    const calls: { url: string; scope: string }[] = []
    let onLoad: (() => void) | null = null
    const navigatorLike = withWorker ? { serviceWorker: { register: (url: string, options: { scope: string }) => { calls.push({ url, scope: options.scope }); return Promise.resolve({}) } } } : {}
    const windowLike = { isSecureContext: secure, addEventListener: (_type: 'load', listener: () => void) => { onLoad = listener } }

    return { calls, navigatorLike, windowLike, load: () => onLoad?.() }
}

describe('registerServiceWorker', () => {
    it('registers the worker at the root, after the page has loaded', () => {
        const f = fakes()

        assert.equal(registerServiceWorker(f.navigatorLike, f.windowLike, false), true)
        assert.deepEqual(f.calls, [], 'not before the load event')
        f.load()
        assert.deepEqual(f.calls, [{ url: '/sw.js', scope: '/' }])
    })

    it('does nothing in development, on an insecure address, or in a browser without the feature', () => {
        const cases = [
            { f: fakes(), development: true },
            { f: fakes(false), development: false },
            { f: fakes(true, false), development: false },
        ]

        for (const { f, development } of cases) {
            assert.equal(registerServiceWorker(f.navigatorLike, f.windowLike, development), false)
            f.load()
            assert.deepEqual(f.calls, [])
        }
    })

    it('does not fail when the browser refuses the registration', async () => {
        const refusing = { serviceWorker: { register: () => Promise.reject(new Error('refused')) } }
        let onLoad: (() => void) | null = null
        registerServiceWorker(refusing, { isSecureContext: true, addEventListener: (_t, l) => { onLoad = l } }, false)
        ;(onLoad as (() => void) | null)?.()
        await new Promise((resolve) => setTimeout(resolve, 0))
    })
})
