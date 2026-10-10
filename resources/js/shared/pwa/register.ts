type Nav = { serviceWorker?: { register: (url: string, options: { scope: string }) => Promise<unknown> } }
type Win = { isSecureContext: boolean; addEventListener: (type: 'load', listener: () => void) => void }

/**
 * Registers the service worker once the page has loaded, so it never competes with the page itself. It is only for a secure address (https, or localhost), where the browser allows it, and never in
 * development, where the files change all the time. A browser that refuses is not an error: the application works the same without it, only the installed app and the offline page are missing.
 */
export function registerServiceWorker(navigatorLike: Nav, windowLike: Win, development: boolean): boolean {
    if (development || !windowLike.isSecureContext || navigatorLike.serviceWorker === undefined) {
        return false
    }

    const worker = navigatorLike.serviceWorker

    windowLike.addEventListener('load', () => {
        void worker.register('/sw.js', { scope: '/' }).catch(() => undefined)
    })

    return true
}
