import assert from 'node:assert/strict'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

function sources(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name)

        if (statSync(path).isDirectory()) return name === 'generated' ? [] : sources(path)

        return path.endsWith('.tsx') ? [path] : []
    })
}

const files = [...sources(join(root, 'modules')), ...sources(join(root, 'components'))]

describe('surfaces (docs/DESIGN/04-COMPONENTS.md)', () => {
    it('a list of rows is a list-panel on the white surface, never bare rows on the grey of the page', () => {
        const bare = files.filter((f) => /className="[^"]*divide-y divide-border border-y border-border/.test(readFileSync(f, 'utf8')))

        assert.deepEqual(bare, [], 'use `list-panel divide-y divide-border` instead of `divide-y divide-border border-y border-border`')
    })

    it('a bordered box with padding has the surface behind it', () => {
        const bare: string[] = []

        for (const f of files) {
            for (const m of readFileSync(f, 'utf8').matchAll(/className="([^"]*\bborder border-border\b[^"]*)"/g)) {
                const classes = m[1] ?? ''

                if (!classes.includes('bg-') && /( p-\d| px-\d| py-\d)/.test(classes)) bare.push(`${f.slice(root.length)}: ${classes}`)
            }
        }

        assert.deepEqual(bare, [], 'add `bg-surface` (or the background the box is meant to have)')
    })
})
