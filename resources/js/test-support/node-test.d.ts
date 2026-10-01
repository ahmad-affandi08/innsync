// Minimal ambient types for Node's built-in test runner. The project does not
// depend on @types/node; frontend logic tests run with `npm test` (node:test).
declare module 'node:test' {
    export function describe(name: string, fn: () => void): void
    export function it(name: string, fn: () => void | Promise<void>): void
}

declare module 'node:assert/strict' {
    interface Assert {
        (value: unknown, message?: string): void
        equal(actual: unknown, expected: unknown, message?: string): void
        deepEqual(actual: unknown, expected: unknown, message?: string): void
        notEqual(actual: unknown, expected: unknown, message?: string): void
        ok(value: unknown, message?: string): void
        throws(fn: () => unknown, expected?: RegExp | object): void
        rejects(fn: () => Promise<unknown>, expected?: RegExp | object): Promise<void>
    }
    const assert: Assert
    export default assert
}
