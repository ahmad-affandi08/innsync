import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'

import { generate } from '../../../scripts/form-requirements.mjs'

describe('required fields per screen', () => {
    it('is up to date with the backend rules and the screens (run `npm run forms`)', () => {
        const committed = readFileSync(new URL('./form-requirements.json', import.meta.url), 'utf8')

        assert.equal(generate(), committed)
    })

    it('marks the reservation form fields the backend requires', () => {
        const all = JSON.parse(readFileSync(new URL('./form-requirements.json', import.meta.url), 'utf8')) as Record<string, string[]>
        const fields = all['front-office/pages/reservations'] ?? []

        for (const field of ['guest_name', 'arrival', 'departure', 'room_type_id', 'rate_plan_id']) assert.ok(fields.includes(field), `${field} should be marked`)

        assert.ok(!fields.includes('notes'), 'an optional field must not be marked')
    })
})
