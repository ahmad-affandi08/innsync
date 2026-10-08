import { Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

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
import { Metric } from '@/components/ui/metric';
import { Select } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { FinanceShell } from '@/modules/finance/components/finance-shell';
import { DEFAULT_CURRENCY, RECON_STATUSES, RECON_TONE, useReceiptMethodLabel } from '@/modules/finance/lib/finance';
import { newIdempotencyKey } from '@/shared/api/http';
import { useServerAction } from '@/shared/api/use-server-action';
import { useFormatters, useTranslation } from '@/shared/i18n/i18n';
import { useErrorStateCopy } from '@/shared/i18n/use-ui-copy';
import { parseMajorToMinor } from '@/shared/money/money';
import type { MessageKey } from '@/locales/en/index';

type Exception = {
    id: string; number: string; kind: string; status: 'open' | 'matched' | 'adjusted' | 'waived'; business_date: string; amount_minor: number; method: string | null; reference: string | null;
    folio_ref: string | null; description: string; resolution: string | null; correction_number: string | null; created_by: string | null; resolved_by: string | null; resolved_at: string | null;
    lock_version: number; may_reconcile: boolean;
};
type Overview = { exceptions: Exception[]; open_count: number; kinds: string[]; resolutions: string[]; methods: string[]; today: string; may: { reconcile: boolean } };
type RaiseForm = { kind: string; date: string; amount: string; method: string; reference: string; folio: string; description: string };
type ReconcileForm = { exception: Exception; status: string; resolution: string; correction: string };

const currency = DEFAULT_CURRENCY;

/** The exceptions of the daily reconciliation: refunds, chargebacks, settlement differences and payments of unknown status, until someone reconciles them. */
export default function ExceptionsPage({ overview, status }: { overview: Overview; status: string }) {
    const { t } = useTranslation();
    const format = useFormatters();
    const errorCopy = useErrorStateCopy();
    const raise = useServerAction();
    const reconcile = useServerAction();
    const methodLabel = useReceiptMethodLabel();
    const [raiseForm, setRaiseForm] = useState<RaiseForm | null>(null);
    const [reconcileForm, setReconcileForm] = useState<ReconcileForm | null>(null);
    const [badAmount, setBadAmount] = useState(false);
    const reload = ['overview'];
    const intent = useMemo(() => newIdempotencyKey(), [JSON.stringify(raiseForm)]);
    const statusLabel = (s: string) => t(`fin.exc.status.${s}` as MessageKey);
    const kindLabel = (k: string) => t(`fin.exc.kind.${k}` as MessageKey);
    const rows = useMemo(() => [...overview.exceptions].sort((a, b) => Number(b.status === 'open') - Number(a.status === 'open')), [overview.exceptions]);

    function filter(next: string) {
        router.get('/finance/exceptions', next === '' ? {} : { status: next }, { preserveScroll: true, preserveState: true });
    }

    function openRaise() {
        raise.clear();
        setBadAmount(false);
        setRaiseForm({ kind: overview.kinds[0] ?? '', date: '', amount: '', method: '', reference: '', folio: '', description: '' });
    }

    function openReconcile(exception: Exception) {
        reconcile.clear();
        setReconcileForm({ exception, status: overview.resolutions[0] ?? '', resolution: '', correction: '' });
    }

    async function saveRaise() {
        if (raiseForm === null) return;
        const minor = parseMajorToMinor(raiseForm.amount, currency);

        setBadAmount(minor === null || minor < 1);
        if (minor === null || minor < 1) return;
        const done = await raise.run('/finance/exceptions', {
            body: {
                kind: raiseForm.kind, business_date: raiseForm.date || null, amount_minor: minor, method: raiseForm.method || null,
                reference: raiseForm.reference.trim() || null, folio_ref: raiseForm.folio.trim() || null, description: raiseForm.description.trim(),
            },
            idempotencyKey: intent,
            reload,
        });
        if (done !== null) setRaiseForm(null);
    }

    async function saveReconcile() {
        if (reconcileForm === null) return;
        const adjusted = reconcileForm.status === 'adjusted';
        const done = await reconcile.run(`/finance/exceptions/${reconcileForm.exception.id}/reconcile`, {
            body: {
                status: reconcileForm.status, resolution: reconcileForm.resolution.trim(),
                correction_number: adjusted ? reconcileForm.correction.trim().toUpperCase() || null : null, lock_version: reconcileForm.exception.lock_version,
            },
            reload,
        });
        if (done !== null) setReconcileForm(null);
    }

    const columns: DataGridColumn<Exception>[] = [
        { id: 'number', label: t('fin.exc.colNumber'), value: (e) => e.number, rowHeader: true },
        { id: 'kind', label: t('fin.exc.colKind'), value: (e) => e.kind, filter: 'select', filterLabel: kindLabel, cell: (e) => kindLabel(e.kind) },
        { id: 'date', label: t('fin.exc.colDate'), value: (e) => e.business_date, cell: (e) => format.date(e.business_date) },
        { id: 'amount', label: t('fin.exc.colAmount'), align: 'right', value: (e) => e.amount_minor, cell: (e) => format.money(e.amount_minor, currency) },
        { id: 'method', label: t('fin.col.method'), value: (e) => (e.method === null ? '' : methodLabel(e.method)), cell: (e) => (e.method === null ? '—' : methodLabel(e.method)), hidden: true },
        {
            id: 'what', label: t('fin.exc.colWhat'), value: (e) => e.description, searchText: (e) => `${e.description} ${e.reference ?? ''} ${e.folio_ref ?? ''}`,
            cell: (e) => (
                <span className="flex flex-col">
                    <span>{e.description}</span>
                    {e.reference !== null ? <span className="text-xs text-muted-foreground">{t('fin.exc.reference')}: {e.reference}</span> : null}
                    {e.folio_ref !== null ? <span className="text-xs text-muted-foreground">{t('fin.exc.folio')}: {e.folio_ref}</span> : null}
                </span>
            ),
        },
        { id: 'status', label: t('fin.exc.colStatus'), value: (e) => e.status, filter: 'select', filterLabel: statusLabel, cell: (e) => <StatusBadge label={statusLabel(e.status)} tone={RECON_TONE[e.status] ?? 'neutral'} /> },
        {
            id: 'resolution', label: t('fin.exc.colResolution'), value: (e) => e.resolution ?? '', searchText: (e) => `${e.resolution ?? ''} ${e.correction_number ?? ''}`,
            cell: (e) => (e.resolution === null ? '—' : (
                <span className="flex flex-col">
                    <span>{e.resolution}</span>
                    {e.correction_number !== null ? <span className="text-xs text-muted-foreground">{t('fin.exc.correction')}: {e.correction_number}</span> : null}
                </span>
            )),
        },
        { id: 'createdBy', label: t('fin.exc.colRaisedBy'), value: (e) => e.created_by ?? '', cell: (e) => e.created_by ?? '—', hidden: true },
        { id: 'resolvedBy', label: t('fin.exc.colResolvedBy'), value: (e) => e.resolved_by ?? '', cell: (e) => (e.resolved_by === null ? '—' : `${e.resolved_by}${e.resolved_at === null ? '' : ` · ${format.instant(e.resolved_at)}`}`), hidden: true },
        ...(overview.may.reconcile ? [{
            id: 'actions', label: t('inv.col.actions'),
            cell: (e: Exception) => (e.may_reconcile ? <Button onClick={() => openReconcile(e)} size="sm" type="button" variant="outline">{t('fin.exc.reconcile')}</Button> : null),
        }] : []),
    ];

    const adjusting = reconcileForm?.status === 'adjusted';

    return (
        <FinanceShell actions={overview.may.reconcile ? <Button onClick={openRaise} type="button">{t('fin.exc.raise')}</Button> : undefined} description={t('fin.exc.description')} title={t('fin.exc.title')} wide>
            <Alert title={t('fin.exc.explainTitle')} tone="info">
                <ul className="flex list-disc flex-col gap-1 pl-5" data-testid="exceptions-explain">
                    <li>{t('fin.exc.explainKinds')}</li>
                    <li>{t('fin.exc.explainOpen')}</li>
                    <li>{t('fin.exc.explainResolve')}</li>
                    <li>{t('fin.exc.explainPeople')}</li>
                    <li>{t('fin.exc.explainCash')} <Link className="underline" href="/finance/cash?tab=exceptions">{t('fin.exc.toCash')}</Link></li>
                </ul>
            </Alert>

            <section aria-label={t('fin.exc.title')} className="grid gap-3 sm:grid-cols-2 lg:max-w-2xl" data-testid="exceptions-kpis">
                <Metric label={t('fin.exc.openCount')} value={format.number(overview.open_count)} />
            </section>

            <div className="flex flex-wrap gap-3 print:hidden">
                <div className="w-full max-w-xs">
                    <Select aria-label={t('fin.exc.colStatus')} onChange={(e) => filter(e.target.value)} searchable={false} value={status}>
                        <option value="">{t('fin.exc.allStatuses')}</option>
                        {RECON_STATUSES.map((s) => <option key={s} value={s}>{statusLabel(s)}</option>)}
                    </Select>
                </div>
            </div>
            <p className="text-sm text-muted-foreground">{t('fin.exc.openFirst')}</p>

            <DataGrid caption={t('fin.exc.title')} columns={columns} empty={<EmptyState title={t('fin.exc.empty')} />} getRowId={(e) => e.id} id="fin.exceptions" rows={rows} testId="exceptions" />

            <Dialog
                footer={<>
                    <Button disabled={raise.busy} onClick={() => setRaiseForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={raise.busy} onClick={() => void saveRaise()} type="button">{t('fin.exc.raiseSave')}</Button>
                </>}
                onClose={() => setRaiseForm(null)}
                open={raiseForm !== null}
                title={t('fin.exc.raiseTitle')}
            >
                {raiseForm !== null && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <p className="text-sm text-muted-foreground sm:col-span-2">{t('fin.exc.raiseHint')}</p>
                        {raise.error !== null ? <div className="sm:col-span-2"><ErrorState {...errorCopy} error={raise.error} onRefresh={() => window.location.reload()} /></div> : null}
                        <FormField error={raise.fieldError('kind')} field="kind" label={t('fin.exc.colKind')}>
                            <Select onChange={(e) => setRaiseForm({ ...raiseForm, kind: e.target.value })} searchable={false} value={raiseForm.kind}>
                                {overview.kinds.map((k) => <option key={k} value={k}>{kindLabel(k)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={raise.fieldError('business_date')} field="business_date" hint={t('fin.exc.dateHint')} label={t('fin.exc.colDate')}>
                            <DatePicker max={overview.today} onChange={(e) => setRaiseForm({ ...raiseForm, date: e.target.value })} value={raiseForm.date} />
                        </FormField>
                        <FormField error={badAmount ? t('fin.exc.badAmount') : raise.fieldError('amount_minor')} field="amount_minor" label={t('fin.exc.amountField', { currency })}>
                            <MoneyInput onChange={(e) => { setBadAmount(false); setRaiseForm({ ...raiseForm, amount: e.target.value }); }} value={raiseForm.amount} />
                        </FormField>
                        <FormField error={raise.fieldError('method')} field="method" label={t('fin.col.method')}>
                            <Select onChange={(e) => setRaiseForm({ ...raiseForm, method: e.target.value })} searchable={false} value={raiseForm.method}>
                                <option value="">{t('fin.exc.methodNone')}</option>
                                {overview.methods.map((m) => <option key={m} value={m}>{methodLabel(m)}</option>)}
                            </Select>
                        </FormField>
                        <FormField error={raise.fieldError('reference')} field="reference" hint={t('fin.exc.referenceHint')} label={t('fin.exc.reference')}>
                            <Input maxLength={80} onChange={(e) => setRaiseForm({ ...raiseForm, reference: e.target.value })} value={raiseForm.reference} />
                        </FormField>
                        <FormField error={raise.fieldError('folio_ref')} field="folio_ref" hint={t('fin.exc.folioHint')} label={t('fin.exc.folio')}>
                            <Input maxLength={40} onChange={(e) => setRaiseForm({ ...raiseForm, folio: e.target.value })} value={raiseForm.folio} />
                        </FormField>
                        <div className="sm:col-span-2">
                            <FormField error={raise.fieldError('description')} field="description" label={t('fin.exc.descriptionField')}>
                                <Textarea maxLength={300} onChange={(e) => setRaiseForm({ ...raiseForm, description: e.target.value })} value={raiseForm.description} />
                            </FormField>
                        </div>
                    </div>
                )}
            </Dialog>

            <Dialog
                footer={<>
                    <Button disabled={reconcile.busy} onClick={() => setReconcileForm(null)} type="button" variant="outline">{t('ui.dialog.cancel')}</Button>
                    <Button loading={reconcile.busy} onClick={() => void saveReconcile()} type="button">{t('fin.exc.reconcileSave')}</Button>
                </>}
                onClose={() => setReconcileForm(null)}
                open={reconcileForm !== null}
                title={t('fin.exc.reconcileTitle', { number: reconcileForm?.exception.number ?? '' })}
            >
                {reconcileForm !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">{t('fin.exc.reconcileHint', { amount: format.money(reconcileForm.exception.amount_minor, currency), kind: kindLabel(reconcileForm.exception.kind) })}</p>
                        {reconcile.error !== null ? <ErrorState {...errorCopy} error={reconcile.error} onRefresh={() => window.location.reload()} /> : null}
                        <FormField error={reconcile.fieldError('status')} field="status" hint={t(`fin.exc.statusHint.${reconcileForm.status}` as MessageKey)} label={t('fin.exc.settleStatus')}>
                            <Select onChange={(e) => setReconcileForm({ ...reconcileForm, status: e.target.value })} searchable={false} value={reconcileForm.status}>
                                {overview.resolutions.map((s) => <option key={s} value={s}>{statusLabel(s)}</option>)}
                            </Select>
                        </FormField>
                        {adjusting ? (
                            <FormField error={reconcile.fieldError('correction_number')} field="correction_number" hint={t('fin.exc.correctionHint')} label={t('fin.exc.correctionNumber')} required>
                                <Input maxLength={20} onChange={(e) => setReconcileForm({ ...reconcileForm, correction: e.target.value })} value={reconcileForm.correction} />
                            </FormField>
                        ) : null}
                        <FormField error={reconcile.fieldError('resolution')} field="resolution" hint={reconcileForm.status === 'matched' ? t('fin.exc.matchedHint') : undefined} label={t('fin.exc.resolution')}>
                            <Textarea maxLength={300} onChange={(e) => setReconcileForm({ ...reconcileForm, resolution: e.target.value })} value={reconcileForm.resolution} />
                        </FormField>
                    </div>
                )}
            </Dialog>
        </FinanceShell>
    );
}
