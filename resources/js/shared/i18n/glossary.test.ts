import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

const dir = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'locales', 'id')

/** docs/DESIGN/12-COPY-TERMINOLOGY.md: one word for each thing, in the Indonesian screens. */
const ONE_WORD: [RegExp, string][] = [
    [/business date/i, 'Tanggal bisnis'],
    [/tanggal operasional/i, 'Tanggal bisnis'],
    [/audit malam/i, 'Night audit'],
    [/room charge/i, 'Biaya kamar'],
]

describe('terminology of the Indonesian screens', () => {
    it('uses one word for the business date, the night audit and the room charge', () => {
        const wrong: string[] = []

        for (const file of readdirSync(dir).filter((f) => f.endsWith('.ts'))) {
            readFileSync(join(dir, file), 'utf8').split('\n').forEach((line, i) => {
                for (const [pattern, use] of ONE_WORD) if (pattern.test(line)) wrong.push(`${file}:${i + 1} use "${use}"`)
            })
        }

        assert.deepEqual(wrong, [])
    })
})
