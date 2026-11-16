import { useEffect, useRef } from 'react';

/**
 * Runs `apply` shortly after any of `values` changes, so a filter needs no Apply button.
 * Not on the first render, and not again for values already applied (React StrictMode runs effects twice).
 * The short delay lets typing settle before a text filter reloads the list.
 */
export function useAutoApply(values: readonly unknown[], apply: () => void, delayMs = 300): void {
    const key = JSON.stringify(values);
    const applied = useRef(key);
    const latest = useRef(apply);

    useEffect(() => {
        latest.current = apply;
    });

    useEffect(() => {
        if (key === applied.current) return;
        const id = window.setTimeout(() => {
            applied.current = key;
            latest.current();
        }, delayMs);

        return () => window.clearTimeout(id);
    }, [key, delayMs]);
}
