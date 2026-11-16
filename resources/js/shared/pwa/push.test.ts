import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { deviceLabel, isAppleMobile, keyToBytes, NUDGE_PAUSE_MS, nudgeDue } from './push.ts'

describe('web push helpers', () => {
    it('reads the public key of the server, a base64url point of 65 bytes, as bytes', () => {
        const bytes = keyToBytes('BAEC-_8FBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4vMDEyMzQ1Njc4OTo7PD0-TU8')
        assert.equal(bytes.length, 65)
        assert.equal(bytes[0], 4)
        assert.equal(bytes[1], 1)
        assert.equal(bytes[64], 0x4f)
        assert.equal(bytes[3], 0xfb, 'the url-safe characters - and _ are the + and / of base64')
        assert.equal(bytes[4], 0xff)
    })

    it('names a device by its browser and system only', () => {
        assert.equal(deviceLabel('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36'), 'Chrome · Android')
        assert.equal(deviceLabel('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1'), 'Safari · iOS')
        assert.equal(deviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0 Safari/537.36 Edg/126.0'), 'Edge · Windows')
        assert.equal(deviceLabel(''), 'Browser')
    })

    it('knows an iPhone and an iPad, which says it is a Mac but has a touch screen', () => {
        assert.equal(isAppleMobile('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', 5), true)
        assert.equal(isAppleMobile('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 5), true)
        assert.equal(isAppleMobile('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 0), false)
        assert.equal(isAppleMobile('Mozilla/5.0 (Linux; Android 14)', 5), false)
    })

    it('invites again only after "Not now" has had its week', () => {
        const now = 1_800_000_000_000
        assert.equal(nudgeDue(null, now), true)
        assert.equal(nudgeDue('garbage', now), true)
        assert.equal(nudgeDue(String(now - NUDGE_PAUSE_MS + 1), now), false)
        assert.equal(nudgeDue(String(now - NUDGE_PAUSE_MS), now), true)
    })
})
