import { Link } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Metric } from '@/components/ui/metric';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { CORRECTION_TONE, DEFAULT_CURRENCY, REVENUE_TONE, signClass, useOutletLabel, useReceiptMethodLabel, useSignedMoney, type MethodTotals, type RevenueAmounts } from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Line = RevenueAmounts & { source: string; outlet_code: string; outlet_name: string | null };
type DayCorrection = { id: string; number: string; status: 'pending' | 'approved' | 'rejected'; reason: string; revenue_minor: number; received_minor: number; effective_date: string | null };
type Day = RevenueAmounts & {
    id: string; date: string; currency: string; collected_minor: number; status: 'recorded' | 'verified'; verified_at: string | null;
    lines: Line[]; payments: MethodTotals[]; night_audit_id: string | null; occurred_at: string; booked_by: string | null; verified_by: string | null; verification_note: string | null;
    blockers: { waiting: number; open: number; exceptions: number }; corrections: DayCorrection[]; may_verify: boolean; may: { verify: boolean };
};

/** One closed day: its statement, what stops it being verified, and the verification itself. */
export default function RevenueDayPage({ day }: { day: Day }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const outletLabel = useOutletLabel();
    const methodLabel = useReceiptMethodLabel();
    const signed = useSignedMoney();
    const [verifying, setVerifying] = useState(false);
    const [note, setNote] = useState('');
    const money = (minor: number) => format.money(minor, day.currency);
    const verified = day.status === 'verified';
    const blocked = !verified && (day.blockers.waiting > 0 || day.blockers.open > 0 || day.blockers.exceptions > 0);
    const date = format.date(day.date, 'long');

    function openVerify() {
        action.clear();
        setNote('');
        setVerifying(true);
    }

    async function verify() {
        const text = note.trim();
        const done = await action.run(`/finance/revenue/${day.date}/verify`, { body: text === '' ? {} : { note: text }, reload: ['day'] });
        if (done !== null) { setVerifying(false); setNote(''); }
    }

    const lineColumns: DataGridColumn<Line>[] = [
        { id: 'outlet', label: t('fin.rev.colOutlet'), value: (l) => outletLabel(l.outlet_code, l.outlet_name), rowHeader: true },
        { id: 'source', label: t('fin.day.colSource'), value: (l) => l.source },
        { id: 'base', label: t('fin.rev.base'), align: 'right', value: (l) => l.base_minor, cell: (l) => money(l.base_minor), footer: money(day.base_minor) },
        { id: 'service', label: t('fin.rev.service'), align: 'right', value: (l) => l.service_charge_minor, cell: (l) => money(l.service_charge_minor), footer: money(day.service_charge_minor) },
        { id: 'tax', label: t('fin.rev.tax'), align: 'right', value: (l) => l.tax_minor, cell: (l) => money(l.tax_minor), footer: money(day.tax_minor) },
        { id: 'total', label: t('fin.rev.billed'), align: 'right', value: (l) => l.total_minor, cell: (l) => money(l.total_minor), footer: money(day.total_minor) },
    ];

    const paymentColumns: DataGridColumn<MethodTotals>[] = [
        { id: 'method', label: t('fin.col.method'), value: (p) => methodLabel(p.method), rowHeader: true },
        { id: 'received', label: t('fin.rev.colReceived'), align: 'right', value: (p) => p.received_minor, cell: (p) => money(p.received_minor) },
        { id: 'paidBack', label: t('fin.rev.colPaidBack'), align: 'right', value: (p) => p.paid_back_minor, cell: (p) => money(p.paid_back_minor) },
        { id: 'net', label: t('fin.rev.colNet'), align: 'right', value: (p) => p.net_minor, cell: (p) => money(p.net_minor), footer: money(day.collected_minor) },
        { id: 'entries', label: t('fin.rev.colEntries'), align: 'right', value: (p) => p.entries },
    ];

    const correctionColumns: DataGridColumn<DayCorrection>[] = [
        { id: 'number', label: t('fin.cor.colNumber'), value: (c) => c.number, cell: (c) => <Link className="font-medium underline" href={`/finance/corrections/${c.id}`}>{c.number}</Link>, rowHeader: true },
        { id: 'status', label: t('fin.cor.colStatus'), value: (c) => c.status, cell: (c) => <StatusBadge label={t(`fin.cor.status.${c.status}` as MessageKey)} tone={CORRECTION_TONE[c.status] ?? 'neutral'} /> },
        { id: 'reason', label: t('fin.cor.colReason'), value: (c) => c.reason },
        { id: 'revenue', label: t('fin.cor.colRevenue'), align: 'right', value: (c) => c.revenue_minor, cell: (c) => <span className={signClass(c.revenue_minor)}>{signed(c.revenue_minor, day.currency || DEFAULT_CURRENCY)}</span> },
        { id: 'received', label: t('fin.cor.colReceived'), align: 'right', value: (c) => c.received_minor, cell: (c) => <span className={signClass(c.received_minor)}>{signed(c.received_minor, day.currency || DEFAULT_CURRENCY)}</span> },
        { id: 'effective', label: t('fin.cor.colEffective'), value: (c) => c.effective_date ?? '', cell: (c) => (c.effective_date === null ? '—' : format.date(c.effective_date)) },
    ];

    const facts: [string, string][] = [
        [t('fin.day.bookedAt'), format.instant(day.occurred_at)],
        [t('fin.day.bookedBy'), day.booked_by ?? '—'],
    ];

    return (
        <FinanceShell
            actions={<div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild variant="outline"><Link href="/finance/revenue">{t('fin.day.back')}</Link></Button>
                {day.may_verify ? <Button onClick={openVerify} type="button">{t('fin.day.verify')}</Button> : null}
                <Button onClick={() => window.print()} type="button" variant="outline">{t('fin.print')}</Button>
            </div>}
            description={t('fin.day.description')}
            title={t('fin.day.title', { date })}
            wide
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" data-testid="revenue-day-head">
                <StatusBadge label={t(`fin.rev.${day.status}` as MessageKey)} tone={REVENUE_TONE[day.status] ?? 'neutral'} />
                {facts.map(([name, value]) => <span key={name}><span className="text-muted-foreground">{name}: </span>{value}</span>)}
            </div>

            {action.error !== null && !verifying ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}

            {blocked ? (
                <Alert title={t('fin.day.blockedTitle')} tone="warning">
                    <div className="flex flex-col gap-2" data-testid="revenue-day-blockers">
                        <p>{t('fin.day.blockedHint')}</p>
                        {day.blockers.waiting > 0 ? (
                            <p className="flex flex-wrap items-center gap-x-3">
                                <span>{t('fin.day.waiting', { count: day.blockers.waiting })}</span>
                                <Link className="underline" href={`/finance/cash?from=${day.date}&to=${day.date}&only=waiting`}>{t('fin.day.toWaiting')}</Link>
                            </p>
                        ) : null}
                        {day.blockers.open > 0 ? (
                            <p className="flex flex-wrap items-center gap-x-3">
                                <span>{t('fin.day.open', { count: day.blockers.open })}</span>
                                <Link className="underline" href="/finance/cash?tab=exceptions">{t('fin.day.toOpen')}</Link>
                            </p>
                        ) : null}
                        {day.blockers.exceptions > 0 ? (
                            <p className="flex flex-wrap items-center gap-x-3">
                                <span>{t('fin.day.exceptions', { count: day.blockers.exceptions })}</span>
                                <Link className="underline" href="/finance/exceptions?status=open">{t('fin.day.toExceptions')}</Link>
                            </p>
                        ) : null}
                    </div>
                </Alert>
            ) : null}

            {verified ? (
                <Alert title={t('fin.day.verifiedTitle')} tone="success">
                    <div className="flex flex-col gap-1" data-testid="revenue-day-verified">
                        <p>{t('fin.day.verifiedBy', { name: day.verified_by ?? '—', when: day.verified_at === null ? '—' : format.instant(day.verified_at) })}</p>
                        {day.verification_note !== null ? <p>{t('fin.day.verifiedNote')}: {day.verification_note}</p> : null}
                        <p className="text-muted-foreground">{t('fin.day.final')}</p>
                    </div>
                </Alert>
            ) : null}

            <section aria-label={t('fin.day.statement')} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5" data-testid="revenue-day-statement">
                <Metric label={t('fin.rev.base')} value={money(day.base_minor)} />
                <Metric label={t('fin.rev.service')} value={money(day.service_charge_minor)} />
                <Metric label={t('fin.rev.tax')} value={money(day.tax_minor)} />
                <Metric label={t('fin.rev.billed')} value={money(day.total_minor)} />
                <Metric label={t('fin.rev.collected')} value={money(day.collected_minor)} />
            </section>

            <Tabs defaultValue="fin-day-lines-h">
                <TabsList>
                    <TabsTrigger value="fin-day-lines-h">{t('fin.day.lines')}</TabsTrigger>
                    <TabsTrigger value="fin-day-payments-h">{t('fin.day.payments')}</TabsTrigger>
                    <TabsTrigger value="fin-day-corrections-h">{t('fin.day.corrections')}</TabsTrigger>
                </TabsList>

            <TabsContent className="flex flex-col gap-3" value="fin-day-lines-h">
                <h2 className="sr-only" id="fin-day-lines-h">{t('fin.day.lines')}</h2>
                <DataGrid caption={t('fin.day.lines')} columns={lineColumns} empty={<EmptyState title={t('fin.day.linesEmpty')} />} footerLabel={t('fin.age.total')} getRowId={(l) => `${l.source}:${l.outlet_code}`} id="fin.revenue.day.lines" rows={day.lines} testId="revenue-day-lines" />
            </TabsContent>

            <TabsContent className="flex flex-col gap-3" value="fin-day-payments-h">
                <h2 className="sr-only" id="fin-day-payments-h">{t('fin.day.payments')}</h2>
                <DataGrid caption={t('fin.day.payments')} columns={paymentColumns} empty={<EmptyState title={t('fin.day.paymentsEmpty')} />} footerLabel={t('fin.age.total')} getRowId={(p) => p.method} id="fin.revenue.day.payments" rows={day.payments} testId="revenue-day-payments" />
            </TabsContent>

            <TabsContent className="flex flex-col gap-3" value="fin-day-corrections-h">
                <h2 className="sr-only" id="fin-day-corrections-h">{t('fin.day.corrections')}</h2>
                <p className="text-sm text-muted-foreground">{t(verified ? 'fin.day.correctionsVerified' : 'fin.day.correctionsHint')}</p>
                {day.corrections.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.day.correctionsEmpty')}</p> : (
                    <DataGrid caption={t('fin.day.corrections')} columns={correctionColumns} getRowId={(c) => c.id} id="fin.revenue.day.corrections" rows={day.corrections} testId="revenue-day-corrections" />
                )}
                <p className="print:hidden"><Link className="underline" href={`/finance/corrections?date=${day.date}`}>{t('fin.day.correct')}</Link></p>
            </TabsContent>
            </Tabs>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setVerifying(false)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void verify()} type="button">{t('fin.day.confirm')}</Button>
                </>}
                onClose={() => setVerifying(false)}
                open={verifying}
                title={t('fin.day.verifyTitle', { date })}
            >
                <div className="flex flex-col gap-3">
                    <p className="text-sm text-muted-foreground">{t('fin.day.verifyHint')}</p>
                    {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                    <FormField error={action.fieldError('note')} field="note" label={t('fin.day.note')}>
                        <Textarea maxLength={300} onChange={(e) => setNote(e.target.value)} value={note} />
                    </FormField>
                </div>
            </Dialog>
        </FinanceShell>
    );
}
