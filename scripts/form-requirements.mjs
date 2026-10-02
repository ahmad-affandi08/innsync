/**
 * Works out, for every screen, which form fields the backend requires, so a field shows its red
 * star without anyone listing it twice.
 *
 *  1. `resources/js/generated/route-rules.json` (from `php tools/export-form-rules.php`) says which
 *     fields each route requires.
 *  2. This script reads every screen's source (and the local files it imports): the requests it
 *     makes (`action.run('/url', { body })`, `apiRequest`, `router.post`, `form.put`) and the fields
 *     it shows (`<FormField field="x">`).
 *  3. A field is required on a screen when it is required by every request of that screen that
 *     carries it. Where that is not clear the field gets no star: a star is never shown by mistake.
 *
 * Output: `resources/js/generated/form-requirements.json`, { "<page>": ["field", ...] }.
 */
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { parseAst } from 'rolldown/parseAst'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const js = join(root, 'resources/js')
const modules = join(js, 'modules')

function walk(dir) {
    return readdirSync(dir).flatMap((name) => {
        const full = join(dir, name)

        return statSync(full).isDirectory() ? walk(full) : [full]
    })
}

function resolveImport(from, spec) {
    let base
    if (spec.startsWith('@/')) base = join(js, spec.slice(2))
    else if (spec.startsWith('.')) base = resolve(dirname(from), spec)
    else return null

    for (const candidate of [`${base}.tsx`, `${base}.ts`, join(base, 'index.tsx'), join(base, 'index.ts')]) {
        try {
            if (statSync(candidate).isFile()) return candidate
        } catch {
            // try the next form
        }
    }

    return null
}

const cache = new Map()

/** Every node of an ESTree-style tree, depth first. */
function* nodes(node) {
    if (Array.isArray(node)) {
        for (const child of node) yield* nodes(child)

        return
    }

    if (node === null || typeof node !== 'object') return

    if (typeof node.type === 'string') yield node

    for (const key of Object.keys(node)) {
        if (key !== 'type' && key !== 'start' && key !== 'end') yield* nodes(node[key])
    }
}

const nameOf = (n) => (n?.type === 'Identifier' || n?.type === 'JSXIdentifier' ? n.name : n?.type === 'Literal' ? String(n.value) : null)

function urlOf(node) {
    if (node?.type === 'Literal' && typeof node.value === 'string') return node.value
    if (node?.type === 'TemplateLiteral') return node.quasis.map((q, i) => q.value.cooked + (i < node.expressions.length ? '{}' : '')).join('')

    return null
}

const keysOf = (node) => (node?.type === 'ObjectExpression' ? node.properties.filter((p) => p.type === 'Property').map((p) => nameOf(p.key)).filter(Boolean) : null)
const option = (node, name) => (node?.type === 'ObjectExpression' ? node.properties.find((p) => p.type === 'Property' && nameOf(p.key) === name)?.value : undefined)

function analyse(file) {
    if (cache.has(file)) return cache.get(file)

    const program = parseAst(readFileSync(file, 'utf8'), { lang: 'tsx' })
    const info = { imports: [], fields: [], calls: [] }

    for (const node of nodes(program.body)) {
        if (node.type === 'ImportDeclaration') {
            const target = resolveImport(file, node.source.value)

            if (target) info.imports.push(target)
        }

        if (node.type === 'JSXOpeningElement' && nameOf(node.name) === 'FormField') {
            const attr = node.attributes.find((a) => a.type === 'JSXAttribute' && nameOf(a.name) === 'field')

            if (attr?.value?.type === 'Literal') info.fields.push(String(attr.value.value))
        }

        if (node.type === 'CallExpression') {
            const callee = node.callee
            const args = node.arguments
            const first = args[0] ? urlOf(args[0]) : null

            if (first === null || !first.startsWith('/')) continue

            let method = null
            let body = null
            const m = option(args[1], 'method')

            if (callee.type === 'MemberExpression' && nameOf(callee.property) === 'run') {
                method = m?.type === 'Literal' ? String(m.value) : 'POST'
                body = keysOf(option(args[1], 'body'))
            } else if (callee.type === 'Identifier' && callee.name === 'apiRequest') {
                method = m?.type === 'Literal' ? String(m.value) : 'GET'
                body = keysOf(option(args[1], 'body'))
            } else if (callee.type === 'MemberExpression' && ['post', 'put', 'patch', 'delete'].includes(nameOf(callee.property))) {
                method = nameOf(callee.property).toUpperCase()
            }

            if (method !== null && method !== 'GET') info.calls.push({ method, url: first.split('?')[0], body })
        }
    }

    cache.set(file, info)

    return info
}

const segments = (path) => path.replace(/^\/+|\/+$/g, '').split('/')
const isPlaceholder = (s) => s === '{}' || /^\{[^}]+\}$/.test(s) || s.includes('{}')

function sameRoute(urlPattern, uri) {
    const a = segments(urlPattern)
    const b = segments(uri)

    return a.length === b.length && a.every((s, i) => s === b[i] || isPlaceholder(s) || isPlaceholder(b[i]))
}

export function generate() {
    const routeRules = JSON.parse(readFileSync(join(js, 'generated/route-rules.json'), 'utf8'))
    const routes = Object.entries(routeRules).map(([key, rule]) => {
        const [method, uri] = key.split(' ')

        return { method, uri, required: new Set(rule.required), declared: new Set(rule.declared) }
    })
    const result = {}

    for (const pageFile of walk(modules).filter((f) => /\/pages\/.+\.tsx$/.test(f))) {
        const seen = new Set()
        const queue = [pageFile]
        const fields = new Set()
        const calls = []

        while (queue.length > 0) {
            const file = queue.pop()

            if (seen.has(file)) continue
            seen.add(file)

            const info = analyse(file)

            info.fields.forEach((f) => fields.add(f))
            calls.push(...info.calls)
            info.imports.filter((i) => i.startsWith(modules) || i.startsWith(join(js, 'components/layout'))).forEach((i) => queue.push(i))
        }

        const required = []

        for (const field of [...fields].sort()) {
            const verdicts = []

            const matched = calls.flatMap((call) => routes.filter((r) => r.method === call.method && sameRoute(call.url, r.uri)).map((route) => ({ call, route })))
            // A request carries the field when its body names it; a body we could not read carries it when the route declares it.
            const carrying = matched.filter(({ call, route }) => (call.body === null ? route.declared.has(field) : call.body.includes(field)))
            const pool = carrying.length > 0 ? carrying : matched.filter(({ route }) => route.declared.has(field))

            for (const { route } of pool) verdicts.push(route.required.has(field))

            if (verdicts.length > 0 && verdicts.every(Boolean)) required.push(field)
        }

        if (required.length > 0) result[relative(modules, pageFile).replace(/\.tsx$/, '')] = required
    }

    return `${JSON.stringify(Object.fromEntries(Object.entries(result).sort(([a], [b]) => a.localeCompare(b))), null, 4)}\n`
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    const out = generate()

    writeFileSync(join(js, 'generated/form-requirements.json'), out)
    console.log(`${Object.keys(JSON.parse(out)).length} screens with required fields written`)
}
