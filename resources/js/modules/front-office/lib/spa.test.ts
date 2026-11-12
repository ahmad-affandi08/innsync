import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { clock, earnedSoFar, percentDone, secondsLeft } from './spa.ts';

const running = { ends_at: '2026-10-01T10:30:00Z', minutes: 30, price_minor: 30_000_000, commission_bp: 4000 };

describe('spa clock', () => {
it('a treatment counts down to its end and never below zero', () => {
    assert.equal(secondsLeft(running, new Date('2026-10-01T10:00:00Z').getTime()), 1800);
    assert.equal(secondsLeft(running, new Date('2026-10-01T10:29:30Z').getTime()), 30);
    assert.equal(secondsLeft(running, new Date('2026-10-01T11:00:00Z').getTime()), 0);
    assert.equal(secondsLeft({ ends_at: null }, 0), 0);
});

it('the clock shows minutes and seconds, and hours from an hour', () => {
    assert.equal(clock(725), '12:05');
    assert.equal(clock(9), '0:09');
    assert.equal(clock(3723), '1:02:03');
});

it('the commission grows with the minutes gone, up to the share set', () => {
    assert.equal(percentDone(running, new Date('2026-10-01T10:00:00Z').getTime()), 0);
    assert.equal(percentDone(running, new Date('2026-10-01T10:15:00Z').getTime()), 50);
    assert.equal(earnedSoFar(running, new Date('2026-10-01T10:15:00Z').getTime()), 6_000_000);
    assert.equal(earnedSoFar(running, new Date('2026-10-01T10:45:00Z').getTime()), 12_000_000);
});
});
