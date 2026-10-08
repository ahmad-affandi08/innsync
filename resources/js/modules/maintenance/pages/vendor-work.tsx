import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Select } from '@/components/ui/select';
import { StatusBadge, type StatusTone } from '@/components/ui/status-badge';
import { MaintenanceShell } from '@/modules/maintenance/components/maintenance-shell';
import type { VendorJob, VendorJobDetail, VendorOverview, VendorStatus } from '@/modules/maintenance/lib/maintenance';
import { apiRequest, newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

const TONE: Record<VendorStatus, StatusTone> = { quoting: 'info', pending_approval: 'pending', approved: 'success', scheduled: 'warning', done: 'success', rejected: 'danger', cancelled: 'neutral' };

/** Work given to outside vendors: quotations of suppliers, the approval of the amount, the date, and what it cost with a photo of the work. */
export default function VendorWorkPage({ overview }: { overview: VendorOverview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const [form, setForm] = useState<{ workOrderId: string; scope: string } | null>(null);
    const [detail, setDetail] = useState<VendorJobDetail | null>(null);
    const [loadFailed, setLoadFailed] = useState(false);
    const [quote, setQuote] = useState({ supplierId: '', amount: '', validUntil: '', note: '' });
    const [pick, setPick] = useState({ quoteId: '', reason: '' });
    const [schedule, setSchedule] = useState({ on: '', note: '' });
    const [done, setDone] = useState({ actual: '', invoice: '', note: '' });
    const [doneFile, setDoneFile] = useState<File | null>(null);
    const [reason, setReason] = useState('');
    const failure = action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null;
    const label = (prefix: string, key: string) => t(`${prefix}.${key}` as MessageKey);
    const money = (minor: number | null) => (minor === null ? '—' : format.money(minor, overview.currency));
    const d = detail;

    async function open(j: { id: string }) {
        action.clear();
        setLoadFailed(false);
        try {
            const next = await apiRequest<VendorJobDetail>(`/maintenance/vendor-jobs/${j.id}`, { method: 'GET' });

            setDetail(next);
            setQuote({ supplierId: next.suppliers[0]?.id ?? '', amount: '', validUntil: '', note: '' });
            setPick({ quoteId: next.quotes[0]?.id ?? '', reason: '' });
            setSchedule({ on: next.scheduled_on ?? next.business_date, note: next.schedule_note ?? '' });
            setDone({ actual: next.agreed_minor === null ? '' : String(next.agreed_minor / 100), invoice: '', note: '' });
            setDoneFile(null);
            setReason('');
        } catch {
            setLoadFailed(true);
        }
    }

    function taken(result: VendorJobDetail | null) {
        if (result !== null) {
            setDetail(result);
            setQuote((q) => ({ ...q, supplierId: result.suppliers[0]?.id ?? '', amount: '', validUntil: '', note: '' }));
            setPick({ quoteId: result.quotes[0]?.id ?? '', reason: '' });
            router.reload({ only: ['overview'] });
        }

        return result;
    }

    async function create() {
        if (form === null) return;
        const result = await action.run<VendorJobDetail>('/maintenance/vendor-jobs', { idempotencyKey: newIdempotencyKey(), body: { work_order_id: form.workOrderId, scope: form.scope.trim() } });

        if (taken(result) !== null) {
            setForm(null);
            if (result !== null) void open(result);
        }
    }

    async function finish() {
        if (d === null) return;
        const body = new FormData();

        body.set('actual_minor', String(parseMajorToMinor(done.actual, overview.currency) ?? ''));
        if (done.invoice.trim() !== '') body.set('invoice_ref', done.invoice.trim());
        body.set('note', done.note.trim());
        body.set('lock_version', String(d.lock_version));
        if (doneFile !== null) body.set('photo', doneFile);
        taken(await action.run<VendorJobDetail>(`/maintenance/vendor-jobs/${d.id}/complete`, { body }));
    }

    const chosen = d?.quotes.find((q) => q.id === pick.quoteId);
    const needsReason = chosen !== undefined && d !== null && d.quotes.length > 1 && !chosen.lowest;
    const amount = parseMajorToMinor(quote.amount, overview.currency);
    const actual = parseMajorToMinor(done.actual, overview.currency);

    const columns: DataGridColumn<VendorJob>[] = [
        { id: 'number', label: t('mtc.col.number'), value: (j) => j.number, rowHeader: true },
        { id: 'work', label: t('mtc.vendor.workOrder'), value: (j) => `${j.work_order_number} ${j.work_order_title}`, cell: (j) => <span>{j.work_order_number}<span className="block text-xs text-muted-foreground">{j.work_order_title}</span></span> },
        { id: 'scope', label: t('mtc.vendor.scope'), value: (j) => j.scope },
        { id: 'supplier', label: t('mtc.vendor.supplier'), value: (j) => j.supplier ?? '', cell: (j) => j.supplier ?? '—' },
        { id: 'status', label: t('mtc.col.status'), value: (j) => j.status, filter: 'select', filterLabel: (v) => label('mtc.vendor.status', v), cell: (j) => <StatusBadge label={label('mtc.vendor.status', j.status)} tone={TONE[j.status]} /> },
        { id: 'agreed', label: t('mtc.vendor.agreed'), align: 'right', value: (j) => j.agreed_minor ?? 0, cell: (j) => money(j.agreed_minor) },
        { id: 'actual', label: t('mtc.vendor.actual'), align: 'right', value: (j) => j.actual_minor ?? 0, cell: (j) => <span>{money(j.actual_minor)}{j.over_quote ? <span className="block text-xs text-danger">{t('mtc.vendor.over')}</span> : null}</span> },
        { id: 'date', label: t('mtc.vendor.date'), value: (j) => j.done_on ?? j.scheduled_on ?? '', cell: (j) => (j.done_on ?? j.scheduled_on) === null ? '—' : format.date((j.done_on ?? j.scheduled_on) as string) },
        { id: 'open', label: '', value: () => '', sortable: false, cell: (j) => <Button onClick={() => void open(j)} size="sm" type="button" variant="outline">{t('mtc.open')}</Button> },
    ];

    return (
        <MaintenanceShell
            actions={overview.may.act ? <Button disabled={overview.work_orders.length === 0} onClick={() => { action.clear(); setForm({ workOrderId: overview.work_orders[0]?.id ?? '', scope: '' }); }} type="button">{t('mtc.vendor.new')}</Button> : undefined}
            description={t('mtc.vendor.description')}
            title={t('mtc.vendor.title')}
        >
            {loadFailed ? <Alert title={t('mtc.loadFailed')} tone="danger" /> : null}
            <DataGrid caption={t('mtc.vendor.title')} columns={columns} empty={<EmptyState illustration="checklist" title={t('mtc.vendor.empty')} />} getRowId={(j) => j.id} id="mtc.vendor" rows={overview.jobs} testId="mtc-vendor-grid" />

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={form?.scope.trim() === '' || form?.workOrderId === ''} loading={action.busy} onClick={() => void create()} type="button">{t('mtc.vendor.create')}</Button></>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('mtc.vendor.new')}
            >
                {form !== null && (
                    <div className="flex flex-col gap-3">
                        {failure}
                        <FormField error={action.fieldError('work_order_id')} field="work_order_id" label={t('mtc.vendor.workOrder')}><Select onChange={(e) => setForm({ ...form, workOrderId: e.target.value })} value={form.workOrderId}>{overview.work_orders.map((w) => <option key={w.id} value={w.id}>{w.number} · {w.title}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('scope')} field="scope" label={t('mtc.vendor.scope')}><Input maxLength={300} onChange={(e) => setForm({ ...form, scope: e.target.value })} value={form.scope} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                className="w-[min(46rem,calc(100vw-2rem))]"
                footer={<Button onClick={() => setDetail(null)} type="button" variant="outline">{t('mtc.close')}</Button>}
                onClose={() => setDetail(null)}
                open={detail !== null}
                title={d === null ? '' : `${d.number} · ${d.work_order_number}`}
            >
                {d !== null && (
                    <div className="flex flex-col gap-4" data-testid="mtc-vendor-detail">
                        {failure}
                        <div className="flex flex-wrap items-center gap-2"><StatusBadge label={label('mtc.vendor.status', d.status)} tone={TONE[d.status]} />{d.over_quote ? <StatusBadge label={t('mtc.vendor.over')} tone="danger" /> : null}</div>
                        <p className="text-sm">{d.scope}</p>
                        <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                            <div><dt className="text-muted-foreground">{t('mtc.vendor.workOrder')}</dt><dd>{d.work_order_number} · {d.work_order_title}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.vendor.supplier')}</dt><dd>{d.supplier ?? '—'}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.vendor.agreed')}</dt><dd>{money(d.agreed_minor)}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.vendor.actual')}</dt><dd>{money(d.actual_minor)}{d.invoice_ref !== null ? ` · ${d.invoice_ref}` : ''}</dd></div>
                            <div><dt className="text-muted-foreground">{t('mtc.vendor.date')}</dt><dd>{(d.done_on ?? d.scheduled_on) === null ? '—' : format.date((d.done_on ?? d.scheduled_on) as string)}{d.schedule_note !== null ? ` · ${d.schedule_note}` : ''}</dd></div>
                            {d.choice_reason !== null ? <div><dt className="text-muted-foreground">{t('mtc.vendor.whyNotLowest')}</dt><dd>{d.choice_reason}</dd></div> : null}
                            {d.done_note !== null ? <div className="sm:col-span-2"><dt className="text-muted-foreground">{t('mtc.vendor.doneNote')}</dt><dd>{d.done_note}</dd></div> : null}
                            {d.cancel_reason !== null ? <div className="sm:col-span-2"><dt className="text-muted-foreground">{t('mtc.cancel.reason')}</dt><dd>{d.cancel_reason}</dd></div> : null}
                        </dl>
                        {d.has_proof ? <div><Button asChild size="sm" variant="outline"><a href={`/maintenance/vendor-jobs/${d.id}/proof`} rel="noreferrer" target="_blank">{t('mtc.vendor.proof')}</a></Button></div> : null}

                        <section aria-labelledby="mtc-vendor-quotes-h" className="flex flex-col gap-2 border-t border-border pt-3">
                            <h3 className="text-sm font-semibold" id="mtc-vendor-quotes-h">{t('mtc.vendor.quotes')}</h3>
                            {d.quotes.length === 0 ? <p className="text-sm text-muted-foreground">{t('mtc.vendor.noQuotes')}</p> : (
                                <ul className="flex flex-col gap-1 text-sm" data-testid="mtc-vendor-quotes">
                                    {d.quotes.map((q) => <li className="flex flex-wrap items-center gap-2" key={q.id}>{q.supplier} · {money(q.amount_minor)}{q.lowest && d.quotes.length > 1 ? <StatusBadge label={t('mtc.vendor.lowest')} tone="info" /> : null}{q.chosen ? <StatusBadge label={t('mtc.vendor.chosen')} tone="success" /> : null}<span className="text-muted-foreground">{q.valid_until !== null ? `${t('mtc.vendor.validUntil')} ${format.date(q.valid_until)}` : ''}{q.note !== null ? ` · ${q.note}` : ''}</span></li>)}
                                </ul>
                            )}
                            {d.may.quote ? (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField error={action.fieldError('supplier_id')} field="supplier_id" label={t('mtc.vendor.supplier')}><Select onChange={(e) => setQuote({ ...quote, supplierId: e.target.value })} value={quote.supplierId}>{d.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</Select></FormField>
                                    <FormField error={action.fieldError('amount_minor')} field="amount_minor" label={t('mtc.vendor.amount', { currency: overview.currency })}><MoneyInput onChange={(e) => setQuote({ ...quote, amount: e.target.value })} value={quote.amount} /></FormField>
                                    <FormField error={action.fieldError('valid_until')} field="valid_until" label={t('mtc.vendor.validUntil')}><DatePicker onChange={(e) => setQuote({ ...quote, validUntil: e.target.value })} value={quote.validUntil} /></FormField>
                                    <FormField error={action.fieldError('note')} field="note" label={t('mtc.parts.note')}><Input maxLength={200} onChange={(e) => setQuote({ ...quote, note: e.target.value })} value={quote.note} /></FormField>
                                    <div><Button disabled={action.busy || quote.supplierId === '' || amount === null || amount < 1} onClick={() => void action.run<VendorJobDetail>(`/maintenance/vendor-jobs/${d.id}/quotes`, { body: { supplier_id: quote.supplierId, amount_minor: amount, valid_until: quote.validUntil === '' ? null : quote.validUntil, note: quote.note.trim() === '' ? null : quote.note.trim() } }).then(taken)} size="sm" type="button">{t('mtc.vendor.addQuote')}</Button></div>
                                </div>
                            ) : null}
                        </section>

                        {d.may.choose ? (
                            <section aria-labelledby="mtc-vendor-choose-h" className="flex flex-col gap-2 border-t border-border pt-3">
                                <h3 className="text-sm font-semibold" id="mtc-vendor-choose-h">{t('mtc.vendor.choose')}</h3>
                                <p className="text-xs text-muted-foreground">{t('mtc.vendor.chooseHint')}</p>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField error={action.fieldError('quote_id')} field="quote_id" label={t('mtc.vendor.quote')}><Select onChange={(e) => setPick({ ...pick, quoteId: e.target.value })} value={pick.quoteId}>{d.quotes.map((q) => <option key={q.id} value={q.id}>{q.supplier} · {money(q.amount_minor)}</option>)}</Select></FormField>
                                    {needsReason ? <FormField error={action.fieldError('reason')} field="reason" label={t('mtc.vendor.whyNotLowest')}><Input maxLength={200} onChange={(e) => setPick({ ...pick, reason: e.target.value })} value={pick.reason} /></FormField> : null}
                                </div>
                                <div><Button disabled={action.busy || pick.quoteId === '' || (needsReason && pick.reason.trim() === '')} onClick={() => void action.run<VendorJobDetail>(`/maintenance/vendor-jobs/${d.id}/choose`, { body: { quote_id: pick.quoteId, reason: pick.reason.trim() === '' ? null : pick.reason.trim(), lock_version: d.lock_version } }).then(taken)} size="sm" type="button">{t('mtc.vendor.chooseDo')}</Button></div>
                            </section>
                        ) : null}

                        {d.may.release ? (
                            <section className="flex flex-col gap-2 border-t border-border pt-3" data-testid="mtc-vendor-release">
                                <p className="text-sm">{t('mtc.vendor.waiting')}</p>
                                <div><Button disabled={action.busy} onClick={() => void action.run<VendorJobDetail>(`/maintenance/vendor-jobs/${d.id}/release`, { body: {} }).then(taken)} size="sm" type="button">{t('mtc.vendor.release')}</Button></div>
                            </section>
                        ) : null}

                        {d.may.schedule ? (
                            <section aria-labelledby="mtc-vendor-schedule-h" className="flex flex-col gap-2 border-t border-border pt-3">
                                <h3 className="text-sm font-semibold" id="mtc-vendor-schedule-h">{t('mtc.vendor.schedule')}</h3>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField error={action.fieldError('scheduled_on')} field="scheduled_on" label={t('mtc.vendor.comes')}><DatePicker onChange={(e) => setSchedule({ ...schedule, on: e.target.value })} value={schedule.on} /></FormField>
                                    <FormField error={action.fieldError('note')} field="note" label={t('mtc.parts.note')}><Input maxLength={200} onChange={(e) => setSchedule({ ...schedule, note: e.target.value })} value={schedule.note} /></FormField>
                                </div>
                                <div><Button disabled={action.busy || schedule.on === ''} onClick={() => void action.run<VendorJobDetail>(`/maintenance/vendor-jobs/${d.id}/schedule`, { body: { scheduled_on: schedule.on, note: schedule.note.trim() === '' ? null : schedule.note.trim(), lock_version: d.lock_version } }).then(taken)} size="sm" type="button">{t('mtc.vendor.scheduleDo')}</Button></div>
                            </section>
                        ) : null}

                        {d.may.complete ? (
                            <section aria-labelledby="mtc-vendor-done-h" className="flex flex-col gap-2 border-t border-border pt-3">
                                <h3 className="text-sm font-semibold" id="mtc-vendor-done-h">{t('mtc.vendor.finish')}</h3>
                                <p className="text-xs text-muted-foreground">{t('mtc.vendor.finishHint')}</p>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <FormField error={action.fieldError('actual_minor')} field="actual_minor" label={t('mtc.vendor.actualAmount', { currency: overview.currency })}><MoneyInput onChange={(e) => setDone({ ...done, actual: e.target.value })} value={done.actual} /></FormField>
                                    <FormField error={action.fieldError('invoice_ref')} field="invoice_ref" label={t('mtc.vendor.invoice')}><Input maxLength={40} onChange={(e) => setDone({ ...done, invoice: e.target.value })} value={done.invoice} /></FormField>
                                    <div className="sm:col-span-2"><FormField error={action.fieldError('note')} field="note" label={t('mtc.vendor.doneNote')}><Input maxLength={300} onChange={(e) => setDone({ ...done, note: e.target.value })} value={done.note} /></FormField></div>
                                    <div className="sm:col-span-2"><FormField error={action.fieldError('photo')} field="photo" label={t('mtc.vendor.photo')}><Input accept="image/jpeg,image/png" onChange={(e) => setDoneFile(e.target.files?.[0] ?? null)} type="file" /></FormField></div>
                                </div>
                                <div><Button disabled={action.busy || actual === null || done.note.trim() === '' || doneFile === null} onClick={() => void finish()} size="sm" type="button">{t('mtc.vendor.finishDo')}</Button></div>
                            </section>
                        ) : null}

                        {d.may.cancel ? (
                            <div className="flex flex-wrap items-end gap-2 border-t border-border pt-3">
                                <FormField error={action.fieldError('reason')} field="reason" label={t('mtc.cancel.reason')}><Input maxLength={200} onChange={(e) => setReason(e.target.value)} value={reason} /></FormField>
                                <Button disabled={action.busy || reason.trim() === ''} onClick={() => void action.run<VendorJobDetail>(`/maintenance/vendor-jobs/${d.id}/cancel`, { body: { reason: reason.trim(), lock_version: d.lock_version } }).then(taken)} size="sm" type="button" variant="outline">{t('mtc.vendor.cancelDo')}</Button>
                            </div>
                        ) : null}
                    </div>
                )}
            </Dialog>
        </MaintenanceShell>
    );
}
