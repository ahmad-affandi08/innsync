import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

import { folioLabel, postingText, type FolioTextLabels } from './folio-text.ts'

const id: FolioTextLabels = { guest: 'Tamu', paymentBy: (m) => `Pembayaran ${m}`, refundBy: (m) => `Pengembalian ${m}` }

describe('folio text', () => {
    it('shows the folio a guest gets by default in the language of the screen, and keeps a name a person gave', () => {
        assert.equal(folioLabel('Guest', id), 'Tamu')
        assert.equal(folioLabel('Perusahaan ABC', id), 'Perusahaan ABC')
    })

    it('translates the payment and refund lines the system wrote', () => {
        assert.equal(postingText('Payment by cash', id), 'Pembayaran cash')
        assert.equal(postingText('Payment by bank transfer', id), 'Pembayaran bank_transfer')
        assert.equal(postingText('Refund by qris', id), 'Pengembalian qris')
    })

    it('leaves what a person typed alone, even when it starts the same way', () => {
        assert.equal(postingText('Payment by cash, as agreed with the manager', id), 'Payment by cash, as agreed with the manager')
        assert.equal(postingText('Minibar', id), 'Minibar')
    })
})
