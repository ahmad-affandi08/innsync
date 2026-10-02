import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { InventoryShell } from '@/modules/inventory-purchasing/components/inventory-shell';
import { formatMilli } from '@/modules/inventory-purchasing/lib/quantity';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Variance = { type: string; item?: string; expected_milli?: number; actual_milli?: number; expected_minor?: number; actual_minor?: number };
type Line = { id: string; item_code: string; item_name: string; unit: string; qty_milli: number; unit_price_minor: number; line_total_minor: number; order_price_minor: number; open_received_milli: number };
type Invoice = {
    id: string; number: string; invoice_number: string; status: string; invoice_date: string; due_date: string; order: { id: string; number: string }; supplier: { id: string; code: string; name: string };
    total_minor: number; variance_count: number; entered_by_name: string | null; lock_version: number; currency: string; tax_number: string | null; subtotal_minor: number; tax_minor: number; accrual_minor: number;
    note: string | null; decision_note: string | null; decided_by_name: string | null; decided_at: string | null; variances: Variance[]; lines: Line[]; documents: { id: string; name: string | null }[]; max_documents: number;
    may_resolve: boolean; resolve_blocked_self: boolean; may_document: boolean;
};

const INVOICE_TONE: Record<string, StatusTone> = { matched: 'success', variance: 'warning', approved: 'success', rejected: 'danger' };

