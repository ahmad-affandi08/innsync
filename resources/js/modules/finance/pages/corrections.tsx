import { Link, router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import {
    CORRECTION_STATUSES, CORRECTION_TONE, DEFAULT_CURRENCY, parseSignedMajorToMinor, signClass, useOutletLabel, useReceiptMethodLabel, useSignedMoney, type CorrectionHead,
} from '@/modules/finance/lib/finance';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import type { MessageKey } from '@/locales/en/index';

type Overview = { corrections: CorrectionHead[]; methods: string[]; outlets: { code: string; name: string | null }[]; may: { request: boolean; decide: boolean } };
type LineDraft = { key: number; kind: 'revenue' | 'payment'; outlet: string; base: string; service: string; tax: string; method: string; received: string };
type Form = { date: string; reason: string; lines: LineDraft[] };

const MAX_LINES = 20;
const currency = DEFAULT_CURRENCY;

/** An empty part of a revenue line is 0; text that is not a clear amount is null. */
const parsePart = (text: string): number | null => (text.trim() === '' ? 0 : parseSignedMajorToMinor(text, currency));

/** The money a draft line changes, or null while one of its amounts is not clear. */
function lineMinor(line: LineDraft): { revenue: number; received: number } | null {
    if (line.kind === 'payment') {
        const received = parsePart(line.received);

        return received === null ? null : { revenue: 0, received };
    }

    const parts = [parsePart(line.base), parsePart(line.service), parsePart(line.tax)];

    return parts.some((p) => p === null) ? null : { revenue: parts.reduce<number>((sum, p) => sum + (p ?? 0), 0), received: 0 };
}

/** The corrections of booked revenue days, and the form that asks for a new one. */
export default function CorrectionsPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const outletLabel = useOutletLabel();
    const methodLabel = useReceiptMethodLabel();
    const signed = useSignedMoney();
    const url = usePage().url;
    const keys = useRef(0);
    const blankLine = (kind: LineDraft['kind']): LineDraft => ({ key: ++keys.current, kind, outlet: '', base: '', service: '', tax: '', method: '', received: '' });
    const [form, setForm] = useState<Form | null>(() => {
        const date = new URLSearchParams(url.split('?')[1] ?? '').get('date') ?? '';

        return overview.may.request && /^\d{4}-\d{2}-\d{2}$/.test(date) ? { date, reason: '', lines: [blankLine('revenue')] } : null;
    });
    const [attempted, setAttempted] = useState(false);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(form)]);
    const statusLabel = (s: string) => t(`fin.cor.status.${s}` as MessageKey);
    const money = (minor: number) => <span className={signClass(minor)}>{signed(minor, currency)}</span>;

    function filter(next: string) {
        router.get('/finance/corrections', next === '' ? {} : { status: next }, { preserveScroll: true, preserveState: true });
    }

    function openNew() {
        action.clear();
        setAttempted(false);
        setForm({ date: '', reason: '', lines: [blankLine('revenue')] });
    }

    function setLine(key: number, patch: Partial<LineDraft>) {
        if (form === null) return;
        setForm({ ...form, lines: form.lines.map((l) => (l.key === key ? { ...l, ...patch } : l)) });
    }

    function addLine(kind: LineDraft['kind']) {
        if (form === null || form.lines.length >= MAX_LINES) return;
        setForm({ ...form, lines: [...form.lines, blankLine(kind)] });
    }

    function removeLine(key: number) {
        if (form === null) return;
        setForm({ ...form, lines: form.lines.filter((l) => l.key !== key) });
    }

    /** What is wrong with a line, in words, or nothing when it can be sent. */
    function problem(line: LineDraft): string | undefined {
        const minor = lineMinor(line);

        if (line.kind === 'revenue' && line.outlet === '') return t('fin.cor.chooseOutlet');
        if (line.kind === 'payment' && line.method === '') return t('fin.cor.chooseMethod');
        if (minor === null) return t('fin.cor.badAmount');
        if (line.kind === 'revenue' && minor.revenue === 0) return t('fin.cor.revenueZero');
        if (line.kind === 'payment' && minor.received === 0) return t('fin.cor.paymentZero');

        return undefined;
    }

    const totals = (form?.lines ?? []).reduce((sum, l) => {
        const minor = lineMinor(l);

        return minor === null ? sum : { revenue: sum.revenue + minor.revenue, received: sum.received + minor.received };
    }, { revenue: 0, received: 0 });
    const invalid = form !== null && (form.date === '' || form.reason.trim() === '' || form.lines.length === 0 || form.lines.some((l) => problem(l) !== undefined));

    async function create() {
        if (form === null) return;
        setAttempted(true);
        if (invalid) return;
        const done = await action.run<{ correction: { id: string } }>('/finance/corrections', {
            body: {
                date: form.date,
                reason: form.reason.trim(),
                lines: form.lines.map((l) => (l.kind === 'revenue'
                    ? { kind: 'revenue', outlet_code: l.outlet, base_minor: parsePart(l.base), service_charge_minor: parsePart(l.service), tax_minor: parsePart(l.tax) }
                    : { kind: 'payment', method: l.method, received_minor: parsePart(l.received) })),
            },
            idempotencyKey: intent,
        });
        if (done !== null) router.visit(`/finance/corrections/${done.correction.id}`);
    }

    const columns: DataGridColumn<CorrectionHead>[] = [
        { id: 'number', label: t('fin.cor.colNumber'), value: (c) => c.number, cell: (c) => <Link className="font-medium underline" href={`/finance/corrections/${c.id}`}>{c.number}</Link>, rowHeader: true },
        { id: 'day', label: t('fin.cor.colDay'), value: (c) => c.day_date, cell: (c) => <Link className="underline" href={`/finance/revenue/${c.day_date}`}>{format.date(c.day_date)}</Link> },
        { id: 'status', label: t('fin.cor.colStatus'), value: (c) => c.status, filter: 'select', filterLabel: statusLabel, cell: (c) => <StatusBadge label={statusLabel(c.status)} tone={CORRECTION_TONE[c.status] ?? 'neutral'} /> },
        { id: 'reason', label: t('fin.cor.colReason'), value: (c) => c.reason },
        { id: 'lines', label: t('fin.cor.colLines'), align: 'right', value: (c) => c.line_count, cell: (c) => format.number(c.line_count), hidden: true },
        { id: 'revenue', label: t('fin.cor.colRevenue'), align: 'right', value: (c) => c.revenue_minor, cell: (c) => money(c.revenue_minor) },
        { id: 'received', label: t('fin.cor.colReceived'), align: 'right', value: (c) => c.received_minor, cell: (c) => money(c.received_minor) },
        { id: 'requestedBy', label: t('fin.cor.colRequestedBy'), value: (c) => c.requested_by ?? '', cell: (c) => `${c.requested_by ?? '—'} · ${format.instant(c.requested_at)}` },
        { id: 'decidedBy', label: t('fin.cor.colDecidedBy'), value: (c) => c.decided_by ?? '', cell: (c) => c.decided_by ?? '—' },
        { id: 'effective', label: t('fin.cor.colEffective'), value: (c) => c.effective_date ?? '', cell: (c) => (c.effective_date === null ? '—' : format.date(c.effective_date)) },
        { id: 'actions', label: t('inv.col.actions'), cell: (c) => <Button onClick={() => router.visit(`/finance/corrections/${c.id}`)} size="sm" type="button" variant="outline">{t('fin.pay.open')}</Button> },
    ];

    return (
        <FinanceShell actions={overview.may.request ? <Button onClick={openNew} type="button">{t('fin.cor.new')}</Button> : undefined} description={t('fin.cor.description')} title={t('fin.cor.title')} wide>
            <Alert title={t('fin.cor.explainTitle')} tone="info">
                <ul className="flex list-disc flex-col gap-1 pl-5" data-testid="corrections-explain">
                    <li>{t('fin.cor.explainBooked')}</li>
                    <li>{t('fin.cor.explainJournal')}</li>
                    <li>{t('fin.cor.explainApproval')}</li>
                    <li>{t('fin.cor.explainEffective')}</li>
                </ul>
            </Alert>

            <div className="flex flex-wrap gap-3 print:hidden">
                <div className="w-full max-w-xs">
                    <Select aria-label={t('fin.cor.colStatus')} onChange={(e) => filter(e.target.value)} searchable={false} value={status}>
                        <option value="">{t('fin.cor.allStatuses')}</option>
                        {CORRECTION_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)}</option>)}
                    </Select>
                </div>
            </div>

            <DataGrid caption={t('fin.cor.title')} columns={columns} empty={<EmptyState title={t('fin.cor.empty')} />} getRowId={(c) => c.id} id="fin.corrections" rows={overview.corrections} testId="corrections" />

            <Dialog
                className="w-[min(46rem,calc(100vw-2rem))]"
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void create()} type="button">{t('fin.cor.create')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.cor.newTitle')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-4">
                        <p className="text-sm text-muted-foreground">{t('fin.cor.newHint')}</p>
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField error={attempted && form.date === '' ? t('fin.cor.dateRequired') : action.fieldError('date')} field="date" hint={t('fin.cor.dateHint')} label={t('fin.cor.day')}>
                                <DatePicker onChange={(e) => setForm({ ...form, date: e.target.value })} value={form.date} />
                            </FormField>
                            <div className="sm:col-span-2">
                                <FormField error={attempted && form.reason.trim() === '' ? t('fin.cor.reasonRequired') : action.fieldError('reason')} field="reason" hint={t('fin.cor.reasonHint')} label={t('fin.cor.reason')}>
                                    <Textarea maxLength={300} onChange={(e) => setForm({ ...form, reason: e.target.value })} value={form.reason} />
                                </FormField>
                            </div>
                        </div>

                        <section aria-labelledby="fin-cor-lines-h" className="flex flex-col gap-3">
                            <h3 className="text-base font-semibold" id="fin-cor-lines-h">{t('fin.cor.lines')}</h3>
                            <p className="text-sm text-muted-foreground">{t('fin.cor.linesHint')}</p>
                            {overview.outlets.length === 0 ? <p className="text-sm text-muted-foreground">{t('fin.cor.noOutlets')}</p> : null}
                            {action.fieldError('lines') !== undefined ? <p className="text-sm text-danger" role="alert">{action.fieldError('lines')}</p> : null}
                            {attempted && form.lines.length === 0 ? <p className="text-sm text-danger" role="alert">{t('fin.cor.noLines')}</p> : null}

                            <ul className="flex flex-col gap-3" data-testid="correction-lines">
                                {form.lines.map((line, index) => {
                                    const minor = lineMinor(line);
                                    const issue = attempted ? problem(line) : undefined;
                                    const name = line.kind === 'revenue' ? t('fin.cor.lineRevenue') : t('fin.cor.linePayment');

                                    return (
                                        <li className="flex flex-col gap-3 border border-border bg-surface-muted p-3" key={line.key}>
                                            <div className="flex items-center justify-between gap-3">
                                                <h4 className="text-sm font-semibold">{t('fin.cor.lineTitle', { number: index + 1, kind: name })}</h4>
                                                <Button aria-label={t('fin.cor.removeLine', { number: index + 1 })} onClick={() => removeLine(line.key)} size="sm" type="button" variant="ghost"><Trash2 aria-hidden="true" className="size-4" />{t('fin.cor.remove')}</Button>
                                            </div>
                                            {line.kind === 'revenue' ? (
                                                <div className="grid gap-3 sm:grid-cols-3">
                                                    <div className="sm:col-span-3">
                                                        <FormField field="lines.*.outlet_code" label={t('fin.rev.colOutlet')} required>
                                                            <Select onChange={(e) => setLine(line.key, { outlet: e.target.value })} value={line.outlet}>
                                                                <option value="">{t('fin.cor.outletChoose')}</option>
                                                                {overview.outlets.map((o) => <option key={o.code} value={o.code}>{outletLabel(o.code, o.name)}</option>)}
                                                            </Select>
                                                        </FormField>
                                                    </div>
                                                    <FormField field="lines.*.base_minor" label={t('fin.cor.base', { currency })}>
                                                        <Input inputMode="decimal" onChange={(e) => setLine(line.key, { base: e.target.value })} value={line.base} />
                                                    </FormField>
                                                    <FormField field="lines.*.service_charge_minor" label={t('fin.cor.service', { currency })}>
                                                        <Input inputMode="decimal" onChange={(e) => setLine(line.key, { service: e.target.value })} value={line.service} />
                                                    </FormField>
                                                    <FormField field="lines.*.tax_minor" label={t('fin.cor.tax', { currency })}>
                                                        <Input inputMode="decimal" onChange={(e) => setLine(line.key, { tax: e.target.value })} value={line.tax} />
                                                    </FormField>
                                                    {minor !== null ? <p className="text-sm sm:col-span-3">{t('fin.cor.lineTotal')}: <span className="font-medium tabular-nums">{money(minor.revenue)}</span></p> : null}
                                                </div>
                                            ) : (
                                                <div className="grid gap-3 sm:grid-cols-2">
                                                    <FormField field="lines.*.method" label={t('fin.col.method')} required>
                                                        <Select onChange={(e) => setLine(line.key, { method: e.target.value })} searchable={false} value={line.method}>
                                                            <option value="">{t('fin.cor.methodChoose')}</option>
                                                            {overview.methods.map((m) => <option key={m} value={m}>{methodLabel(m)}</option>)}
                                                        </Select>
                                                    </FormField>
                                                    <FormField field="lines.*.received_minor" label={t('fin.cor.received', { currency })} required>
                                                        <Input inputMode="decimal" onChange={(e) => setLine(line.key, { received: e.target.value })} value={line.received} />
                                                    </FormField>
                                                </div>
                                            )}
                                            {issue !== undefined ? <p className="text-sm text-danger" role="alert">{issue}</p> : null}
                                        </li>
                                    );
                                })}
                            </ul>

                            <div className="flex flex-wrap items-center gap-2">
                                <Button disabled={form.lines.length >= MAX_LINES} onClick={() => addLine('revenue')} size="sm" type="button" variant="outline"><Plus aria-hidden="true" className="size-4" />{t('fin.cor.addRevenue')}</Button>
                                <Button disabled={form.lines.length >= MAX_LINES} onClick={() => addLine('payment')} size="sm" type="button" variant="outline"><Plus aria-hidden="true" className="size-4" />{t('fin.cor.addPayment')}</Button>
                                <span className="text-xs text-muted-foreground">{t('fin.cor.lineCount', { count: form.lines.length, max: MAX_LINES })}</span>
                            </div>
                        </section>

                        <div aria-live="polite" className="flex flex-col gap-3 border border-border bg-surface p-3 text-sm" data-testid="correction-totals">
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs text-muted-foreground">{t('fin.cor.totalRevenue')}</dt>
                                    <dd className="text-base font-semibold tabular-nums">{money(totals.revenue)}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs text-muted-foreground">{t('fin.cor.totalReceived')}</dt>
                                    <dd className="text-base font-semibold tabular-nums">{money(totals.received)}</dd>
                                </div>
                            </dl>
                            <p className="text-xs text-muted-foreground">{t('fin.cor.negativeHint')}</p>
                        </div>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
