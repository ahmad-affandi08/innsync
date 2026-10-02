import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import type { MessageKey } from '@/locales/en/index';

type Line = { id: string; item_code: string; item_name: string; unit: string; qty_milli: number; unit_price_minor: number; value_minor: number };
type PurchaseReturn = {
    id: string; number: string; reason: string; receipt: { id: string; number: string }; supplier: { id: string; code: string; name: string };
    value_minor: number; returned_by_name: string | null; business_date: string; created_at: string;
    currency: string; note: string | null; credit_tax_minor: number; credit_note_number: string | null; lines: Line[];
};

/** One return to a supplier (FR-INV-011): what went back, at what price, and the credit note that came with it. */
export default function ReturnPage({ purchaseReturn: r }: { purchaseReturn: PurchaseReturn }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const money = (minor: number) => format.money(minor, r.currency);
    const facts: [string, ReactNode][] = [
        [t('inv.po.supplier'), <Link className="underline" href={`/inventory/suppliers/${r.supplier.id}`} key="s">{r.supplier.code} · {r.supplier.name}</Link>],
        [t('inv.ret.col.receipt'), <Link className="underline" href={`/inventory/receipts/${r.receipt.id}`} key="r">{r.receipt.number}</Link>],
        [t('inv.ret.col.reason'), t(`inv.ret.reason.${r.reason}` as MessageKey)],
        [t('inv.col.date'), format.date(r.business_date)],
        [t('inv.ret.col.returnedBy'), `${r.returned_by_name ?? '—'} · ${format.instant(r.created_at)}`],
        [t('inv.ret.creditNote'), r.credit_note_number ?? '—'],
        [t('inv.col.note'), r.note ?? '—'],
    ];

    return (
        <InventoryShell
            actions={<>
                <Button asChild variant="outline"><Link href="/inventory/returns">{t('inv.ret.back')}</Link></Button>
                <Button onClick={() => window.print()} type="button" variant="outline">{t('inv.po.print')}</Button>
            </>}
            description={t('inv.ret.detailDescription')}
            title={t('inv.ret.detailTitle', { number: r.number })}
            wide
        >
            <section aria-labelledby="ret-details-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="ret-details-h">{t('inv.ret.details')}</h2>
                <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-4" data-testid="return-details">
                    {facts.map(([name, value]) => (
                        <div key={name}>
                            <dt className="text-xs text-muted-foreground">{name}</dt>
                            <dd className="break-words">{value}</dd>
                        </div>
                    ))}
                </dl>
            </section>

            <section aria-labelledby="ret-lines-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="ret-lines-h">{t('inv.ret.lines')}</h2>
                <div className="border border-border bg-surface">
                    <Table data-testid="return-lines">
                        <TableHeader>
                            <TableRow>
                                <TableHead scope="col">{t('inv.col.item')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('inv.opening.quantity')}</TableHead>
                                <TableHead scope="col">{t('inv.col.unit')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('inv.po.price')}</TableHead>
                                <TableHead className="text-right" scope="col">{t('inv.col.value')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {r.lines.map((l) => (
                                <TableRow key={l.id}>
                                    <TableCell><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span></TableCell>
                                    <TableCell className="text-right">{formatMilli(l.qty_milli, locale)}</TableCell>
                                    <TableCell>{l.unit}</TableCell>
                                    <TableCell className="text-right">{money(l.unit_price_minor)}</TableCell>
                                    <TableCell className="text-right">{money(l.value_minor)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell className="text-right" colSpan={4}>{t('inv.ret.total')}</TableCell>
                                <TableCell className="text-right" data-testid="return-total">{money(r.value_minor)}</TableCell>
                            </TableRow>
                            {r.credit_tax_minor > 0 ? (
                                <TableRow>
                                    <TableCell className="text-right" colSpan={4}>{t('inv.ret.creditTax')}{r.credit_note_number === null ? '' : ` (${r.credit_note_number})`}</TableCell>
                                    <TableCell className="text-right" data-testid="return-credit-tax">{money(r.credit_tax_minor)}</TableCell>
                                </TableRow>
                            ) : null}
                        </TableFooter>
                    </Table>
                </div>
                <p className="text-sm text-muted-foreground">{t('inv.ret.payableNote')}</p>
            </section>
        </InventoryShell>
    );
}
