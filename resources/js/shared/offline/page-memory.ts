import { router } from '@inertiajs/react'

import { offlineSupport, openOfflineRuntime } from './idb-store.ts'
import { isOfflinePage, openSnapshot, pathOf, saveSnapshot, type InertiaPageLike } from './page-snapshot.ts'

/**
 * Keeps the offline pages on the device and opens them again when the network is down (docs/OPERATIONS/OFFLINE-OPERATIONS.md). Everything here is best effort: a browser that cannot do it
 * simply has no offline start, and nothing on screen changes.
 */
async function runtime() {
    return offlineSupport().supported ? await openOfflineRuntime() : null
}

async function remember(page: InertiaPageLike): Promise<void> {
    // A page that was itself opened from the device is not kept again: that would renew its age without a look at the server.
    if (!isOfflinePage(page.url) || page.props.auth == null || page.props.offlineSnapshot !== undefined) {
        return
    }

    try {
        const r = await runtime()

        if (r !== null) await saveSnapshot({ store: r.snapshots, cipher: r.cipher }, page, Date.now())
    } catch {
        // Nothing to do: the page works online either way.
    }
}

async function forget(): Promise<void> {
    try {
        await (await runtime())?.snapshots.clear()
    } catch {
        // Nothing to do.
    }
}

/** Called once at start with the page the server sent: keeps it, and keeps every later visit to an offline page. A sign-out, or a visit with nobody signed in, removes what was kept. */
export function startPageMemory(first: InertiaPageLike | null): void {
    if (first !== null) {
        first.props.auth == null ? void forget() : void remember(first)
    }

    router.on('navigate', (event) => {
        const page = event.detail.page as unknown as InertiaPageLike

        page.props.auth == null ? void forget() : void remember(page)
    })
    router.on('before', (event) => {
        const visit = event.detail.visit

        if (visit.method === 'post' && pathOf(String(visit.url instanceof URL ? visit.url.pathname : visit.url)) === '/logout') {
            void forget()
        }
    })
}

const NOT_KEPT = {
    id: 'Halaman ini belum pernah dibuka di perangkat ini saat ada jaringan, atau sudah terlalu lama. Buka sekali saat online lalu coba lagi.',
    en: 'This page has not been opened on this device while online, or it is too old. Open it once with a network, then try again.',
}

/**
 * The shell sent by `/offline-shell` has no page in it. Fills it with the one kept on this device. Returns false (and says why on screen) when there is none, so the caller starts nothing.
 */
export async function fillOfflineShell(el: HTMLElement): Promise<boolean> {
    try {
        const r = await runtime()
        const kept = r === null ? null : await openSnapshot({ store: r.snapshots, cipher: r.cipher }, pathOf(location.pathname), Date.now())

        if (kept !== null) {
            // Inertia reads the first page from this element, as it does from the document the server sends.
            const script = document.createElement('script')
            script.type = 'application/json'
            script.dataset.page = 'app'
            script.textContent = JSON.stringify({ ...kept.page, props: { ...kept.page.props, offlineSnapshot: { savedAt: kept.savedAt } } })
            document.body.insertBefore(script, el)
            delete el.dataset.offlineShell

            return true
        }
    } catch {
        // Falls through to the message.
    }

    el.replaceChildren()
    const box = document.createElement('main')
    box.style.cssText = 'font-family:system-ui,sans-serif;max-width:32rem;margin:15vh auto;padding:0 1rem'

    for (const text of [NOT_KEPT.id, NOT_KEPT.en]) {
        const p = document.createElement('p')
        p.textContent = text
        box.appendChild(p)
    }

    el.appendChild(box)

    return false
}
