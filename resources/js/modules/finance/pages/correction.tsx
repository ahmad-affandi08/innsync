import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { CORRECTION_TONE, DEFAULT_CURRENCY, signClass, useOutletLabel, useReceiptMethodLabel, useSignedMoney, type CorrectionHead } from '@/modules/finance/lib/finance';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Line = {
    kind: 'revenue' | 'payment'; outlet_code: string | null; outlet_name: string | null; method: string | null;
    base_minor: number; service_charge_minor: number; tax_minor: number; total_minor: number; received_minor: number;
};
type Correction = CorrectionHead & { decision_note: string | null; lock_version: number; lines: Line[]; may_decide: boolean };
type Row = Line & { n: number };
type DecideForm = { decision: 'approve' | 'reject'; note: string };

const currency = DEFAULT_CURRENCY;

/** One correction of a booked day: what it adds or takes, who asked, and the decision of the person who checks it. */
export default function CorrectionPage({ correction }: { correction: Correction }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const outletLabel = useOutletLabel();
    const methodLabel = useReceiptMethodLabel();
    const signed = useSignedMoney();
    const [form, setForm] = useState<DecideForm | null>(null);
    const status = (s: string) => t(`fin.cor.status.${s}` as MessageKey);
    const money = (minor: number) => <span className={signClass(minor)}>{signed(minor, currency)}</span>;
    const pending = correction.status === 'pending';
    const reload = ['correction'];
    const date = format.date(correction.day_date, 'long');

    function openDecide() {
        action.clear();
        setForm({ decision: 'approve', note: '' });
    }

    async function decide() {
        if (form === null) return;
        const done = await action.run(`/finance/corrections/${correction.id}/decide`, {
            body: { decision: form.decision, note: form.note.trim() || null, lock_version: correction.lock_version },
            reload,
        });
        if (done !== null) setForm(null);
    }

    const facts: [string, ReactNode][] = [
        [t('fin.cor.colDay'), <Link className="underline" href={`/finance/revenue/${correction.day_date}`} key="day">{date}</Link>],
        [t('fin.cor.colRequestedBy'), `${correction.requested_by ?? '—'} · ${format.instant(correction.requested_at)}`],
        [t('fin.cor.colDecidedBy'), correction.decided_by ?? '—'],
        [t('fin.cor.colEffective'), correction.effective_date === null ? '—' : format.date(correction.effective_date, 'long')],
    ];

    const rows: Row[] = correction.lines.map((l, n) => ({ ...l, n }));
    const columns: DataGridColumn<Row>[] = [
        { id: 'kind', label: t('fin.cor.colKind'), value: (l) => l.kind, filter: 'select', filterLabel: (v) => t(v === 'revenue' ? 'fin.cor.lineRevenue' : 'fin.cor.linePayment'), cell: (l) => t(l.kind === 'revenue' ? 'fin.cor.lineRevenue' : 'fin.cor.linePayment'), rowHeader: true },
        {
            id: 'what', label: t('fin.cor.colWhat'), value: (l) => (l.kind === 'revenue' ? outletLabel(l.outlet_code ?? '', l.outlet_name) : methodLabel(l.method ?? '')),
        },
        { id: 'base', label: t('fin.rev.base'), align: 'right', value: (l) => l.base_minor, cell: (l) => (l.kind === 'revenue' ? money(l.base_minor) : '—') },
        { id: 'service', label: t('fin.rev.service'), align: 'right', value: (l) => l.service_charge_minor, cell: (l) => (l.kind === 'revenue' ? money(l.service_charge_minor) : '—') },
        { id: 'tax', label: t('fin.rev.tax'), align: 'right', value: (l) => l.tax_minor, cell: (l) => (l.kind === 'revenue' ? money(l.tax_minor) : '—') },
        { id: 'total', label: t('fin.cor.colRevenue'), align: 'right', value: (l) => l.total_minor, cell: (l) => (l.kind === 'revenue' ? money(l.total_minor) : '—'), footer: money(correction.revenue_minor) },
        { id: 'received', label: t('fin.cor.colReceived'), align: 'right', value: (l) => l.received_minor, cell: (l) => (l.kind === 'payment' ? money(l.received_minor) : '—'), footer: money(correction.received_minor) },
    ];

    return (
        <FinanceShell
            actions={<div className="flex flex-wrap gap-2 print:hidden">
                <Button asChild variant="outline"><Link href="/finance/corrections">{t('fin.cor.back')}</Link></Button>
                {correction.may_decide ? <Button onClick={openDecide} type="button">{t('fin.cor.decide')}</Button> : null}
                <Button onClick={() => window.print()} type="button" variant="outline">{t('fin.print')}</Button>
            </div>}
            description={t('fin.cor.pageDescription')}
            title={t('fin.cor.pageTitle', { number: correction.number, date })}
            wide
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" data-testid="correction-head">
                <StatusBadge label={status(correction.status)} tone={CORRECTION_TONE[correction.status] ?? 'neutral'} />
            </div>

            {pending && !correction.may_decide ? <Alert title={t('fin.cor.cannotDecide')} tone="info" /> : null}
            {correction.status === 'approved' ? (
                <Alert title={status('approved')} tone="success">
                    <p data-testid="correction-effective">{t('fin.cor.approvedBody', { date: correction.effective_date === null ? '—' : format.date(correction.effective_date, 'long'), day: date })}</p>
                </Alert>
            ) : null}
            {correction.decision_note !== null ? (
                <Alert title={status(correction.status)} tone={correction.status === 'rejected' ? 'danger' : 'info'}>
                    <p data-testid="correction-decision-note">{t('fin.cor.decisionNote')}: {correction.decision_note}</p>
                </Alert>
            ) : null}
            {correction.status === 'rejected' ? <p className="text-sm text-muted-foreground">{t('fin.cor.rejectedBody')}</p> : null}

            <dl className="grid gap-x-6 gap-y-3 border border-border bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-4" data-testid="correction-facts">
                {facts.map(([name, value]) => (
                    <div key={name}>
                        <dt className="text-xs text-muted-foreground">{name}</dt>
                        <dd className="break-words">{value}</dd>
                    </div>
                ))}
                <div className="sm:col-span-2 lg:col-span-4">
                    <dt className="text-xs text-muted-foreground">{t('fin.cor.colReason')}</dt>
                    <dd className="break-words">{correction.reason}</dd>
                </div>
            </dl>

            <section aria-labelledby="fin-cor-lines-h" className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold" id="fin-cor-lines-h">{t('fin.cor.lines')}</h2>
                <p className="text-sm text-muted-foreground">{t('fin.cor.pageLinesHint')}</p>
                <DataGrid caption={t('fin.cor.lines')} columns={columns} empty={<EmptyState title={t('fin.cor.linesEmpty')} />} footerLabel={t('fin.age.total')} getRowId={(l) => String(l.n)} id="fin.correction.lines" rows={rows} testId="correction-lines" />
            </section>

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void decide()} type="button">{form?.decision === 'reject' ? t('fin.cor.reject') : t('fin.cor.approve')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.cor.decideTitle', { number: correction.number })}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.cor.decideHint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={action.fieldError('decision')} field="decision" label={t('fin.cor.decision')}>
                            <Select onChange={(e) => setForm({ ...form, decision: e.target.value === 'reject' ? 'reject' : 'approve' })} searchable={false} value={form.decision}>
                                <option value="approve">{t('fin.cor.approve')}</option>
                                <option value="reject">{t('fin.cor.reject')}</option>
                            </Select>
                        </FormField>
                        <FormField error={action.fieldError('note')} field="note" hint={form.decision === 'reject' ? t('fin.cor.rejectHint') : t('fin.cor.noteHint')} label={t('fin.cor.note')} required={form.decision === 'reject'}>
                            <Textarea maxLength={300} onChange={(e) => setForm({ ...form, note: e.target.value })} value={form.note} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
