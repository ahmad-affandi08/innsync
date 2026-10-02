import { useSyncExternalStore } from 'react'

/** True while the media query matches; false on the server and before the first paint. */
export function useMediaQuery(query: string): boolean {
    return useSyncExternalStore(
        (notify) => {
            const list = window.matchMedia(query)

            list.addEventListener('change', notify)

            return () => list.removeEventListener('change', notify)
        },
        () => window.matchMedia(query).matches,
        () => false,
    )
}
