import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DataGrid, type DataGridColumn } from '@/components/ui/data-grid';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';

type Suggestion = { type: string; id: string; label: string; days: number };
type Line = { id: string; account_label: string; date: string; description: string; reference: string | null; direction: 'in' | 'out'; amount_minor: number; status: string; match_type: string | null; note: string | null; category: string | null; suggestions: Suggestion[] };
type Overview = { currency: string; status: string; counts: { open: number; matched: number; ignored: number }; lines: Line[]; categories: Record<'in' | 'out', string[]>; imports: { id: string; account_label: string; filename: string; rows_new: number; rows_duplicate: number; created_at: string }[]; may_manage: boolean };
type Report = { status: 'rejected' | 'validated' | 'applied'; dry_run: boolean; count: number; duplicates: number; errors: { line: number; code: string; message: string }[] };

const STATUSES = ['open', 'matched', 'ignored'] as const;

/** The bank statement the hotel downloaded, set against the books: what matches is tied to the payment or receipt it belongs to, what does not stays open for a person to explain. */
export default function BankPage({ overview }: { overview: Overview }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const action = useServerAction();
    const reload = ['overview'];
    const money = (minor: number) => format.money(minor, overview.currency);
    const [importing, setImporting] = useState<{ label: string; file: File | null; report: Report | null } | null>(null);
    const [pickerKey, setPickerKey] = useState(0);
    const [aside, setAside] = useState<{ line: Line; note: string; category: string } | null>(null);
    const [reopen, setReopen] = useState<{ line: Line; reason: string } | null>(null);

    function show(status: string) {
        router.get('/finance/bank', status === 'open' ? {} : { status }, { preserveState: true, preserveScroll: true, only: ['overview'] });
    }

    async function send(dry: boolean) {
        if (importing === null || importing.file === null) return;
        const body = new FormData();
        body.set('file', importing.file);
        body.set('account_label', importing.label.trim());
        body.set('dry_run', dry ? '1' : '0');
        const report = await action.run<Report>('/finance/bank/import', { body, reload: dry ? undefined : reload });
        if (report === null) return;
        if (!dry && report.status === 'applied') {
            setImporting(null);
            setPickerKey((k) => k + 1);
            return;
        }
        setImporting({ ...importing, report });
    }

    async function match(line: Line, s: Suggestion) {
        await action.run(`/finance/bank/lines/${line.id}/match`, { body: { type: s.type, target_id: s.id }, reload });
    }

    async function setAsideNow() {
        if (aside === null) return;
        const done = await action.run(`/finance/bank/lines/${aside.line.id}/ignore`, { body: { note: aside.note.trim(), category: aside.category === '' ? null : aside.category }, reload });
        if (done !== null) setAside(null);
    }

    async function reopenNow() {
        if (reopen === null) return;
        const done = await action.run(`/finance/bank/lines/${reopen.line.id}/reopen`, { body: { reason: reopen.reason.trim() }, reload });
        if (done !== null) setReopen(null);
    }

    const columns: DataGridColumn<Line>[] = [
        { id: 'date', label: t('fin.bk.col.date'), value: (l) => l.date, cell: (l) => format.date(l.date) },
        { id: 'account', label: t('fin.bk.col.account'), value: (l) => l.account_label, filter: 'select' },
        { id: 'description', label: t('fin.bk.col.description'), value: (l) => l.description, rowHeader: true, cell: (l) => <span>{l.description}{l.reference !== null ? <span className="block text-xs text-muted-foreground">{l.reference}</span> : null}</span> },
        { id: 'amount', label: t('fin.bk.col.amount'), align: 'right', value: (l) => (l.direction === 'in' ? l.amount_minor : -l.amount_minor), cell: (l) => <span className={l.direction === 'in' ? 'text-success' : ''}>{l.direction === 'in' ? '+' : '−'}{money(l.amount_minor)}</span> },
        {
            id: 'match',
            label: t('fin.bk.col.match'),
            value: (l) => l.status,
            sortable: false,
            cell: (l) => {
                if (l.status === 'matched') return <StatusBadge label={t(`fin.bk.type.${l.match_type}` as 'fin.bk.type.receipt')} tone="success" />;
                if (l.status === 'ignored') return <span className="text-sm text-muted-foreground">{t('fin.bk.setAside')}{l.category !== null ? ` · ${t(`fin.bk.cat.${l.category}` as 'fin.bk.cat.bank_fee')}` : ''}{l.note !== null ? ` · ${l.note}` : ''}</span>;
                if (l.suggestions.length === 0) return <span className="text-sm text-muted-foreground">{t('fin.bk.noSuggestion')}</span>;

                return (
                    <div className="flex flex-col gap-1">
                        {l.suggestions.map((s) => (
                            <div className="flex flex-wrap items-center gap-2" key={`${s.type}-${s.id}`}>
                                <span className="text-sm">{t(`fin.bk.type.${s.type}` as 'fin.bk.type.receipt')} · {s.label}<span className="text-xs text-muted-foreground"> · {t('fin.bk.days', { n: s.days })}</span></span>
                                {overview.may_manage ? <Button disabled={action.busy} onClick={() => void match(l, s)} size="sm" type="button" variant="outline">{t('fin.bk.match')}</Button> : null}
                            </div>
                        ))}
                    </div>
                );
            },
        },
        {
            id: 'actions',
            label: '',
            value: () => '',
            sortable: false,
            cell: (l) => {
                if (!overview.may_manage) return null;
                if (l.status === 'open') return <Button onClick={() => { action.clear(); setAside({ line: l, note: '', category: '' }); }} size="sm" type="button" variant="ghost">{t('fin.bk.aside')}</Button>;

                return <Button onClick={() => { action.clear(); setReopen({ line: l, reason: '' }); }} size="sm" type="button" variant="ghost">{t('fin.bk.reopen')}</Button>;
            },
        },
    ];

    const report = importing?.report ?? null;

    return (
        <FinanceShell description={t('fin.bk.description')} title={t('fin.bk.title')} wide>
            {action.error !== null && importing === null && aside === null && reopen === null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
            <div className="flex flex-wrap items-center gap-3 print:hidden">
                {overview.may_manage ? <Button onClick={() => { action.clear(); setImporting({ label: '', file: null, report: null }); }} type="button">{t('fin.bk.import')}</Button> : null}
                <div className="flex gap-2" role="group" aria-label={t('fin.bk.filter')}>
                    {STATUSES.map((s) => (
                        <Button aria-pressed={overview.status === s} key={s} onClick={() => show(s)} size="sm" type="button" variant={overview.status === s ? 'default' : 'outline'}>{t(`fin.bk.status.${s}` as 'fin.bk.status.open', { n: overview.counts[s] })}</Button>
                    ))}
                </div>
            </div>
            <DataGrid caption={t('fin.bk.title')} columns={columns} empty={<EmptyState title={t('fin.bk.none')} />} getRowId={(l) => l.id} id="fin.bank" rows={overview.lines} testId="bank-lines" />

            <Dialog
                footer={
                    <>
                        <Button disabled={action.busy} onClick={() => setImporting(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                        <Button disabled={importing?.file === null || (importing?.label.trim() ?? '') === ''} loading={action.busy && report === null} onClick={() => void send(true)} type="button" variant="outline">{t('fin.bk.check')}</Button>
                        <Button disabled={report?.status !== 'validated' || report.count === 0} loading={action.busy && report !== null} onClick={() => void send(false)} type="button">{t('fin.bk.apply')}</Button>
                    </>
                }
                onClose={() => setImporting(null)}
                open={importing !== null}
                title={t('fin.bk.import')}
            >
                {importing !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm text-muted-foreground">{t('fin.bk.importHint')}</p>
                        <FormField error={action.fieldError('account_label')} field="account_label" hint={t('fin.bk.accountHint')} label={t('fin.bk.col.account')}><Input maxLength={40} onChange={(e) => setImporting({ ...importing, label: e.target.value, report: null })} value={importing.label} /></FormField>
                        <FormField error={action.fieldError('file')} field="file" label={t('fin.bk.file')}><Input accept=".csv,.txt,text/csv,text/plain" key={pickerKey} onChange={(e) => setImporting({ ...importing, file: e.target.files?.[0] ?? null, report: null })} type="file" /></FormField>
                        {report !== null && report.status === 'rejected' ? (
                            <Alert title={t('fin.bk.rejected')} tone="danger">
                                <ul className="list-disc pl-5 text-sm">{report.errors.slice(0, 10).map((e) => <li key={`${e.line}-${e.code}`}>{t('fin.bk.errorLine', { line: e.line, message: e.message })}</li>)}</ul>
                            </Alert>
                        ) : null}
                        {report !== null && report.status === 'validated' ? <Alert title={t('fin.bk.validated', { count: report.count, duplicates: report.duplicates })} tone="success" /> : null}
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setAside(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={aside?.note.trim() === ''} loading={action.busy} onClick={() => void setAsideNow()} type="button">{t('fin.bk.aside')}</Button></>}
                onClose={() => setAside(null)}
                open={aside !== null}
                title={t('fin.bk.asideTitle')}
            >
                {aside !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{aside.line.description} · {money(aside.line.amount_minor)}</p>
                        <FormField error={action.fieldError('category')} field="category" hint={t('fin.bk.categoryHint')} label={t('fin.bk.category')}><Select onChange={(e) => setAside({ ...aside, category: e.target.value })} value={aside.category}><option value="">{t('fin.bk.cat.none')}</option>{overview.categories[aside.line.direction].map((c) => <option key={c} value={c}>{t(`fin.bk.cat.${c}` as 'fin.bk.cat.bank_fee')}</option>)}</Select></FormField>
                        <FormField error={action.fieldError('note')} field="note" hint={t('fin.bk.asideHint')} label={t('fin.bk.note')}><Input maxLength={200} onChange={(e) => setAside({ ...aside, note: e.target.value })} value={aside.note} /></FormField>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<><Button disabled={action.busy} onClick={() => setReopen(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button><Button disabled={reopen?.reason.trim() === ''} loading={action.busy} onClick={() => void reopenNow()} type="button">{t('fin.bk.reopen')}</Button></>}
                onClose={() => setReopen(null)}
                open={reopen !== null}
                title={t('fin.bk.reopenTitle')}
            >
                {reopen !== null && (
                    <div className="grid gap-3">
                        {action.error !== null ? <ErrorState {...errorCopy} error={action.error} onRefresh={() => window.location.reload()} /> : null}
                        <p className="text-sm">{reopen.line.description} · {money(reopen.line.amount_minor)}</p>
                        <FormField error={action.fieldError('reason')} field="reason" label={t('fo.lock.reason')}><Input maxLength={200} onChange={(e) => setReopen({ ...reopen, reason: e.target.value })} value={reopen.reason} /></FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
