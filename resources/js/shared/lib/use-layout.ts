import { useSyncExternalStore } from 'react'

export type LayoutMode = 'sidebar' | 'rail' | 'topbar'

export const LAYOUT_MODES: readonly LayoutMode[] = ['rail', 'sidebar', 'topbar']

/** Used until a person chooses another. */
export const DEFAULT_LAYOUT: LayoutMode = 'rail'

const KEY = 'innsync.layout'
const EVENT = 'innsync:layout'

function read(): LayoutMode {
    try {
        const stored = window.localStorage.getItem(KEY)

        return LAYOUT_MODES.find((m) => m === stored) ?? DEFAULT_LAYOUT
    } catch {
        return DEFAULT_LAYOUT
    }
}

/** The back-office layout this browser prefers. A display preference only: it never changes what a person may do. */
export function useLayout(): [LayoutMode, (mode: LayoutMode) => void] {
    const mode = useSyncExternalStore(
        (notify) => {
            window.addEventListener(EVENT, notify)
            window.addEventListener('storage', notify)

            return () => {
                window.removeEventListener(EVENT, notify)
                window.removeEventListener('storage', notify)
            }
        },
        read,
        () => DEFAULT_LAYOUT,
    )

    return [
        mode,
        (next) => {
            try {
                window.localStorage.setItem(KEY, next)
            } catch {
                // Blocked storage: the choice lasts until the page reloads.
            }

            window.dispatchEvent(new Event(EVENT))
        },
    ]
}
