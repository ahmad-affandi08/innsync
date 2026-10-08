import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { DatePicker } from '@/components/ui/date-picker';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/ui/money-input';
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { PromiseBadge, ReceivableStatus, useCustomerKindLabel, type ReceivableRow } from '@/modules/finance/lib/finance';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Customer = { id: string; code: string; name: string; kind: string; terms_days: number; active: boolean };
type Overview = {
    today: string; currency: string; receivables: ReceivableRow[]; owed_minor: number; overdue_minor: number; due_soon_minor: number; customers: Customer[]; may: { manage: boolean };
};
type Filters = { status: string; customer_id: string };
type Form = { customer_id: string; description: string; reference: string; amount: string; issued_on: string; due_date: string };

const STATUSES = ['open', 'overdue', 'paid', 'all'] as const;
const BLANK: Form = { customer_id: '', description: '', reference: '', amount: '', issued_on: '', due_date: '' };

/** What customers owe the property, and the worklist for collecting it. */
export default function ReceivablesPage({ filters, overview }: { filters: Filters; overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const kindLabel = useCustomerKindLabel();
    const [form, setForm] = useState<Form | null>(null);
    const [badAmount, setBadAmount] = useState(false);
    const money = (minor: number, currency = overview.currency) => format.money(minor, currency);
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(form)]);
    const activeCustomers = overview.customers.filter((c) => c.active);
    const chosen = form === null ? undefined : overview.customers.find((c) => c.id === form.customer_id);
    const sourceLabel = (s: string) => t(s === 'company_folio' ? 'fin.ar.sourceCompanyFolio' : 'fin.ar.sourceManual');

    function filter(next: Partial<Filters>) {
        const query = { ...filters, ...next };

        router.get('/finance/receivables', Object.fromEntries(Object.entries(query).filter(([, v]) => v !== '')), { preserveScroll: true });
    }

    function openNew() {
        action.clear();
        setBadAmount(false);
        setForm({ ...BLANK });
    }

    async function create() {
        if (form === null) return;
        const minor = parseMajorToMinor(form.amount, overview.currency);

        setBadAmount(minor === null);
        if (minor === null) return;
        const done = await action.run<{ receivable: { id: string } }>('/finance/receivables', {
            body: { customer_id: form.customer_id, description: form.description.trim(), reference: form.reference.trim() || null, amount_minor: minor, issued_on: form.issued_on || null, due_date: form.due_date || null },
            idempotencyKey: intent,
        });
        if (done !== null) router.visit(`/finance/receivables/${done.receivable.id}`);
    }

    const columns: DataGridColumn<ReceivableRow>[] = [
        { id: 'customer', label: t('fin.ar.customer'), value: (r) => r.customer_name, searchText: (r) => `${r.customer_code} ${r.customer_name}`, rowHeader: true },
        { id: 'number', label: t('fin.ar.number'), value: (r) => r.number, cell: (r) => <Link className="font-medium underline" href={`/finance/receivables/${r.id}`}>{r.number}</Link> },
        { id: 'source', label: t('fin.ar.source'), value: (r) => r.source_type, filter: 'select', filterLabel: sourceLabel, cell: (r) => `${sourceLabel(r.source_type)} · ${r.source_number}` },
        { id: 'description', label: t('fin.ar.descriptionCol'), value: (r) => r.description, hidden: true },
        { id: 'issued', label: t('fin.ar.issued'), value: (r) => r.issued_on, cell: (r) => format.date(r.issued_on), hidden: true },
        { id: 'due', label: t('fin.col.dueDate'), value: (r) => r.due_date, cell: (r) => format.date(r.due_date) },
        { id: 'late', label: t('fin.ar.daysOverdue'), align: 'right', value: (r) => r.days_overdue, cell: (r) => (r.overdue ? format.number(r.days_overdue) : '—') },
        { id: 'amount', label: t('fin.col.amount'), align: 'right', value: (r) => r.amount_minor, cell: (r) => money(r.amount_minor, r.currency), hidden: true },
        { id: 'received', label: t('fin.ar.received'), align: 'right', value: (r) => r.received_minor, cell: (r) => money(r.received_minor, r.currency), hidden: true },
        { id: 'balance', label: t('fin.col.balance'), align: 'right', value: (r) => r.balance_minor, cell: (r) => money(r.balance_minor, r.currency) },
        { id: 'state', label: t('inv.col.status'), value: (r) => (r.overdue ? 'overdue' : r.status), cell: (r) => <ReceivableStatus row={r} /> },
        {
            id: 'promise', label: t('fin.ar.promise'), value: (r) => r.promised_on ?? '',
            cell: (r) => (r.promised_on !== null && r.balance_minor > 0 ? <PromiseBadge row={r} today={overview.today} /> : '—'),
        },
        { id: 'notes', label: t('fin.ar.notes'), align: 'right', value: (r) => r.note_count, cell: (r) => format.number(r.note_count), hidden: true },
        { id: 'actions', label: t('inv.col.actions'), cell: (r) => <Button onClick={() => router.visit(`/finance/receivables/${r.id}`)} size="sm" type="button" variant="outline">{t('fin.ar.open')}</Button> },
    ];

    return (
        <FinanceShell actions={overview.may.manage ? <Button onClick={openNew} type="button">{t('fin.ar.new')}</Button> : undefined} description={t('fin.ar.description')} title={t('fin.ar.title')} wide>
            <div className="grid gap-3 sm:grid-cols-3" data-testid="receivable-summary">
                <Metric label={t('fin.ar.owed')} value={money(overview.owed_minor)} />
                <Metric label={t('fin.ar.overdue')} value={money(overview.overdue_minor)} />
                <Metric label={t('fin.ar.dueSoon')} value={money(overview.due_soon_minor)} />
            </div>

            <div className="flex flex-wrap gap-3">
                <div className="w-full max-w-xs">
                    <Select aria-label={t('fin.ar.status')} onChange={(e) => filter({ status: e.target.value })} searchable={false} value={filters.status}>
                        {STATUSES.map((s) => <option key={s} value={s}>{t(`fin.filter.${s}` as MessageKey)}</option>)}
                    </Select>
                </div>
                <div className="w-full max-w-xs">
                    <Select aria-label={t('fin.ar.customerFilter')} onChange={(e) => filter({ customer_id: e.target.value })} value={filters.customer_id}>
                        <option value="">{t('fin.ar.allCustomers')}</option>
                        {overview.customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </Select>
                </div>
            </div>
            {filters.status === 'overdue' ? <p className="text-sm text-muted-foreground">{t('fin.ar.overdueFirst')}</p> : null}

            <DataGrid caption={t('fin.ar.title')} columns={columns} empty={<EmptyState title={t('fin.ar.empty')} />} getRowId={(r) => r.id} id="fin.receivables" rows={overview.receivables} testId="receivables" />

            <Dialog
                footer={<>
                    <Button disabled={action.busy} onClick={() => setForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={action.busy} onClick={() => void create()} type="button">{t('fin.ar.create')}</Button>
                </>}
                onClose={() => setForm(null)}
                open={form !== null}
                title={t('fin.ar.newTitle')}
            >
                {form !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.ar.newHint')}</p>
                        {action.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('customer_id')} field="customer_id" hint={activeCustomers.length === 0 ? t('fin.ar.noCustomers') : undefined} label={t('fin.ar.customer')}>
                                <Select onChange={(e) => setForm({ ...form, customer_id: e.target.value })} value={form.customer_id}>
                                    <option value="">{t('fin.ar.customerChoose')}</option>
                                    {activeCustomers.map((c) => <option key={c.id} value={c.id}>{`${c.code} · ${c.name} · ${kindLabel(c.kind)}`}</option>)}
                                </Select>
                            </FormField>
                        </div>
                        <div className="sm:col-span-2">
                            <FormField error={action.fieldError('description')} field="description" label={t('fin.ar.descriptionField')}>
                                <Input maxLength={200} onChange={(e) => setForm({ ...form, description: e.target.value })} value={form.description} />
                            </FormField>
                        </div>
                        <FormField error={action.fieldError('reference')} field="reference" hint={t('fin.ar.referenceHint')} label={t('fin.ar.reference')}>
                            <Input maxLength={60} onChange={(e) => setForm({ ...form, reference: e.target.value })} value={form.reference} />
                        </FormField>
                        <FormField error={badAmount ? t('fo.folio.invalidAmount') : action.fieldError('amount_minor')} field="amount_minor" label={t('fin.ar.amountField', { currency: overview.currency })}>
                            <MoneyInput onChange={(e) => { setBadAmount(false); setForm({ ...form, amount: e.target.value }); }} value={form.amount} />
                        </FormField>
                        <FormField error={action.fieldError('issued_on')} field="issued_on" hint={t('fin.ar.issuedHint')} label={t('fin.ar.issued')}>
                            <DatePicker onChange={(e) => setForm({ ...form, issued_on: e.target.value })} value={form.issued_on} />
                        </FormField>
                        <FormField
                            error={action.fieldError('due_date')}
                            field="due_date"
                            hint={chosen === undefined ? t('fin.ar.dueHint') : t('fin.ar.dueHintTerms', { days: chosen.terms_days })}
                            label={t('fin.col.dueDate')}
                        >
                            <DatePicker onChange={(e) => setForm({ ...form, due_date: e.target.value })} value={form.due_date} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