/** One supplier invoice with its three-way match: what the supplier billed against what was ordered and what was received. */
export default function InvoicePage({ invoice }: { invoice: Invoice }) {
    const { t, locale } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [deciding, setDeciding] = useState<'approve' | 'reject' | null>(null);
    const [note, setNote] = useState('');
    const [pickerKey, setPickerKey] = useState(0);
    const money = (minor: number) => format.money(minor, invoice.currency);
    const qty = (n: number) => formatMilli(n, locale);
    const label = (s: string) => t(`inv.inv.status.${s}` as MessageKey);
    const reload = ['invoice'];

    function sentence(v: Variance): string {
        const item = v.item ?? '';
        const actual = qty(v.actual_milli ?? 0);
        const expected = qty(v.expected_milli ?? 0);

        switch (v.type) {
            case 'quantity': return t('inv.inv.variance.quantity', { item, actual, expected });
            case 'not_received': return t('inv.inv.variance.not_received', { item, actual });
            case 'price': return t('inv.inv.variance.price', { item, actual: money(v.actual_minor ?? 0), expected: money(v.expected_minor ?? 0) });
            case 'tax': return t('inv.inv.variance.tax', { actual: money(v.actual_minor ?? 0), expected: money(v.expected_minor ?? 0) });
            case 'tax_document': return t('inv.inv.variance.tax_document');
            default: return v.type;
        }
    }

    function open(kind: 'approve' | 'reject') {
        action.clear();
        setNote('');
        setDeciding(kind);
    }

    async function decide() {
        if (deciding === null) return;
        const path = deciding === 'approve' ? `/inventory/invoices/${invoice.id}/approve` : `/inventory/invoices/${invoice.id}/reject`;
        const done = await action.run(path, { body: { note: note.trim(), lock_version: invoice.lock_version }, reload });
        if (done !== null) { setDeciding(null); setNote(''); }
    }

    async function upload(file: File | undefined) {
        if (file === undefined) return;
        const body = new FormData();
        body.set('document', file);
        await action.run(`/inventory/invoices/${invoice.id}/documents`, { body, reload });
        setPickerKey((k) => k + 1);
    }

    const facts: [string, string][] = [
        [t('inv.rcv.col.supplier'), `${invoice.supplier.code} · ${invoice.supplier.name}`],
        [t('inv.rcv.col.order'), invoice.order.number],
        [t('inv.inv.col.invoiceDate'), format.date(invoice.invoice_date)],
        [t('inv.inv.col.dueDate'), format.date(invoice.due_date)],
        [t('inv.inv.taxNumber'), invoice.tax_number ?? '—'],
        [t('inv.inv.col.enteredBy'), invoice.entered_by_name ?? '—'],
        [t('inv.inv.subtotal'), money(invoice.subtotal_minor)],
        [t('inv.inv.taxAmount'), money(invoice.tax_minor)],
        [t('inv.inv.col.total'), money(invoice.total_minor)],
        [t('inv.col.note'), invoice.note ?? '—'],
    ];
    const decided = invoice.status === 'approved' || invoice.status === 'rejected';

    return (
        <InventoryShell
            actions={<div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild variant="outline"><Link href="/inventory/invoices">{t('inv.inv.back')}</Link></Button>
                {invoice.may_resolve ? <Button onClick={() => open('reject')} type="button" variant="outline">{t('inv.inv.reject')}</Button> : null}
                {invoice.may_resolve ? <Button onClick={() => open('approve')} type="button">{t('inv.inv.approve')}</Button> : null}
                <Button onClick={() => window.print()} type="button" variant="outline">{t('inv.rcv.print')}</Button>
            </div>}
            description={t('inv.inv.detailDescription')}
            title={`${invoice.number} · ${invoice.invoice_number}`}
            wide
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" data-testid="invoice-head">
                <StatusBadge label={label(invoice.status)} tone={INVOICE_TONE[invoice.status] ?? 'neutral'} />
                {invoice.status === 'variance' ? <span className="text-muted-foreground">{t('inv.inv.waiting')}</span> : null}
            </div>

            {action.error !== null && deciding === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            {invoice.resolve_blocked_self ? <Alert title={t('inv.inv.selfDecision')} tone="info" /> : null}
            {decided ? (
                <Alert title={`${label(invoice.status)}: ${invoice.decided_by_name ?? '—'}${invoice.decided_at ? ` · ${format.instant(invoice.decided_at)}` : ''}`} tone={invoice.status === 'approved' ? 'success' : 'danger'}>
                    {invoice.decision_note !== null ? <p data-testid="decision-note">{t('inv.inv.decisionNote')}: {invoice.decision_note}</p> : null}
                </Alert>
            ) : null}

            <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-5" data-testid="invoice-facts">
                {facts.map(([name, value]) => (
                    <div key={name}>
                        <dt className="text-xs text-muted-foreground">{name}</dt>
                        <dd className="break-words">{value}</dd>
                    </div>
                ))}
            </dl>

            <div className="overflow-x-auto border border-border bg-surface">
                <Table data-testid="invoice-lines">
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('inv.col.item')}</TableHead>
                            <TableHead className="text-right">{t('inv.inv.invoiceQty')}</TableHead>
                            <TableHead className="text-right">{t('inv.inv.receivedOpen')}</TableHead>
                            <TableHead className="text-right">{t('inv.inv.invoicePrice')}</TableHead>
                            <TableHead className="text-right">{t('inv.inv.orderPrice')}</TableHead>
                            <TableHead className="text-right">{t('inv.col.value')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {invoice.lines.map((l) => (
                            <TableRow data-testid={`invoice-line-${l.item_code}`} key={l.id}>
                                <TableCell><span className="font-medium">{l.item_code}</span> <span className="text-muted-foreground">{l.item_name}</span></TableCell>
                                <TableCell className="text-right">{qty(l.qty_milli)} {l.unit}</TableCell>
                                <TableCell className="text-right">{qty(l.open_received_milli)} {l.unit}</TableCell>
                                <TableCell className={l.unit_price_minor !== l.order_price_minor ? 'text-right font-medium text-warning' : 'text-right'}>{money(l.unit_price_minor)}</TableCell>
                                <TableCell className="text-right">{money(l.order_price_minor)}</TableCell>
                                <TableCell className="text-right">{money(l.line_total_minor)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            <section aria-labelledby="inv-match-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="inv-match-h">{t('inv.inv.match')}</h2>
                {invoice.variances.length === 0 ? <Alert title={t('inv.inv.matchClean')} tone="success" /> : (
                    <Alert title={t('inv.inv.matchDifferences', { count: invoice.variances.length })} tone="warning">
                        <ul className="mt-1 list-disc pl-5" data-testid="invoice-variances">
                            {invoice.variances.map((v, i) => <li key={i}>{sentence(v)}</li>)}
                        </ul>
                    </Alert>
                )}
            </section>

            <section aria-labelledby="inv-docs-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="inv-docs-h">{t('inv.inv.documents')}</h2>
                {invoice.documents.length === 0 ? <p className="text-sm text-muted-foreground">{t('inv.inv.noDocuments')}</p> : (
                    <ul className="flex flex-col gap-1 text-sm" data-testid="invoice-documents">
                        {invoice.documents.map((d) => (
                            <li key={d.id}><a className="underline" href={`/inventory/invoices/${invoice.id}/documents/${d.id}`} rel="noreferrer" target="_blank">{d.name ?? t('inv.inv.document')}</a></li>
                        ))}
                    </ul>
                )}
                {invoice.may_document && invoice.documents.length < invoice.max_documents ? (
                    <div className="max-w-sm print:hidden">
                        <FormField error={action.fieldError('document')} field="document" label={t('inv.inv.addDocument')}>
                            <Input accept="application/pdf,image/jpeg,image/png" key={pickerKey} onChange={(e) => void upload(e.target.files?.[0])} type="file" />
                        </FormField>
                    </div>
                ) : null}
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setDeciding(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void decide()} type="button">{deciding === 'reject' ? t('inv.inv.reject') : t('inv.inv.approve')}</Button>
                </>}
                onClose={() => setDeciding(null)}
                open={deciding !== null}
                title={deciding === 'reject' ? t('inv.inv.rejectTitle', { number: invoice.number }) : t('inv.inv.approveTitle', { number: invoice.number })}
            >
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">{deciding === 'reject' ? t('inv.inv.rejectHint') : t('inv.inv.approveHint')}</p>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={action.fieldError('note')} field="note" label={t('inv.inv.decisionNote')}>
                        <Input maxLength={200} onChange={(e) => setNote(e.target.value)} value={note} />
                    </FormField>
                </div>
            </Dialog>
        </InventoryShell>
    );
}
