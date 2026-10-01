import { StatusBadge } from '@/components/ui/status-badge';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';

export type Shift = {
    id: string; number: string; status: 'open' | 'closed'; cashier_name: string | null; closed_by_name: string | null; opened_at: string; opened_business_date: string; closed_at: string | null;
    opening_float_minor: number; receipts: { method: string; received_minor: number; paid_back_minor: number; count: number }[];
    drops: { id: string; amount_minor: number; reference: string | null; note: string | null; created_at: string }[]; drops_minor: number; cash_net_minor: number; cash_on_hand_minor: number;
    expected_cash_minor: number | null; counted_cash_minor: number | null; variance_minor: number | null; variance_reason: string | null; lock_version: number;
};

const METHODS = ['cash', 'qris', 'card', 'bank_transfer', 'online'];

/** What went through a shift and how the cash adds up. Shown on the cashier's own page and on the review page. */
export function ShiftSummary({ currency, shift: s }: { currency: string; shift: Shift }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const money = (v: number) => format.money(v, currency);
    const method = (m: string) => (METHODS.includes(m) ? t(`fo.folio.method.${m}` as 'fo.folio.method.cash') : m);
    const cashReceived = s.receipts.find((r) => r.method === 'cash')?.received_minor ?? 0;
    const cashPaidBack = s.receipts.find((r) => r.method === 'cash')?.paid_back_minor ?? 0;
    const expected = s.expected_cash_minor ?? s.opening_float_minor + s.cash_net_minor - s.drops_minor;

    return (
        <div className="flex flex-col gap-5">
            <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]" data-testid="shift-header">
                <dt className="text-muted-foreground">{t('fo.cash.cashier')}</dt><dd className="font-medium">{s.cashier_name ?? '—'}</dd>
                <dt className="text-muted-foreground">{t('fo.cash.opened')}</dt><dd>{t('fo.cash.openedAt', { time: format.instant(s.opened_at), date: format.date(s.opened_business_date) })}</dd>
                <dt className="text-muted-foreground">{t('fo.cash.list.col.status')}</dt><dd><StatusBadge label={t(`fo.cash.status.${s.status}` as 'fo.cash.status.open')} tone={s.status === 'open' ? 'info' : 'neutral'} /></dd>
                {s.closed_at !== null ? <><dt className="text-muted-foreground" /><dd>{t('fo.cash.closedBy', { time: format.instant(s.closed_at), name: s.closed_by_name ?? '—' })}</dd></> : null}
            </dl>

            <section aria-labelledby="cash-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="cash-h">{t('fo.cash.cashTitle')}</h2>
                <dl className="flex max-w-md flex-col gap-1 text-sm" data-testid="cash-lines">
                    <div className="flex justify-between"><dt>{t('fo.cash.cashLine.float')}</dt><dd>{money(s.opening_float_minor)}</dd></div>
                    <div className="flex justify-between"><dt>{t('fo.cash.cashLine.received')}</dt><dd>{money(cashReceived)}</dd></div>
                    <div className="flex justify-between"><dt>{t('fo.cash.cashLine.paidBack')}</dt><dd>− {money(cashPaidBack)}</dd></div>
                    <div className="flex justify-between"><dt>{t('fo.cash.cashLine.drops')}</dt><dd>− {money(s.drops_minor)}</dd></div>
                    <div className="flex justify-between border-t border-border pt-1 font-medium"><dt>{t('fo.cash.cashLine.expected')}</dt><dd data-testid="expected-cash">{money(expected)}</dd></div>
                    {s.counted_cash_minor !== null ? (
                        <>
                            <div className="flex justify-between"><dt>{t('fo.cash.cashLine.counted')}</dt><dd>{money(s.counted_cash_minor)}</dd></div>
                            <div className="flex justify-between font-medium"><dt>{t('fo.cash.cashLine.variance')}</dt><dd data-testid="variance">{money(s.variance_minor ?? 0)}</dd></div>
                        </>
                    ) : null}
                </dl>
                {s.variance_reason !== null ? <p className="text-sm text-muted-foreground">{t('fo.cash.varianceReasonLine', { reason: s.variance_reason })}</p> : null}
            </section>

            <section aria-labelledby="rec-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="rec-h">{t('fo.cash.receipts')}</h2>
                {s.receipts.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.cash.noReceipts')}</p> : (
                    <table className="w-full text-left text-sm">
                        <thead><tr className="text-xs text-muted-foreground"><th className="py-1 font-medium" scope="col">{t('fo.cash.col.method')}</th><th scope="col">{t('fo.cash.col.received')}</th><th scope="col">{t('fo.cash.col.paidBack')}</th><th scope="col">{t('fo.cash.col.count')}</th></tr></thead>
                        <tbody>{s.receipts.map((r) => (
                            <tr className="border-t border-border" key={r.method}><th className="py-1 font-medium" scope="row">{method(r.method)}</th><td>{money(r.received_minor)}</td><td>{money(r.paid_back_minor)}</td><td>{r.count}</td></tr>
                        ))}</tbody>
                    </table>
                )}
                <p className="text-xs text-muted-foreground">{t('fo.cash.receiptsNote')}</p>
            </section>

            <section aria-labelledby="drops-h" className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold" id="drops-h">{t('fo.cash.drops')}</h2>
                {s.drops.length === 0 ? <p className="text-sm text-muted-foreground">{t('fo.cash.noDrops')}</p> : (
                    <ul className="divide-y divide-border border-y border-border text-sm" data-testid="drops">{s.drops.map((d) => (
                        <li className="flex flex-wrap justify-between gap-2 py-1" key={d.id}><span>{format.instant(d.created_at)}{d.reference !== null ? ` · ${d.reference}` : ''}{d.note !== null ? ` · ${d.note}` : ''}</span><span>{money(d.amount_minor)}</span></li>
                    ))}</ul>
                )}
            </section>
        </div>
    );
}
