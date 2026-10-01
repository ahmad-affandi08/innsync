/**
 * Query-key convention (TASK-FND-012, ADR-0004).
 *
 * Every key starts with the owning module and resource and carries the active
 * property id, so cached data can never be served after the user switches
 * property (NFR-06 property scope). Keys are plain tuples so TanStack Query can
 * invalidate by prefix: `all(property)` > `lists(property)` > `list(property, params)`.
 */
export type QueryParams = Readonly<Record<string, string | number | boolean | null | undefined>>

export interface ResourceKeys {
    all: (propertyId: string) => readonly [string, string, string]
    lists: (propertyId: string) => readonly [string, string, string, 'list']
    list: (
        propertyId: string,
        params: QueryParams,
    ) => readonly [string, string, string, 'list', QueryParams]
    details: (propertyId: string) => readonly [string, string, string, 'detail']
    detail: (
        propertyId: string,
        id: string,
    ) => readonly [string, string, string, 'detail', string]
}

function assertProperty(propertyId: string): string {
    if (propertyId.trim() === '') {
        throw new Error('A query key requires the active property id.')
    }

    return propertyId
}

/** Object keys are sorted and empty values dropped, so equal filters always produce equal keys. */
export function normalizeParams(params: QueryParams): QueryParams {
    const entries = Object.entries(params)
        .filter(([, value]) => value !== undefined && value !== null && value !== '')
        .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))

    return Object.fromEntries(entries)
}

export function createResourceKeys(module: string, resource: string): ResourceKeys {
    return {
        all: (propertyId) => [module, resource, assertProperty(propertyId)] as const,
        lists: (propertyId) => [module, resource, assertProperty(propertyId), 'list'] as const,
        list: (propertyId, params) =>
            [module, resource, assertProperty(propertyId), 'list', normalizeParams(params)] as const,
        details: (propertyId) => [module, resource, assertProperty(propertyId), 'detail'] as const,
        detail: (propertyId, id) =>
            [module, resource, assertProperty(propertyId), 'detail', id] as const,
    }
}
