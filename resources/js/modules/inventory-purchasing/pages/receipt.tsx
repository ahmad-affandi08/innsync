import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Line = {
    id: string; item_code: string; item_name: string; unit: string; accepted_qty_milli: number; rejected_qty_milli: number; condition: string; rejection_reason: string | null; note: string | null;
    unit_price_minor: number; value_minor: number; expires_on: string | null; photos: { id: string }[];
};
type Receipt = {
    id: string; number: string; order: { id: string; number: string; status: string }; supplier: { id: string; code: string; name: string }; location: { id: string; code: string; name: string };
    delivery_note: string | null; received_on: string; value_minor: number; received_by_name: string | null; created_at: string; currency: string; note: string | null; order_revision: number;
    may_photo: boolean; max_photos: number; lines: Line[];
};

/** A posted goods receipt: what was accepted and refused per line, and the photos of the delivery. It is printable as the receiving note. */
export default function ReceiptPage({ receipt }: { receipt: Receipt }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [pickerKey, setPickerKey] = useState(0);
    const qty = (n: number) => formatMilli(n, locale);
    const money = (minor: number) => format.money(minor, receipt.currency);

    async function upload(line: Line, file: File | undefined) {
        if (file === undefined) return;
        const body = new FormData();
        body.set('photo', file);
        await action.run(`/inventory/receipts/${receipt.id}/lines/${line.id}/photos`, { body, reload: ['receipt'] });
        setPickerKey((k) => k + 1);
    }

    const facts: [string, ReactNode][] = [
        [t('inv.rcv.col.order'), <Link className="underline" href={`/inventory/orders/${receipt.order.id}`} key="order">{receipt.order.number}</Link>],
        [t('inv.rcv.orderState'), t(`inv.rcv.orderStatus.${receipt.order.status}` as MessageKey)],
        [t('inv.rcv.col.supplier'), receipt.supplier.name],
        [t('inv.col.location'), `${receipt.location.code} · ${receipt.location.name}`],
        [t('inv.rcv.col.deliveryNote'), receipt.delivery_note ?? '—'],
        [t('inv.col.date'), format.date(receipt.received_on)],
        [t('inv.rcv.col.receivedBy'), `${receipt.received_by_name ?? '—'} · ${format.instant(receipt.created_at)}`],
        [t('inv.col.value'), money(receipt.value_minor)],
        [t('inv.col.note'), receipt.note ?? '—'],
    ];

    return (
        <InventoryShell
            actions={<div className="flex gap-2 print:hidden">
                <Button asChild variant="outline"><Link href="/inventory/receipts">{t('inv.rcv.back')}</Link></Button>
                <Button onClick={() => window.print()} type="button" variant="outline">{t('inv.rcv.print')}</Button>
            </div>}
            description={t('inv.rcv.detailDescription')}
            title={`${receipt.number} · ${t('inv.rcv.note')}`}
            wide
        >
            <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-3" data-testid="receipt-head">
                {facts.map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-xs text-muted-foreground">{label}</dt>
                        <dd className="break-words">{value}</dd>
                    </div>
                ))}
            </dl>

            {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            <div className="overflow-x-auto border border-border bg-surface">
                <Table data-testid="receipt-lines">
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('inv.col.item')}</TableHead>
                            <TableHead className="text-right">{t('inv.rcv.accepted')}</TableHead>
                            <TableHead className="text-right">{t('inv.rcv.refused')}</TableHead>
                            <TableHead>{t('inv.rcv.refusalReason')}</TableHead>
                            <TableHead>{t('inv.rcv.condition')}</TableHead>
                            <TableHead className="text-right">{t('inv.rcv.price')}</TableHead>
                            <TableHead className="text-right">{t('inv.col.value')}</TableHead>
                            <TableHead>{t('inv.rcv.expiry')}</TableHead>
                            <TableHead>{t('inv.rcv.lineNote')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {receipt.lines.map((l) => (
                            <TableRow data-testid={`receipt-line-${l.item_code}`} key={l.id}>
                                <TableCell><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span></TableCell>
                                <TableCell className="text-right">{qty(l.accepted_qty_milli)} {l.unit}</TableCell>
                                <TableCell className="text-right">{l.rejected_qty_milli > 0 ? `${qty(l.rejected_qty_milli)} ${l.unit}` : '—'}</TableCell>
                                <TableCell>{l.rejection_reason === null ? '—' : t(`inv.rcv.reason.${l.rejection_reason}` as MessageKey)}</TableCell>
                                <TableCell>{t(`inv.rcv.condition.${l.condition}` as MessageKey)}</TableCell>
                                <TableCell className="text-right">{money(l.unit_price_minor)}</TableCell>
                                <TableCell className="text-right">{money(l.value_minor)}</TableCell>
                                <TableCell>{l.expires_on === null ? '—' : format.date(l.expires_on)}</TableCell>
                                <TableCell>{l.note ?? '—'}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <section aria-labelledby="rcv-photos-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="rcv-photos-h">{t('inv.rcv.photos')}</h2>
                <ul className="flex flex-col gap-3">
                    {receipt.lines.map((l) => (
                        <li className="flex flex-col gap-2 border border-border p-3 text-sm" data-testid={`receipt-photos-${l.item_code}`} key={l.id}>
                            <p><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span> · {t('inv.rcv.photoCount', { count: l.photos.length, max: receipt.max_photos })}</p>
                            {l.photos.length > 0 ? (
                                <ul className="flex flex-wrap gap-3">
                                    {l.photos.map((p) => (
                                        <li className="flex flex-col gap-1" key={p.id}>
                                            <a href={`/inventory/receipts/${receipt.id}/photos/${p.id}`} rel="noreferrer" target="_blank">
                                                <img alt={t('inv.rcv.photoAlt', { item: l.item_code, number: receipt.number })} className="h-28 w-28 border border-border object-cover" src={`/inventory/receipts/${receipt.id}/photos/${p.id}`} />
                                            </a>
                                            <a className="text-xs underline print:hidden" href={`/inventory/receipts/${receipt.id}/photos/${p.id}`} rel="noreferrer" target="_blank">{t('inv.rcv.openFull')}</a>
                                        </li>
                                    ))}
                                </ul>
                            ) : <p className="text-muted-foreground">{t('inv.rcv.noPhotos')}</p>}
                            {receipt.may_photo && l.photos.length < receipt.max_photos ? (
                                <div className="max-w-sm print:hidden">
                                    <FormField error={action.fieldError('photo')} field="photo" label={t('inv.rcv.addPhoto')}>
                                        <Input accept="image/jpeg,image/png" capture="environment" key={`${l.id}-${pickerKey}`} onChange={(e) => void upload(l, e.target.files?.[0])} type="file" />
                                    </FormField>
                                </div>
                            ) : null}
                        </li>
                    ))}
                </ul>
            </section>
        </InventoryShell>
    );
}
