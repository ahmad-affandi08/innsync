/*
 * InnSYnc service worker. It does two small things and nothing else (docs/DESIGN/08-RESPONSIVE-PWA.md):
 *  1. The files under /build/assets and /icons carry a hash of their content (or never change), so a copy kept on the phone is always the right one: they are answered from the phone after the first time,
 *     which makes the next visit start sooner and lets an installed app open on a bad signal.
 *  2. When a page cannot be reached at all, a plain "no connection" page is shown instead of the browser's own error; for the two screens that can start from nothing (OFFLINE_PAGES) it is the shell, which
 *     holds no page and no data: the application fills it from the encrypted copy the device kept.
 * It never keeps a page, an answer of the application or anything private, and it never replays a write: sales and housekeeping steps made without a network wait in the application's own encrypted queue
 * (docs/OPERATIONS/OFFLINE-OPERATIONS.md), which sends them again entry by entry with an idempotency key. Change VERSION when this file changes; an older version's copies are removed when the new one starts.
 */
const VERSION = 'innsync-v2';
const ASSETS = `${VERSION}-assets`;
const SHELL = `${VERSION}-shell`;
const OFFLINE_PAGE = '/offline.html';
// The document with no page in it that the application fills from what the device kept (resources/js/shared/offline/page-memory.ts), for the screens that can start from nothing.
const OFFLINE_SHELL = '/offline-shell';
// Keep equal to OFFLINE_PAGES in resources/js/shared/offline/page-snapshot.ts (a test compares them).
const OFFLINE_PAGES = ['/fnb/register', '/housekeeping/my-rooms'];
const MAX_ASSETS = 400;

function keptOnThePhone(url) {
    return url.pathname.startsWith('/build/assets/') || url.pathname.startsWith('/icons/');
}

async function trim(cache, max) {
    const keys = await cache.keys();

    for (let i = 0; i < keys.length - max; i++) {
        await cache.delete(keys[i]);
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL).then((cache) => cache.addAll([OFFLINE_PAGE, OFFLINE_SHELL, '/icons/icon-192.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(names.filter((name) => name.startsWith('innsync-') && !name.startsWith(VERSION)).map((name) => caches.delete(name))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (keptOnThePhone(url)) {
        event.respondWith(
            caches.open(ASSETS).then(async (cache) => {
                const kept = await cache.match(request);

                if (kept) {
                    return kept;
                }

                const fresh = await fetch(request);

                if (fresh.ok && fresh.type === 'basic') {
                    await cache.put(request, fresh.clone());
                    await trim(cache, MAX_ASSETS);
                }

                return fresh;
            }),
        );

        return;
    }

    if (request.mode === 'navigate') {
        const startable = OFFLINE_PAGES.includes(url.pathname);

        if (startable) {
            // The shell is renewed whenever the screen is reached, so it always names the files of the current build.
            event.waitUntil(caches.open(SHELL).then((cache) => fetch(OFFLINE_SHELL).then((fresh) => (fresh.ok ? cache.put(OFFLINE_SHELL, fresh) : undefined))).catch(() => undefined));
        }

        event.respondWith(fetch(request).catch(() => caches.open(SHELL).then((cache) => cache.match(startable ? OFFLINE_SHELL : OFFLINE_PAGE)).then((page) => page || Response.error())));
    }
});
