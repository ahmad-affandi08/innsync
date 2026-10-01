import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { en } from '../../locales/en/index.ts'
import { id } from '../../locales/id/index.ts'
import {
    createTranslator,
    interpolate,
    isLocale,
    negotiateLocale,
    placeholders,
} from './translate.ts'

describe('createTranslator', () => {
    const primary = { greet: 'Halo {name}', 'item.other': '{count} barang', 'item.one': '{count} barang tunggal' }
    const fallback = { greet: 'Hello {name}', only: 'English only', 'item.one': 'one item', 'item.other': '{count} items' }

    it('interpolates parameters and leaves unknown placeholders visible', () => {
        const tr = createTranslator('id', primary, fallback)

        assert.equal(tr.t('greet', { name: 'Ani' }), 'Halo Ani')
        assert.equal(tr.t('greet'), 'Halo {name}')
        assert.equal(tr.t('greet', { other: 'x' }), 'Halo {name}')
    })

    it('falls back to English, then to the key, and never throws', () => {
        const tr = createTranslator('id', primary, fallback)

        assert.equal(tr.t('only'), 'English only')
        assert.equal(tr.t('missing.key'), 'missing.key')
    })

    it('uses locale plural rules with an other fallback', () => {
        const english = createTranslator('en', fallback)

        assert.equal(english.plural('item', 1), 'one item')
        assert.equal(english.plural('item', 3), '3 items')
        // Indonesian has a single plural category ("other"), even for one.
        assert.equal(createTranslator('id', primary, fallback).plural('item', 1), '1 barang')
    })

    it('does not treat parameter values as templates or markup', () => {
        const tr = createTranslator('en', { a: 'Hi {name}' })

        assert.equal(tr.t('a', { name: '{name}<b>x</b>' }), 'Hi {name}<b>x</b>')
        assert.equal(interpolate('{a}{b}', { a: '{b}', b: 'z' }), '{b}z')
    })
})

describe('negotiateLocale', () => {
    it('matches on the primary subtag and falls back to English', () => {
        assert.equal(negotiateLocale(['id-ID', 'en']), 'id')
        assert.equal(negotiateLocale(['fr-FR', 'EN-us']), 'en')
        assert.equal(negotiateLocale(['fr']), 'en')
        assert.equal(negotiateLocale([]), 'en')
        assert.equal(isLocale('id'), true)
        assert.equal(isLocale('xx'), false)
        assert.equal(isLocale(undefined), false)
    })
})

describe('dictionaries', () => {
    it('Indonesian and English define exactly the same keys', () => {
        assert.deepEqual(Object.keys(id).sort(), Object.keys(en).sort())
    })

    it('every key keeps the same placeholders in both languages', () => {
        for (const key of Object.keys(en) as (keyof typeof en)[]) {
            assert.deepEqual(placeholders(id[key]), placeholders(en[key]), key)
        }
    })

    it('has no empty or untranslated-copy messages', () => {
        for (const [key, value] of Object.entries(id)) {
            assert.ok(value.trim() !== '', key)
        }

        // Brand-neutral labels may match; anything else identical to English is probably untranslated.
        const allowedSame = new Set([
            'common.language.id',
            'common.language.en',
            'common.field.email',
            'hk.nav.linen',
            'hk.linen.place.laundry',
            'hk.linen.kind.linen',
            'hk.linen.kind.amenity',
            // Terms Indonesian hotels use unchanged.
            'rates.kind.ota',
            'rates.quote.service',
            'rates.quote.total',
            'tax.serviceCharge',
            'fo.nav.label',
            'fo.availability.marker.stop',
            'fo.availability.marker.cta',
            'fo.availability.marker.ctd',
            'fo.availability.marker.min',
            'fo.source.ota',
            'fo.source.walk_in',
            'fo.res.total',
            'fo.res.status',
            'fo.folio.col.seq',
            'fo.folio.col.date',
            'fo.folio.col.service',
            'fo.folio.method.qris',
            'fo.folio.windowLabel',
            'fo.folio.title',
            'fo.folio.openLink',
            'fo.folio.approvalRow',
            'fo.checkin.title',
            'fo.checkin.idType.ktp',
            'fo.checkin.idType.kitas',
            'fo.stay.title',
            'fo.nav.audit',
            'ldy.nav.label',
            'fo.bill.folio',
            'fo.bill.serviceCharge',
            'fo.bill.total',
            'fo.bill.outlet.laundry',
            'rpt.mov.col.status',
            'rpt.mov.guests',
            'rpt.perf.col.adr',
            'rpt.perf.col.revpar',
            'fo.cash.shift',
            'fo.cash.list.filter',
            'fo.cash.list.col.number',
            'fo.cash.list.col.status',
            'fo.cash.detail.title',
            'fo.rate.nightRow',
            'fo.late.row',
            'fo.req.category.housekeeping',
            'fo.req.category.front_office',
            'fo.req.filter.status',
            'fo.req.hk',
            'fo.fb.channel.email',
            'fo.fb.filter.status',
            'fo.fb.row',
            'fo.fb.event.line',
            'fo.sop.tickedBy',
            'fo.sop.perf.run',
            'fo.corr.row',
            'fo.corr.rowMeta',
            'policy.scope.plan',
            'policy.depositBasis',
            'fo.res.fee.fixed',
            'fo.stay.moveRow',
            'rpt.dash.now',
            'rpt.card.revenue.laundry',
            'rpt.card.revenue.net',
            'rpt.group.front_office',
            'rpt.flash.date',
            'rpt.flash.total',
            'rpt.reg.col.visa',
            'tax.scope.laundry',
            'ldy.order.title',
            'ldy.order.col.item',
            'ldy.order.col.total',
            'fo.audit.title',
            'fo.audit.report.title',
            'fo.audit.report.waiverRow',
        ])
        const identical = (Object.keys(en) as (keyof typeof en)[]).filter(
            (key) => en[key] === id[key] && !allowedSame.has(key),
        )

        assert.deepEqual(identical, [])
    })

    it('contains plain text only', () => {
        for (const [key, value] of [...Object.entries(en), ...Object.entries(id)]) {
            assert.ok(!/[<>]/.test(value), `${key} contains markup`)
        }
    })
})
