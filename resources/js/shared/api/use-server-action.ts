import { router } from '@inertiajs/react'
import { useCallback, useState } from 'react'

import { apiRequest } from '@/shared/api/http'
import { ApiError, toFailure, type Failure } from '@/shared/lib/api-error'

/** Only same-site paths are allowed as a return target, so the re-confirmation page cannot be used as an open redirect. */
export function currentReturnPath(): string {
    return `${window.location.pathname}${window.location.search}`
}

type RunOptions = {
    method?: 'POST' | 'PUT' | 'DELETE'
    body?: unknown
    /** Inertia props to reload afterwards so the screen shows the server's truth. */
    reload?: string[]
    /** One key per user intent (NFR-18): reuse it when the same intent is sent again after an interruption. */
    idempotencyKey?: string
    /** Called with the failure before it is stored, so a screen can react to a specific refusal (for example open a dialog). */
    onFailure?: (failure: Failure) => void
}

/**
 * Runs one mutation against a JSON endpoint and keeps the failure in a form the screen can show: the standard error
 * state plus per-field messages from a 422. A 423 (the password confirmation lapsed) sends the person to confirm it and
 * back to this page. Nothing is retried implicitly; the screen decides.
 */
export function useServerAction() {
    const [busy, setBusy] = useState(false)
    const [error, setError] = useState<unknown>(null)

    const run = useCallback(async <T,>(path: string, options: RunOptions = {}): Promise<T | null> => {
        setBusy(true)
        setError(null)

        try {
            const result = await apiRequest<T>(path, { method: options.method ?? 'POST', body: options.body, idempotencyKey: options.idempotencyKey })

            if (options.reload !== undefined) {
                router.reload({ only: options.reload })
            }

            return result
        } catch (caught) {
            if (caught instanceof ApiError && caught.failure.status === 423) {
                window.location.assign(`/reconfirm?return=${encodeURIComponent(currentReturnPath())}`)

                return null
            }

            setError(caught)
            options.onFailure?.(toFailure(caught))

            if (caught instanceof ApiError && caught.failure.kind === 'conflict' && options.reload !== undefined) {
                router.reload({ only: options.reload })
            }

            return null
        } finally {
            setBusy(false)
        }
    }, [])

    const failure: Failure | null = error === null ? null : toFailure(error)

    return {
        busy,
        error,
        failure,
        fieldError: (name: string): string | undefined => failure?.fields[name]?.[0],
        clear: () => setError(null),
        run,
    }
}
