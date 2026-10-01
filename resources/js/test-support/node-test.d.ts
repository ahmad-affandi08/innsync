// Minimal ambient types for Node's built-in test runner. The project does not
// depend on @types/node; frontend logic tests run with `npm test` (node:test).
declare module 'node:test' {
    export function describe(name: string, fn: () => void): void
    export function it(name: string, fn: () => void | Promise<void>): void
}

declare module 'node:fs' {
    export function readFileSync(path: URL | string, encoding: 'utf8'): string
}

declare module 'node:assert/strict' {
    interface Assert {
        (value: unknown, message?: string): void
        equal(actual: unknown, expected: unknown, message?: string): void
        deepEqual(actual: unknown, expected: unknown, message?: string): void
        notEqual(actual: unknown, expected: unknown, message?: string): void
        notDeepEqual(actual: unknown, expected: unknown, message?: string): void
        ok(value: unknown, message?: string): void
        throws(fn: () => unknown, expected?: RegExp | object | (new (...args: never[]) => Error), message?: string): void
        match(value: string, pattern: RegExp, message?: string): void
        notMatch(value: string, pattern: RegExp, message?: string): void
        doesNotMatch(value: string, pattern: RegExp, message?: string): void
        rejects(fn: () => Promise<unknown>, expected?: RegExp | object): Promise<void>
    }
    const assert: Assert
    export default assert
}

// Only what the logic tests need from Node's global.
declare const process: { env: Record<string, string | undefined> }
