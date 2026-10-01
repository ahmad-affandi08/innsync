export interface BackoffConfig {
    baseMs: number
    factor: number
    capMs: number
    /** Fraction of the delay randomized each way, to avoid every device retrying in lockstep. */
    jitter: number
}

export const DEFAULT_BACKOFF: BackoffConfig = { baseMs: 2_000, factor: 2, capMs: 300_000, jitter: 0.2 }

/** Delay before retry number `attempt` (1-based): exponential, capped, jittered. Always bounded. */
export function backoffDelayMs(attempt: number, random: () => number = Math.random, config: BackoffConfig = DEFAULT_BACKOFF): number {
    const exponent = Math.max(0, Math.min(attempt, 60) - 1)
    const raw = Math.min(config.capMs, config.baseMs * config.factor ** exponent)
    const spread = raw * config.jitter * (random() * 2 - 1)

    return Math.max(0, Math.min(config.capMs, Math.round(raw + spread)))
}
