import { useSyncExternalStore } from 'react'

export type LayoutMode = 'sidebar' | 'rail' | 'topbar'

export const LAYOUT_MODES: readonly LayoutMode[] = ['sidebar', 'rail', 'topbar']

const KEY = 'innsync.layout'
const EVENT = 'innsync:layout'

function read(): LayoutMode {
    try {
        const stored = window.localStorage.getItem(KEY)

        return LAYOUT_MODES.find((m) => m === stored) ?? 'sidebar'
    } catch {
        return 'sidebar'
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
        () => 'sidebar' as LayoutMode,
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
